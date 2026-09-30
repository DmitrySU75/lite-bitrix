<?php

declare(strict_types=1);

namespace LitePmsB24\Http;

/**
 * Минимальная обёртка HTTP-ответа. Вызывается только из веб-среды.
 */
final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = ['Content-Type' => 'application/json']
    ) {
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }

    /** @param array<string,mixed> $data */
    public static function json(int $status, array $data): self
    {
        return new self($status, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
