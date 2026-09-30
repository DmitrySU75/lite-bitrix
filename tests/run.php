<?php

declare(strict_types=1);

/**
 * Простой смоук-тест без внешних вызовов и без БД: парсер вебхука и маппер.
 *
 * Запуск: composer test  (или php tests/run.php после composer install)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use LitePmsB24\Bitrix24\LeadMapper;
use LitePmsB24\Config;
use LitePmsB24\LitePMS\WebhookParser;

$failures = 0;

function check(bool $condition, string $label): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
    } else {
        fwrite(STDOUT, 'ok:   ' . $label . PHP_EOL);
    }
}

$base = dirname(__DIR__);
$config = Config::load($base);
$parser = new WebhookParser();

// --- Парсер: бронь ---
$payload = [
    'type'           => 'new_booking',
    'booking_id'     => 123,
    'client_name'    => 'Иван',
    'client_surname' => 'Петров',
    'client_phone'   => '+79161112233',
    'client_email'   => 'ivan@example.com',
    'date_in'        => '2026-10-01',
    'date_out'       => '2026-10-03',
    'person'         => 2,
    'price'          => 10000,
    'status_id'      => 2,
    'service_id'     => 1,
    'ym_clid'        => '123456',
];
$raw = json_encode($payload, JSON_UNESCAPED_UNICODE);
$event = $parser->parse($payload, $raw);
check($event['_type'] === 'new_booking', 'parser: тип события извлечён');
check($event['_booking_id'] === '123', 'parser: booking_id извлечён');
check($event['_key'] === sha1($raw), 'parser: ключ идемпотентности = хэш тела');
check($parser->isBookingEvent('edit_booking'), 'parser: edit_booking распознан как событие брони');
check($parser->isPaymentEvent('booking_module_payment'), 'parser: payment распознан');
check($parser->isRequestEvent('booking_module_request'), 'parser: request распознан');
check((new WebhookParser())->isBookingEvent('booking_module_request') === false, 'parser: request не бронь');

// --- Парсер: невалидный payload должен бросить исключение ---
$threw = false;
try {
    $parser->parse(null, '{}');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check($threw, 'parser: пустой payload отклонён');

// --- Маппер ---
$mapper = new LeadMapper($config);
$fields = $mapper->forCreate($event);
check(($fields['UF_CRM_EXT_ID'] ?? null) === '123', 'mapper: EXT_ID = booking_id');
check(($fields['NAME'] ?? null) === 'Иван', 'mapper: NAME из client_name');
check(($fields['LAST_NAME'] ?? null) === 'Петров', 'mapper: LAST_NAME из client_surname');
check(isset($fields['UF_CRM_YM_CLID']) && $fields['UF_CRM_YM_CLID'] === '123456', 'mapper: атрибуция ym_clid');
check(isset($fields['UF_CRM_AMOUNT']) && (float) $fields['UF_CRM_AMOUNT'] === 10000.0, 'mapper: сумма');
check(isset($fields['UF_CRM_GUESTS']) && $fields['UF_CRM_GUESTS'] === 2, 'mapper: число гостей');
check(isset($fields['PHONE']) && $fields['PHONE'][0]['VALUE'] === '+79161112233', 'mapper: телефон');
check(isset($fields['UF_CRM_SYNC_SOURCE']), 'mapper: маркер происхождения (антицикл)');

// --- Маппер: события без значения не должны попадать в поля (не затираем CRM) ---
$minimal = $parser->parse(['type' => 'edit_booking', 'booking_id' => 999], '{"type":"edit_booking","booking_id":999}');
$minFields = $mapper->forCreate($minimal);
check(!isset($minFields['NAME']), 'mapper: пустые значения отфильтрованы');
check(!isset($minFields['SOURCE_ID']), 'mapper: неизвестный источник не подставляется');

if ($failures > 0) {
    fwrite(STDERR, $failures . ' проверка(и) не пройдена(ы)' . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Все проверки пройдены.' . PHP_EOL);
