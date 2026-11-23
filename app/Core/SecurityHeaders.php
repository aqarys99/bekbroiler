<?php
declare(strict_types=1);

namespace App\Core;

/** Заголовки безопасности для админки и публичного сайта (§9). */
final class SecurityHeaders
{
    /** Заголовки для запроса: админка — строже (нельзя встраивать, ни в какие рамки). */
    public static function build(bool $admin, bool $https, ?string $mapHost = null): array
    {
        $frames = ["'self'", 'https://www.youtube-nocookie.com'];
        if ($mapHost !== null && $mapHost !== '') {
            $frames[] = 'https://' . $mapHost;
        }
        $csp = [
            "default-src 'self'",
            "script-src 'self'",
            // стили в атрибутах style="" используются в разметке (цвета темы, полосы прогресса)
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: https://img.youtube.com https://i.ytimg.com",
            "media-src 'self'",
            "font-src 'self'",
            "connect-src 'self'",
            'frame-src ' . implode(' ', $frames),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            $admin ? "frame-ancestors 'none'" : "frame-ancestors 'self'",
        ];
        $h = [
            'Content-Security-Policy' => implode('; ', $csp),
            'X-Frame-Options' => $admin ? 'DENY' : 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
        ];
        if ($https) {
            $h['Strict-Transport-Security'] = 'max-age=15552000';
        }
        return $h;
    }

    public static function isHttps(): bool
    {
        $https = (string)($_SERVER['HTTPS'] ?? '');
        return ($https !== '' && strtolower($https) !== 'off')
            || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    /** Отправить заголовки (вызывается из front controller до любого вывода). */
    public static function send(bool $withDb = true): void
    {
        if (headers_sent()) {
            return;
        }
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $adminPrefix = rtrim((string)config('base_path', ''), '/') . '/' . trim((string)config('admin_path', 'admin'), '/');
        $admin = $path === $adminPrefix || str_starts_with($path, $adminPrefix . '/');
        $host = null;
        if ($withDb) {
            try {
                $src = (string)\App\Models\Settings::get('map_src', '');
                $h = parse_url($src, PHP_URL_HOST);
                $host = is_string($h) && preg_match('/^[a-z0-9.-]+$/i', $h) ? $h : null;
            } catch (\Throwable $e) {
                $host = null;
            }
        }
        foreach (self::build($admin, self::isHttps(), $host) as $k => $v) {
            header($k . ': ' . $v);
        }
    }
}
