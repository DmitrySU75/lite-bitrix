<?php

declare(strict_types=1);

namespace LitePmsB24\Storage;

use PDO;

final class SqliteStorage implements StorageInterface
{
    private PDO $pdo;

    public function __construct(string $dsn)
    {
        $this->pdo = new PDO($dsn);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
    }

    public function migrate(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS queue (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                event_key   TEXT NOT NULL UNIQUE,
                payload     TEXT NOT NULL,
                status      TEXT NOT NULL DEFAULT 'pending',   -- pending|processing|done|failed|error
                attempts    INTEGER NOT NULL DEFAULT 0,
                next_run_at INTEGER NOT NULL DEFAULT 0,
                last_error  TEXT,
                created_at  INTEGER NOT NULL,
                updated_at  INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS idempotency (
                event_key    TEXT PRIMARY KEY,
                processed_at INTEGER NOT NULL,
                result       TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS links (
                booking_id TEXT PRIMARY KEY,
                lead_id    INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS sync_log (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                event_key  TEXT,
                level      TEXT NOT NULL,
                action     TEXT NOT NULL,
                context    TEXT,
                created_at INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_queue_status  ON queue(status, next_run_at);
            CREATE INDEX IF NOT EXISTS idx_log_created   ON sync_log(created_at);
            SQL
        );
    }

    /** @param array<string,mixed> $payload */
    public function enqueue(array $payload): int
    {
        $key = (string) ($payload['_key'] ?? ('manual-' . time()));
        $now = time();
        $stmt = $this->pdo->prepare(
            'INSERT INTO queue (event_key, payload, created_at, updated_at)
             VALUES (:k, :p, :now, :now)
             ON CONFLICT(event_key) DO NOTHING'
        );
        $stmt->execute([
            ':k'   => $key,
            ':p'   => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':now' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        // Дубль по event_key уже есть — вернём его id (идемпотентность, ТЗ 9.1).
        $found = $this->pdo->prepare('SELECT id FROM queue WHERE event_key = ?');
        $found->execute([$key]);
        $row = $found->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['id'] : 0;
    }

    /** @return list<array<string,mixed>> */
    public function claimNext(int $batchSize): array
    {
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                "SELECT * FROM queue
                 WHERE status IN ('pending', 'failed') AND next_run_at <= ?
                 ORDER BY next_run_at ASC LIMIT ?"
            );
            $select->execute([time(), $batchSize]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC);

            if ($rows) {
                $update = $this->pdo->prepare(
                    "UPDATE queue SET status = 'processing', updated_at = ? WHERE id = ?"
                );
                foreach ($rows as $row) {
                    $update->execute([time(), (int) $row['id']]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return $rows;
    }

    public function markDone(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE queue SET status = 'done', updated_at = ? WHERE id = ?"
        );
        $stmt->execute([time(), $jobId]);
    }

    public function markFailed(int $jobId, string $error, int $attempts, int $maxAttempts, int $backoffBaseMs): void
    {
        $terminal = $attempts >= $maxAttempts;
        // Экспоненциальная задержка: base * 2^(attempts-1) миллисекунд.
        $delayMs = $backoffBaseMs << max(0, $attempts - 1);
        // Терминальный сбой ('error') повторно не обрабатывается; 'failed' — с задержкой.
        $nextRunAt = $terminal ? 0 : time() + intdiv($delayMs, 1000) + 1;
        $status = $terminal ? 'error' : 'failed';
        $stmt = $this->pdo->prepare(
            'UPDATE queue
             SET status = :s, attempts = :a, last_error = :e, next_run_at = :n, updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            ':s'   => $status,
            ':a'   => $attempts,
            ':e'   => substr($error, 0, 2000),
            ':n'   => $nextRunAt,
            ':now' => time(),
            ':id'  => $jobId,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function staleJobs(int $staleAfterSec): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM queue WHERE status = 'processing' AND updated_at <= ?"
        );
        $stmt->execute([time() - $staleAfterSec]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function requeue(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE queue SET status = 'pending', next_run_at = ?, updated_at = ? WHERE id = ?"
        );
        $stmt->execute([time(), time(), $jobId]);
    }

    public function hasProcessed(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM idempotency WHERE event_key = ?');
        $stmt->execute([$key]);
        return (bool) $stmt->fetchColumn();
    }

    public function markProcessed(string $key, string $result): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO idempotency (event_key, processed_at, result) VALUES (?, ?, ?)'
        );
        $stmt->execute([$key, time(), $result]);
    }

    public function findLeadIdByBookingId(string $bookingId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT lead_id FROM links WHERE booking_id = ?');
        $stmt->execute([$bookingId]);
        $leadId = $stmt->fetchColumn();
        return $leadId === false ? null : (int) $leadId;
    }

    public function saveLink(string $bookingId, int $leadId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO links (booking_id, lead_id, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(booking_id) DO UPDATE SET lead_id = excluded.lead_id, updated_at = excluded.updated_at'
        );
        $stmt->execute([$bookingId, $leadId, time()]);
    }

    /** @return list<array<string,mixed>> */
    public function listLog(int $from, int $to): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sync_log WHERE created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 10000'
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
