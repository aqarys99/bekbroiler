<?php
declare(strict_types=1);

namespace App\Core;

/** Кэш публичного сайта (наполняется в фиче 11). Сейчас только сброс. */
final class SiteCache
{
    public static function flush(): void
    {
        $dir = Config::storageDir() . '/cache';
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f) && $f[strlen($dir) + 1] !== '.') {
                @unlink($f);
            }
        }
    }
}
