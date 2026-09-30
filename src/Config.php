<?php

declare(strict_types=1);

namespace LitePmsB24;

use RuntimeException;

/**
 * Загрузчик конфигурации. Считывает config/config.dist.php либо, если он есть,
 * config/config.local.php (с реальными секретами — не в репозитории).
 */
final class Config
{
    /** @var array<string,mixed> */
    private array $data;

    public function __construct(string $file)
    {
        if (!is_file($file)) {
            throw new RuntimeException(
                'Config file not found: ' . $file
                . '. Скопируйте config/config.dist.php в config/config.local.php и заполните значениями.'
            );
        }
        $loaded = require $file;
        $this->data = is_array($loaded) ? $loaded : [];
    }

    public static function load(string $baseDir): self
    {
        $local = $baseDir . '/config/config.local.php';
        $file = is_file($local) ? $local : $baseDir . '/config/config.dist.php';
        return new self($file);
    }

    /**
     * Доступ по точечному пути: $config->get('mapping.uf_ext_id', 'UF_CRM_EXT_ID').
     *
     * @param string     $path
     * @param mixed|null $default
     * @return mixed|null
     */
    public function get(string $path, $default = null)
    {
        $current = $this->data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }
        return $current;
    }
}
