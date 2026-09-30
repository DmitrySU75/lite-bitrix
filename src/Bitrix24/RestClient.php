<?php

declare(strict_types=1);

namespace LitePmsB24\Bitrix24;

use RuntimeException;

/**
 * Тонкий REST-клиент поверх входящего вебхука Bitrix24 (ТЗ разд. 8.1).
 * Используемые методы: crm.lead.add / update / get / list / fields.
 */
final class RestClient
{
    public function __construct(
        private readonly string $webhookUrl,
        private readonly int $timeout = 30
    ) {
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function call(string $method, array $params = []): array
    {
        $url = rtrim($this->webhookUrl, '/') . '/' . $method . '.json';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Bitrix24 request failed: ' . $error);
        }
        curl_close($ch);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Bitrix24 returned a non-JSON response');
        }
        if (isset($data['error']) && $data['error']) {
            $description = (string) ($data['error_description'] ?? $data['error']);
            throw new RuntimeException('Bitrix24 error [' . $data['error'] . ']: ' . $description);
        }
        return $data;
    }

    /** @param array<string,mixed> $fields */
    public function leadAdd(array $fields): array
    {
        return $this->call('crm.lead.add', ['fields' => $fields]);
    }

    /** @param array<string,mixed> $fields */
    public function leadUpdate(int $id, array $fields): array
    {
        return $this->call('crm.lead.update', ['id' => $id, 'fields' => $fields]);
    }

    /** @return array<string,mixed> */
    public function leadGet(int $id): array
    {
        return $this->call('crm.lead.get', ['id' => $id]);
    }

    /**
     * Поиск лида по значению EXT_ID (ТЗ разд. 6, 9.3).
     *
     * @return list<int>
     */
    public function leadIdsByExtId(string $extId, string $ufCode): array
    {
        $response = $this->call('crm.lead.list', [
            'filter' => [$ufCode => $extId],
            'select' => ['ID'],
        ]);
        $ids = [];
        foreach (($response['result'] ?? []) as $item) {
            $ids[] = (int) ($item['ID'] ?? 0);
        }
        return $ids;
    }
}
