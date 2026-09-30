<?php

declare(strict_types=1);

/**
 * ===== Конфигурация адаптера LitePMS -> Bitrix24 =====
 *
 * Скопируйте этот файл в config/config.local.php и заполните реальные значения.
 * config.local.php НЕ должен попадать в репозиторий (см. .gitignore).
 *
 * Ссылки на разделы ТЗ:
 *   - безопасность секретов            -> разд. 11
 *   - каналы и webhook-уведомления     -> разд. 8.3
 *   - очередь, ретраи, логи            -> разд. 10
 *   - развёртывание / health           -> разд. 12
 *   - маппинг и UF лида                -> разд. 5, 16 (заполняется по факту)
 *   - сквозная аналитика               -> разд. 14.3
 */
return [

    // ---- Секреты (никогда не в коде, не в логах и не в репозитории) ----
    'app' => [
        // LitePMS не подписывает вебхуки (HMAC нет, ТЗ 8.3.1). Поэтому endpoint
        // проверяет источник по фиксированному секретному заголовку (ТЗ разд. 11).
        'endpoint_secret_header' => 'X-LitePMS-Adapter-Key',
        'endpoint_secret'        => env('LITEPMS_ENDPOINT_SECRET', 'change-me'),
        // Уникальный маркер происхождения записи для защиты от зацикливания (ТЗ 9.2).
        'adapter_marker'         => 'litepms-adapter',
    ],

    // ---- Bitrix24 (входящий REST-вебхук портала, ТЗ разд. 8.1) ----
    'bitrix24' => [
        // Вид: https://<портал>/rest/<user_id>/<токен>/  (без имени метода).
        'webhook_url' => env('B24_WEBHOOK_URL', 'https://portal.bitrix24.ru/rest/1/xxxxxxxxxxxx/'),
        'timeout'     => (int) env('B24_TIMEOUT', 30),
    ],

    // ---- Хранилище состояния и очереди (ТЗ разд. 12) ----
    'storage' => [
        'dsn' => env('ADAPTER_DB_DSN', 'sqlite:' . __DIR__ . '/../data/adapter.sqlite'),
        // Для продакшена можно переключить, например, на PostgreSQL:
        // 'dsn' => 'pgsql:host=...;dbname=...',
        // 'user' => '',
        // 'pass' => '',
    ],

    // ---- Очередь и ретраи (ТЗ разд. 10.1) ----
    'queue' => [
        'max_attempts'    => (int) env('QUEUE_MAX_ATTEMPTS', 5),
        'backoff_base_ms' => (int) env('QUEUE_BACKOFF_BASE_MS', 1000), // next = base * 2^attempt
        'batch_size'      => (int) env('QUEUE_BATCH_SIZE', 50),
        // Задачи, зависшие в статусе 'processing' дольше этого времени (сек), возвращать в очередь.
        'stale_after_sec' => (int) env('QUEUE_STALE_AFTER_SEC', 300),
    ],

    // ---- Маппинг LitePMS -> поля лида (ТЗ разд. 5). ----
    // Коды пользовательских полей (UF_CRM_*) НУЖНО подтвердить на портале
    // (ТЗ разд. 16 п. 3). Значения ниже — placeholders для каркаса.
    'mapping' => [
        'uf_ext_id'         => 'UF_CRM_EXT_ID',         // строка: booking_id (ключ связки, ТЗ разд. 6)
        'uf_arrival'        => 'UF_CRM_ARRIVAL',        // дата/время заезда
        'uf_departure'      => 'UF_CRM_DEPARTURE',      // дата/время выезда
        'uf_guests'         => 'UF_CRM_GUESTS',         // число гостей
        'uf_room'           => 'UF_CRM_ROOM',           // категория/номер
        'uf_amount'         => 'UF_CRM_AMOUNT',         // сумма (double)
        'uf_sync_source'    => 'UF_CRM_SYNC_SOURCE',    // маркер происхождения (антицикл, ТЗ 9.2)
        // Атрибуция для сквозной аналитики (ТЗ разд. 14.3):
        'uf_ym_clid'        => 'UF_CRM_YM_CLID',
        'uf_etext'          => 'UF_CRM_ETEXT',
        'uf_yclid'          => 'UF_CRM_YCLID',
        // Оплата (ТЗ разд. 10.3, 13.1 п. 7):
        'uf_payment_status' => 'UF_CRM_PAYMENT_STATUS',
        'uf_payment_amount' => 'UF_CRM_PAYMENT_AMOUNT',
        'uf_payment_date'   => 'UF_CRM_PAYMENT_DATE',
    ],

    // ---- Шаблон заголовка лида (ТЗ разд. 5) ----
    'lead' => [
        'title_template' => 'Бронь {client_surname} {client_name}, {date_in} - {date_out}',
    ],

    // ---- Справочники. Заполняются по факту (ТЗ разд. 10.3, 16 п. 4-5). ----
    // status_id LitePMS (1..9) -> STAGE_ID лида (UUID стадии воронки).
    'status_map' => [
        // 1 => 'STAGE_ID_DRAFT',   // не подтверждено
        // 2 => 'STAGE_ID_NEW',     // подтверждено
        // 3 => 'STAGE_ID_LOST',    // отменено
        // 4 => 'STAGE_ID_OUT',     // выезд
        // 5 => 'STAGE_ID_NO_SHOW', // незаезд
        // 6 => 'STAGE_ID_STAY',    // проживание
        // 8 => 'STAGE_ID_RESERVE', // резерв
        // 9 => 'STAGE_ID_OVER',    // overbooking
    ],
    // service_id LitePMS -> SOURCE_ID лида (справочник источников CRM, ТЗ 14.3).
    'source_map' => [
        // 1  => 'WEB',    // сайт / модуль бронирования
        // 2  => 'CALL',   // звонок
        // ...
    ],

    // ---- Логирование (ТЗ разд. 10.2) ----
    'logging' => [
        'dir'      => __DIR__ . '/../data/logs',
        'level'    => env('LOG_LEVEL', 'info'), // debug|info|warning|error
        'mask_pii' => true,                     // маскировать телефоны/email в логах
    ],

    // ---- Отчётность (XLS) за период (ТЗ разд. 10.2) ----
    'reporting' => [
        'dir' => __DIR__ . '/../data/reports',
    ],
];
