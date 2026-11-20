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
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}
