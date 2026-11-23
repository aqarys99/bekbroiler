<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Кэш страниц публичного сайта (I-13): файлы storage/cache/site-<хэш>.html.
 * Живёт TTL секунд (запланированные новости выходят без cron) и сбрасывается при любом сохранении в админке.
 */
final class SiteCache
{
    public const TTL = 300;

    private static function dir(): string
    {
        return Config::storageDir() . '/cache';
    }

    private static function file(string $key): string
    {
        return self::dir() . '/site-' . sha1($key) . '.html';
    }

    public static function get(string $key): ?string
    {
        $f = self::file($key);
        if (!is_file($f) || filemtime($f) < time() - self::TTL) {
            return null;
        }
        $html = @file_get_contents($f);
        return $html === false ? null : $html;
    }

    public static function put(string $key, string $html): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $html) !== false) {
            @rename($tmp, self::file($key));
        }
    }

    public static function flush(): void
    {
        $dir = self::dir();
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f) && $f[strlen($dir) + 1] !== '.') {
                @unlink($f);
            }
        }
    }
}
