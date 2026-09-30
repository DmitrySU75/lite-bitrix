<?php

declare(strict_types=1);

namespace LitePmsB24\Service;

use LitePmsB24\Bitrix24\LeadMapper;
use LitePmsB24\Bitrix24\RestClient;
use LitePmsB24\Config;
use LitePmsB24\LitePMS\WebhookParser;
use LitePmsB24\Storage\StorageInterface;
use RuntimeException;

/**
 * Оркестратор направления A: LitePMS (событие) -> лид Bitrix24.
 *
 * Учитывает: идемпотентность (9.1), связку бронь->лид по EXT_ID (6), дедупликацию
 * и отсутствие создания лида при удалении, если лида ещё нет (9.3), логирование (10.2),
 * атрибуцию для сквозной аналитики (14.3).
 */
final class SyncService
{
    public function __construct(
        private readonly Config $config,
        private readonly StorageInterface $storage,
        private readonly RestClient $b24,
        private readonly LeadMapper $mapper,
        private readonly WebhookParser $parser,
        private readonly LogService $log
    ) {
    }

    /**
     * Обрабатывает одно событие. Возвращает строку-результат
     * (created / updated / payment_recorded / duplicate / request_logged / skipped ...).
     *
     * @param array<string,mixed> $event
     */
    public function handle(array $event): string
    {
        $key = (string) ($event['_key'] ?? '');
        $type = (string) ($event['_type'] ?? '');

        // Идемпотентность (ТЗ 9.1) — повторную доставку обрабатываем один раз.
        if ($key !== '' && $this->storage->hasProcessed($key)) {
            return 'duplicate';
        }

        if ($this->parser->isRequestEvent($type)) {
            // Предварительная заявка (booking_module_request): только name/phone/comment, без booking_id.
            // TODO: при необходимости создавать лид из заявки (см. раздел сценариев ТЗ).
            $this->log->info($key, 'request_event', [
                'name'    => $event['name'] ?? '',
                'phone'   => $event['phone'] ?? '',
            ]);
            if ($key !== '') {
                $this->storage->markProcessed($key, 'request_logged');
            }
            return 'request_logged';
        }

        if ($this->parser->isPaymentEvent($type)) {
            return $this->handlePayment($event);
        }

        if ($this->parser->isBookingEvent($type)) {
            return $this->handleBooking($event);
        }

        $this->log->warning($key, 'unknown_event_type', ['type' => $type]);
        return 'unknown_type';
    }

    /**
     * @param array<string,mixed> $event
     */
    private function handleBooking(array $event): string
    {
        $key = (string) $event['_key'];
        $bookingId = (string) $event['_booking_id'];
        $type = (string) $event['_type'];

        $leadId = $this->storage->findLeadIdByBookingId($bookingId);
        $isCreate = $leadId === null;

        // Страховка: если локальный кэш связки пуст, ищем лид по EXT_ID в CRM (ТЗ 6, 9.3).
        if ($isCreate) {
            $found = $this->findLeadByExtId($bookingId);
            if ($found !== null) {
                $leadId = $found;
                $this->storage->saveLink($bookingId, $leadId);
                $isCreate = false;
            }
        }

        // Удаление брони: не создаём лид, если его ещё нет (ТЗ 9.3).
        if ($isCreate && $type === 'delete_booking') {
            $this->log->info($key, 'booking_deleted_no_lead', ['booking_id' => $bookingId]);
            $this->storage->markProcessed($key, 'skipped');
            return 'skipped';
        }

        $fields = $this->mapper->forCreate($event);

        if ($isCreate) {
            $response = $this->b24->leadAdd($fields);
            $newLeadId = (int) ($response['result'] ?? 0);
            if ($newLeadId <= 0) {
                throw new RuntimeException('crm.lead.add did not return a lead id');
            }
            $this->storage->saveLink($bookingId, $newLeadId);
            $this->log->info($key, 'lead_created', [
                'booking_id' => $bookingId,
                'lead_id'    => $newLeadId,
            ]);
            $this->storage->markProcessed($key, 'created');
            return 'created';
        }

        // Обновление существующего лида. EXT_ID и маркер происхождения не перезаписываем.
        $updateFields = array_diff_key($fields, [
            (string) $this->config->get('mapping.uf_ext_id')   => true,
            (string) $this->config->get('mapping.uf_sync_source') => true,
        ]);
        $this->b24->leadUpdate($leadId, $updateFields);
        $this->log->info($key, 'lead_updated', [
            'booking_id' => $bookingId,
            'lead_id'    => $leadId,
        ]);
        $this->storage->markProcessed($key, 'updated');
        return 'updated';
    }

    /**
     * Оплата (booking_module_payment) — фиксируем отметку и сумму для сквозной
     * аналитики (ТЗ 10.3, 13.1 п. 7, 14.3).
     *
     * @param array<string,mixed> $event
     */
    private function handlePayment(array $event): string
    {
        $key = (string) $event['_key'];
        $bookingId = (string) ($event['booking_id'] ?? '');

        $leadId = $this->storage->findLeadIdByBookingId($bookingId);
        if ($leadId === null) {
            $leadId = $this->findLeadByExtId($bookingId);
        }
        if ($leadId === null) {
            $this->log->warning($key, 'payment_no_lead', ['booking_id' => $bookingId]);
            $this->storage->markProcessed($key, 'no_lead');
            return 'no_lead';
        }

        $map = $this->config->get('mapping', []);
        $fields = [
            $map['uf_payment_status'] => 'paid',
            $map['uf_payment_date']   => date('Y-m-d H:i:s', (int) ($event['date'] ?? time())),
        ];
        if (isset($event['price']) && $event['price'] !== '') {
            $fields[$map['uf_payment_amount']] = (float) $event['price'];
        }

        $this->b24->leadUpdate($leadId, $fields);
        $this->log->info($key, 'payment_recorded', [
            'booking_id' => $bookingId,
            'lead_id'    => $leadId,
            'price'      => $event['price'] ?? null,
        ]);
        $this->storage->markProcessed($key, 'payment_recorded');
        return 'payment_recorded';
    }

    private function findLeadByExtId(string $bookingId): ?int
    {
        $ufCode = (string) $this->config->get('mapping.uf_ext_id');
        $ids = $this->b24->leadIdsByExtId($bookingId, $ufCode);
        $ids = array_values(array_filter($ids, static fn (int $id) => $id > 0));
        return $ids[0] ?? null;
    }
}
