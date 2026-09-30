<?php

declare(strict_types=1);

/**
 * Генерация отчёта по синхронизации за период (ТЗ разд. 10.2).
 *
 * Использование:
 *   php bin/report.php [from=YYYY-MM-DD] [to=YYYY-MM-DD]
 *
 * Без аргументов — отчёт за последние 24 часа. По умолчанию CSV; для XLSX — секция
 * в XlsReporter::exportXlsx().
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use LitePmsB24\Config;
use LitePmsB24\Service\XlsReporter;
use LitePmsB24\Storage\SqliteStorage;

$base   = dirname(__DIR__);
$config = Config::load($base);

$storage = new SqliteStorage((string) $config->get('storage.dsn'));
$storage->migrate();

$to = isset($argv[2]) ? strtotime($argv[2] . ' 23:59:59') : time();
$from = isset($argv[1]) ? strtotime($argv[1] . ' 00:00:00') : $to - 86400;
if ($from === false || $to === false) {
    fwrite(STDERR, "Неверный формат даты. Использование: php bin/report.php [YYYY-MM-DD] [YYYY-MM-DD]\n");
    exit(1);
}

$reporter = new XlsReporter($storage, (string) $config->get('reporting.dir'));
$path = $reporter->exportCsv($from, $to);
fwrite(STDOUT, "Отчёт сохранён: " . $path . "\n");
