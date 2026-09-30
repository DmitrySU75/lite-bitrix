<?php

declare(strict_types=1);

namespace LitePmsB24\Service;

use LitePmsB24\Storage\StorageInterface;

/**
 * Отчёт по синхронизации за период (ТЗ разд. 10.2).
 *
 * Каркас: выгружает журнал событий за период в CSV с UTF-8 BOM (открывается в Excel).
 * Для генерации нативного XLS/XLSX подключите PhpSpreadsheet — заготовка exportXlsx() ниже.
 */
final class XlsReporter
{
    public function __construct(
        private readonly StorageInterface $storage,
        private readonly string $dir
    ) {
    }

    public function exportCsv(int $from, int $to, string $filename = ''): string
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $filename = $filename !== '' ? $filename : 'report-' . date('Ymd-His') . '.csv';
        $path = rtrim($this->dir, '/') . '/' . $filename;

        $fp = fopen($path, 'w');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM для корректного открытия в Excel
        fputcsv($fp, ['Timestamp', 'Level', 'Event key', 'Action', 'Context']);
        foreach ($this->storage->listLog($from, $to) as $row) {
            fputcsv($fp, [
                date('Y-m-d H:i:s', (int) $row['created_at']),
                $row['level'],
                $row['event_key'],
                $row['action'],
                $row['context'],
            ]);
        }
        fclose($fp);
        return $path;
    }

    public function exportXlsx(int $from, int $to): string
    {
        throw new \LogicException(
            'Not implemented: подключите PhpSpreadsheet (phpoffice/phpspreadsheet) для генерации XLSX.'
        );
    }
}
