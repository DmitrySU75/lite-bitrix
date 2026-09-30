<?php

declare(strict_types=1);

namespace LitePmsB24\Http;

/**
 * Минимальная обёртка входящего HTTP-запроса.
 */
final class Request
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly ?string $body
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        $rawBody = file_get_contents('php://input');

        return new self(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
            $headers,
            $rawBody === false ? null : $rawBody
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function jsonBody(): ?array
    {
        if ($this->body === null || $this->body === '') {
            return null;
        }
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : null;
    }
}
