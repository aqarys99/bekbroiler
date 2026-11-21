<?php
declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(): void
    {
        if (PHP_SAPI === 'cli') {
            // В консоли (тесты) сессия — обычный массив
            $_SESSION ??= [];
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        $save = session_save_path();
        $save = $save !== '' ? preg_replace('/^\d+;(?:0?\d+;)?/', '', $save) : sys_get_temp_dir();
        if (!is_dir($save) || !is_writable($save)) {
            $fallback = Config::storageDir() . '/sessions';
            if (!is_dir($fallback)) {
                @mkdir($fallback, 0775, true);
            }
            if (is_writable($fallback)) {
                session_save_path($fallback);
            }
        }
        session_name('sadik_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => self::cookiePath(),
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function cookiePath(): string
    {
        return (string)config('base_path', '') ?: '/';
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** Новый ID сессии (после входа и смены пароля). */
    public static function regenerate(): void
    {
        self::start();
        if (PHP_SAPI === 'cli') {
            return;
        }
        session_regenerate_id(true);
    }

    /** Полностью завершить сессию и удалить cookie. */
    public static function destroy(): void
    {
        $_SESSION = [];
        if (PHP_SAPI === 'cli') {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (!headers_sent()) {
            setcookie('sadik_sid', '', [
                'expires'  => time() - 3600,
                'path'     => self::cookiePath(),
                'secure'   => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        unset($_COOKIE['sadik_sid']);
    }
}
