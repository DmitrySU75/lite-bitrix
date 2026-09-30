<?php

declare(strict_types=1);

/**
 * Точка входа адаптера. Маршруты:
 *   POST /webhook  — приём исходящих уведомлений LitePMS (ТЗ разд. 8.3.1)
 *   GET  /health   — healthcheck (ТЗ разд. 12)
 *
 * Webhook-адрес в настройках LitePMS должен указывать на <host>/webhook.
 */

require __DIR__ . '/../vendor/autoload.php';

use LitePmsB24\Config;
use LitePmsB24\Http\Request;
use LitePmsB24\Http\Response;
use LitePmsB24\LitePMS\WebhookParser;
use LitePmsB24\Storage\SqliteStorage;

$base   = dirname(__DIR__);
$config = Config::load($base);
$request = Request::fromGlobals();

switch ($request->path) {
    case '/health':
        (new Response(200, json_encode(['status' => 'ok', 'time' => date('c')])))->send();
        break;

    case '/webhook':
        handleWebhook($config, $request);
        break;

    default:
        (new Response(404, json_encode(['error' => 'not_found'])))->send();
        break;
}

/**
 * Приём уведомления LitePMS. Сразу возвращает 200 (иначе LitePMS может блокировать
 * доставку — ТЗ разд. 8.3.1), а реальная работа с CRM выполняется в очереди (воркер).
 */
function handleWebhook(Config $config, Request $request): void
{
    if ($request->method !== 'POST') {
        Response::json(405, ['error' => 'method not allowed'])->send();
        return;
    }

    // Проверка источника (ТЗ разд. 8.3.1, 11): LitePMS не подписывает вебхуки,
    // поэтому проверяем фиксированный секретный заголовок.
    $secret = (string) $config->get('app.endpoint_secret', '');
    $header = (string) $config->get('app.endpoint_secret_header', 'X-LitePMS-Adapter-Key');
    if ($secret !== '' && $request->header($header) !== $secret) {
        Response::json(401, ['error' => 'unauthorized'])->send();
        return;
    }

    $raw = (string) ($request->body ?? '');
    try {
        $event = (new WebhookParser())->parse($request->jsonBody(), $raw);
    } catch (\InvalidArgumentException $e) {
        Response::json(422, ['error' => $e->getMessage()])->send();
        return;
    }

    // Ставим в очередь и сразу подтверждаем приём.
    $storage = new SqliteStorage((string) $config->get('storage.dsn'));
    $storage->migrate();
    $storage->enqueue($event);

    Response::json(200, ['status' => 'accepted'])->send();
}
