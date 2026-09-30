<?php

declare(strict_types=1);

namespace LitePmsB24\Bitrix24;

use LitePmsB24\Config;

/**
 * Трансляция нормализованного события брони LitePMS в поля лида Bitrix24
 * согласно маппингу (ТЗ разд. 5, 7, 10.3, 14.3).
 */
final class LeadMapper
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Поля для crm.lead.add при создании нового лида из брони.
     *
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    public function forCreate(array $event): array
    {
        $map = $this->config->get('mapping', []);

        $guestName  = $event['client_surname'] ?? '';
        $guestSName = $event['client_name'] ?? '';

        $fields = [
            'TITLE'    => $this->title(
                $event,
                (string) $this->config->get('lead.title_template', 'Бронь')
            ),
            'LAST_NAME' => $guestName,
            'NAME'      => $guestSName,
            'COMMENTS'  => $this->comments($event),
            $map['uf_ext_id'] => $event['_booking_id'],
            $map['uf_sync_source'] => (string) $this->config->get('app.adapter_marker'),
        ];

        if (!empty($event['client_phone'])) {
            $fields['PHONE'] = [['VALUE' => $event['client_phone'], 'VALUE_TYPE' => 'WORK']];
        }
        if (!empty($event['client_email'])) {
            $fields['EMAIL'] = [['VALUE' => $event['client_email'], 'VALUE_TYPE' => 'WORK']];
        }

        $fields = array_merge($fields, $this->bookingFields($event));

        // Убираем пустые значения, чтобы не затирать данные в CRM (ТЗ разд. 7).
        return array_filter($fields, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Блок фактических данных брони (общий для создания и обновления).
     *
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function bookingFields(array $event): array
    {
        $map = $this->config->get('mapping', []);
        $fields = [];

        if (!empty($event['date_in']) || !empty($event['time_in'])) {
            $fields[$map['uf_arrival']] = $this->dateTimeString(
                (string) ($event['date_in'] ?? ''),
                (string) ($event['time_in'] ?? '')
            );
        }
        if (!empty($event['date_out']) || !empty($event['time_out'])) {
            $fields[$map['uf_departure']] = $this->dateTimeString(
                (string) ($event['date_out'] ?? ''),
                (string) ($event['time_out'] ?? '')
            );
        }

        $guests = (int) ($event['person'] ?? 0)
            + (int) ($event['person_add'] ?? 0)
            + (int) ($event['children'] ?? 0);
        if ($guests > 0) {
            $fields[$map['uf_guests']] = $guests;
        }

        if (!empty($event['room_name'])) {
            $fields[$map['uf_room']] = $event['room_name'];
        }

        if (isset($event['price']) && $event['price'] !== '') {
            $fields['CURRENCY_ID']   = 'RUB';
            $fields[$map['uf_amount']] = (float) $event['price'];
        }

        $sourceId = $this->sourceId($event['service_id'] ?? null);
        if ($sourceId !== null) {
            $fields['SOURCE_ID'] = $sourceId;
        }

        // Атрибуция для сквозной аналитики (ТЗ разд. 14.3).
        foreach (['uf_ym_clid' => 'ym_clid', 'uf_etext' => 'etext', 'uf_yclid' => 'yclid'] as $uf => $src) {
            if (!empty($event[$src])) {
                $fields[$map[$uf]] = $event[$src];
            }
        }

        $stageId = $this->stageId($event['status_id'] ?? null);
        if ($stageId !== null) {
            $fields['STAGE_ID'] = $stageId;
        }

        return $fields;
    }

    /**
     * Заголовок лида по шаблону (ТЗ разд. 5).
     *
     * @param array<string,mixed> $event
     */
    private function title(array $event, string $template): string
    {
        return strtr($template, [
            '{client_surname}' => $event['client_surname'] ?? '',
            '{client_name}'    => $event['client_name'] ?? '',
            '{date_in}'        => $event['date_in'] ?? '',
            '{date_out}'       => $event['date_out'] ?? '',
        ]);
    }

    /**
     * @param array<string,mixed> $event
     */
    private function comments(array $event): string
    {
        $parts = array_filter([
            $event['client_booking_comment'] ?? '',
            $event['comment'] ?? '',
        ]);
        return implode(PHP_EOL, $parts);
    }

    private function dateTimeString(string $date, string $time): string
    {
        $value = $date;
        if ($time !== '') {
            $value = ($value !== '' ? $value . ' ' : '') . $time;
        }
        return $value;
    }

    private function sourceId($serviceId): ?string
    {
        if ($serviceId === null || $serviceId === '') {
            return null;
        }
        $sourceMap = $this->config->get('source_map', []);
        $key = (string) $serviceId;
        return isset($sourceMap[$key]) ? (string) $sourceMap[$key] : null;
    }

    private function stageId($statusId): ?string
    {
        if ($statusId === null || $statusId === '') {
            return null;
        }
        $statusMap = $this->config->get('status_map', []);
        $key = (string) $statusId;
        return isset($statusMap[$key]) ? (string) $statusMap[$key] : null;
    }
}
