<?php
declare(strict_types=1);

namespace App\Site;

/** Контекст запроса публичного сайта: язык, режим для слабовидящих, мета-теги страницы. */
final class Ctx
{
    public static string $lang = 'ru';
    public static bool $a11y = false;
    /** true, если на странице показан русский текст вместо отсутствующего казахского (I-11) */
    public static bool $missing = false;
    /** @var array<string,mixed> */
    public static array $meta = [];

    public static function init(string $lang): void
    {
        self::$lang = $lang === 'kk' ? 'kk' : 'ru';
        self::$missing = false;
        self::$meta = [];
        self::$a11y = (bool)setting('a11y_enabled', true) && ($_COOKIE['a11y'] ?? '') === '1';
    }

    public static function isKk(): bool
    {
        return self::$lang === 'kk';
    }

    public static function kkEnabled(): bool
    {
        return (bool)setting('kk_enabled', false);
    }

    /** Адрес страницы сайта с префиксом языка. */
    public static function url(string $path = '/', array $query = [], ?string $lang = null): string
    {
        $lang ??= self::$lang;
        $path = '/' . ltrim($path, '/');
        if ($lang === 'kk') {
            $path = $path === '/' ? '/kk' : '/kk' . $path;
        }
        return url($path, $query);
    }

    /**
     * Текст по-русски или по-казахски. Нет казахского (пусто или копия русского) — русский,
     * и страница помечается «Аударма жоқ». $flag=false — для имён, которые не переводятся.
     */
    public static function pick(array $row, string $field, ?string $kkKey = null, bool $flag = true): string
    {
        $ru = trim((string)($row[$field] ?? ''));
        if (!self::isKk()) {
            return $ru;
        }
        $kk = trim((string)($row[$kkKey ?? $field . '_kk'] ?? ''));
        if ($kk !== '' && ($kk !== $ru || !$flag)) {
            return $kk;
        }
        if ($ru !== '' && $flag) {
            self::$missing = true;
        }
        return $ru;
    }

    /** Схема и хост текущего запроса (адрес сайта в настройках не хранится). */
    public static function origin(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if (!preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
            $host = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
        }
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    /** Путь текущего запроса без base_path и без префикса языка. */
    public static function pathOf(string $uri): array
    {
        $path = (string)parse_url($uri, PHP_URL_PATH);
        $base = rtrim((string)config('base_path', ''), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . ltrim($path, '/');
        $lang = 'ru';
        if ($path === '/kk' || str_starts_with($path, '/kk/')) {
            $lang = 'kk';
            $path = substr($path, 3) ?: '/';
        }
        return [$lang, $path === '' ? '/' : $path];
    }
}
