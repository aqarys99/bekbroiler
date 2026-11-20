<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Session;

if (!function_exists('e')) {
    function e(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('t')) {
    function t(string $key, array $replace = []): string
    {
        static $cache = [];
        $locale = preg_replace('/[^a-z]/', '', (string)config('locale', 'ru')) ?: 'ru';
        if (!isset($cache[$locale])) {
            $file = dirname(__DIR__) . '/app/lang/' . $locale . '.php';
            $cache[$locale] = is_file($file) ? (array)require $file : [];
        }
        if (!array_key_exists($key, $cache[$locale])) {
            if (config('debug', false)) {
                Logger::warning('Нет перевода', ['key' => $key]);
            }
            return $key;
        }
        $text = (string)$cache[$locale][$key];
        foreach ($replace as $name => $value) {
            $text = str_replace(':' . $name, (string)$value, $text);
        }
        return $text;
    }
}

if (!function_exists('url')) {
    function url(string $path = '/', array $query = []): string
    {
        $base = rtrim((string)config('base_path', ''), '/');
        $path = '/' . ltrim($path, '/');
        $qs = $query ? '?' . http_build_query($query) : '';
        return $base . $path . $qs;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = dirname(__DIR__) . '/public/assets/' . $path;
        $v = is_file($file) ? '?v=' . filemtime($file) : '';
        return url('/assets/' . $path) . $v;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return url('/' . config('admin_path', 'admin') . $path);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        Session::start();
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        return hash_equals(csrf_token(), $token);
    }
}

if (!function_exists('abort')) {
    function abort(int $code, string $message = ''): never
    {
        throw new HttpException($code, $message);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $code = 302): never
    {
        if (!headers_sent()) {
            header('Location: ' . str_replace(["\r", "\n"], '', $url), true, $code);
        }
        exit;
    }
}
