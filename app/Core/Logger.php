<?php
declare(strict_types=1);

namespace App\Core;

final class Logger
{
    public static function error(string $msg, array $context = []): void
    {
        self::write('ERROR', $msg, $context);
    }

    public static function warning(string $msg, array $context = []): void
    {
        self::write('WARNING', $msg, $context);
    }

    public static function info(string $msg, array $context = []): void
    {
        self::write('INFO', $msg, $context);
    }

    private static function write(string $level, string $msg, array $context): void
    {
        try {
            $ctx = $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : '';
            $line = '[' . gmdate('Y-m-d H:i:s') . '] ' . $level . ' ' . $msg . $ctx . PHP_EOL;
            $dir = Config::storageDir() . '/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $file = $dir . '/app-' . gmdate('Y-m-d') . '.log';
            if (!is_dir($dir) || @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
                @error_log(trim($line));
            }
        } catch (\Throwable) {
            // логгер никогда не бросает исключения
        }
    }
}
