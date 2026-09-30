<?php

declare(strict_types=1);

namespace LitePmsB24\Service;

/**
 * Файловый логгер с маскированием PII (телефоны/email) — ТЗ разд. 10.2.
 */
final class LogService
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(
        private readonly string $dir,
        private readonly bool $maskPii,
        private readonly string $level = 'info'
    ) {
    }

    /** @param array<string,mixed> $ctx */
    public function info(string $eventKey, string $action, array $ctx = []): void
    {
        $this->write('info', $eventKey, $action, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public function warning(string $eventKey, string $action, array $ctx = []): void
    {
        $this->write('warning', $eventKey, $action, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    public function error(string $eventKey, string $action, array $ctx = []): void
    {
        $this->write('error', $eventKey, $action, $ctx);
    }

    /** @param array<string,mixed> $ctx */
    private function write(string $level, string $eventKey, string $action, array $ctx): void
    {
        if (!$this->levelAllowed($level)) {
            return;
        }
        if ($this->maskPii) {
            $ctx = $this->mask($ctx);
        }

        $line = json_encode([
            'ts'        => date('c'),
            'level'     => $level,
            'event_key' => $eventKey,
            'action'    => $action,
            'context'   => $ctx,
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        @file_put_contents($this->dir . '/adapter-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Маскирование телефонных номеров и email (ТЗ разд. 10.2).
     *
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>
     */
    private function mask(array $ctx): array
    {
        foreach ($ctx as $key => $value) {
            if (is_array($value)) {
                $ctx[$key] = $this->mask($value);
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            $lowerKey = strtolower((string) $key);
            if (str_contains($lowerKey, 'phone')) {
                $ctx[$key] = strlen($value) > 6
                    ? substr($value, 0, 3) . '***' . substr($value, -2)
                    : '***';
            } elseif (str_contains($lowerKey, 'email')) {
                $at = strpos($value, '@');
                $ctx[$key] = $at === false
                    ? '***'
                    : substr($value, 0, 2) . '***@' . substr($value, $at + 1, 2) . '***';
            }
        }
        return $ctx;
    }

    private function levelAllowed(string $level): bool
    {
        return (self::LEVELS[$level] ?? 0) >= (self::LEVELS[$this->level] ?? 1);
    }
}
