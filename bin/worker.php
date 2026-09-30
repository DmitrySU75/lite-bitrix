<?php

declare(strict_types=1);

/**
 * Фоновый воркер очереди. Обрабатывает события LitePMS -> лиды Bitrix24 вне
 * HTTP-запроса (webhook возвращает 200 мгновенно, ТЗ разд. 8.3.1).
 *
 * Запуск:
 *   - cron каждую минуту:   * * * * * php /path/to/bin/worker.php >> /var/log/litepms-worker.log 2>&1
 *   - либо под supervisor (постоянный процесс) с циклом сна.
 *
 * SLA синхронизации <= 5 минут (ТЗ разд. 13.2) — запуск раз в минуту это обеспечивает.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use LitePmsB24\Bitrix24\LeadMapper;
use LitePmsB24\Bitrix24\RestClient;
use LitePmsB24\Config;
use LitePmsB24\LitePMS\WebhookParser;
use LitePmsB24\Service\LogService;
use LitePmsB24\Service\SyncService;
use LitePmsB24\Storage\SqliteStorage;

$base   = dirname(__DIR__);
$config = Config::load($base);

$storage = new SqliteStorage((string) $config->get('storage.dsn'));
$storage->migrate();

$log = new LogService(
    (string) $config->get('logging.dir'),
    (bool) $config->get('logging.mask_pii', true),
    (string) $config->get('logging.level', 'info')
);

$b24    = new RestClient((string) $config->get('bitrix24.webhook_url'), (int) $config->get('bitrix24.timeout', 30));
$parser = new WebhookParser();
$mapper = new LeadMapper($config);
$sync   = new SyncService($config, $storage, $b24, $mapper, $parser, $log);

$batchSize = (int) $config->get('queue.batch_size', 50);
$maxAttempts = (int) $config->get('queue.max_attempts', 5);
$backoffMs   = (int) $config->get('queue.backoff_base_ms', 1000);
$staleAfter  = (int) $config->get('queue.stale_after_sec', 300);

// Возвращаем в очередь задачи, зависшие в статусе 'processing' (восстановление после сбоя).
foreach ($storage->staleJobs($staleAfter) as $job) {
    $storage->requeue((int) $job['id']);
}

$jobs = $storage->claimNext($batchSize);
foreach ($jobs as $job) {
    $jobId   = (int) $job['id'];
    $payload = json_decode((string) $job['payload'], true);
    $event   = is_array($payload) ? $payload : [];
    $eventKey = (string) ($event['_key'] ?? $job['event_key'] ?? ('job-' . $jobId));

    try {
        $result = $sync->handle($event);
        $log->info($eventKey, 'job_done', ['job_id' => $jobId, 'result' => $result]);
        $storage->markDone($jobId);
    } catch (\Throwable $e) {
        $attempts = (int) $job['attempts'] + 1;
        $log->error($eventKey, 'job_failed', [
            'job_id'   => $jobId,
            'attempts' => $attempts,
            'error'    => $e->getMessage(),
        ]);
        $storage->markFailed($jobId, $e->getMessage(), $attempts, $maxAttempts, $backoffMs);
    }
}
