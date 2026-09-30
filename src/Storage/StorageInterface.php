<?php

declare(strict_types=1);

namespace LitePmsB24\Storage;

interface StorageInterface
{
    public function migrate(): void;

    /** @param array<string,mixed> $payload */
    public function enqueue(array $payload): int;

    /** @return list<array<string,mixed>> */
    public function claimNext(int $batchSize): array;

    public function markDone(int $jobId): void;

    public function markFailed(int $jobId, string $error, int $attempts, int $maxAttempts, int $backoffBaseMs): void;

    /** @return list<array<string,mixed>> Задачи, зависшие в статусе 'processing'. */
    public function staleJobs(int $staleAfterSec): array;

    public function requeue(int $jobId): void;

    public function hasProcessed(string $key): bool;

    public function markProcessed(string $key, string $result): void;

    public function findLeadIdByBookingId(string $bookingId): ?int;

    public function saveLink(string $bookingId, int $leadId): void;

    /** @return list<array<string,mixed>> */
    public function listLog(int $from, int $to): array;
}
