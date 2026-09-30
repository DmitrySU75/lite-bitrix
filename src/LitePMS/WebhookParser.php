<?php

declare(strict_types=1);

namespace LitePmsB24\LitePMS;

use InvalidArgumentException;

/**
 * Разбор входящего webhook-уведомления LitePMS (ТЗ разд. 8.3, формат «бронь»,
 * «оплата», «предзаявка»).
 *
 * Возвращает нормализованное событие (исходный payload + служебные ключи `_key`,
 * `_type`, `_booking_id`, `_received_at`).
 */
final class WebhookParser
{
    /** События по брони (содержат booking_id и полные данные). */
    private const BOOKING_EVENTS = [
        'new_booking',
        'edit_booking',
        'delete_booking',
        'booking_module',
        'channel_manager_booking',
    ];

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    public function parse(?array $payload, string $rawBody): array
    {
        if ($payload === null) {
            throw new InvalidArgumentException('Empty or invalid JSON payload');
        }

        $type = (string) ($payload['type'] ?? '');
        if ($type === '') {
            throw new InvalidArgumentException('Missing event type');
        }

        $bookingId = (string) ($payload['booking_id'] ?? '');
        // Предварительная заявка не содержит booking_id (только name/phone/comment).
        if ($bookingId === '' && !$this->isRequestEvent($type)) {
            throw new InvalidArgumentException('Missing booking_id for event type ' . $type);
        }

        return array_merge($payload, [
            '_type'        => $type,
            '_booking_id'  => $bookingId,
            // Ключ идемпотентности: хэш исходного тела — повторная доставка того же тела
            // обработается один раз (ТЗ разд. 9.1).
            '_key'         => sha1($rawBody),
            '_received_at' => time(),
        ]);
    }

    public function isBookingEvent(string $type): bool
    {
        return in_array($type, self::BOOKING_EVENTS, true);
    }

    public function isPaymentEvent(string $type): bool
    {
        return $type === 'booking_module_payment';
    }

    public function isRequestEvent(string $type): bool
    {
        return $type === 'booking_module_request';
    }
}
