<?php
declare(strict_types=1);

namespace App\Core;

final class Json
{
    /** Установить статус и заголовок, вернуть тело (обработчики возвращают строку). */
    public static function respond(int $status, array $data): string
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        http_response_code($status);
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    /** Запрос из fetch/XHR, ждущий JSON. */
    public static function wanted(): bool
    {
        return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }
}
