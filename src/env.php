<?php

declare(strict_types=1);

/**
 * Простой глобальный хелпер чтения переменных окружения.
 * Используется в config.*.php. Подключается через composer autoload (files).
 */
if (!function_exists('env')) {
    function env(string $key, $default = null)
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }
}
