<?php
declare(strict_types=1);

namespace App\Core;

/** Язык интерфейса админки и мастера установки: пользователь -> cookie -> настройка -> ru. */
final class Locale
{
    public const COOKIE = 'admin_lang';
    public const ALL = ['ru', 'kk'];
    private static ?string $current = null;

    public static function valid(mixed $v): bool
    {
        return is_string($v) && in_array($v, self::ALL, true);
    }

    public static function set(string $locale): void
    {
        self::$current = self::valid($locale) ? $locale : 'ru';
    }

    public static function reset(): void
    {
        self::$current = null;
    }

    public static function current(): string
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $c = $_COOKIE[self::COOKIE] ?? null;
        if (self::valid($c)) {
            return $c;
        }
        $def = config('locale', 'ru');
        try {
            $s = \App\Models\Settings::get('admin_lang');
            if (self::valid($s)) {
                $def = $s;
            }
        } catch (\Throwable) {
        }
        return self::valid($def) ? $def : 'ru';
    }

    public static function remember(string $locale): void
    {
        if (!self::valid($locale) || PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $locale, [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $locale;
    }

    /** Применить язык пользователя к текущему запросу. */
    public static function forUser(array $user): void
    {
        $l = $user['locale'] ?? null;
        if (self::valid($l)) {
            self::set($l);
        }
    }
}
