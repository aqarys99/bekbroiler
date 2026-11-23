<?php
declare(strict_types=1);

namespace App\Core;

/** Разбор ссылок YouTube и запрос названия через oEmbed. */
final class YouTube
{
    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be', 'www.youtu.be'];

    /** Идентификатор ролика из ссылки любого вида; null, если ссылка не опознана. */
    public static function parseId(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 500 || preg_match('/[\x00-\x20\x7f]/', $input)) {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $input)) {
            $input = 'https://' . $input;
        }
        $u = parse_url($input);
        if (!is_array($u) || !in_array(strtolower((string)($u['scheme'] ?? '')), ['http', 'https'], true)
            || isset($u['user']) || isset($u['pass'])) {
            return null;
        }
        $host = strtolower((string)($u['host'] ?? ''));
        if (!in_array($host, self::HOSTS, true)) {
            return null;
        }
        $path = (string)($u['path'] ?? '');
        $id = null;
        if (str_ends_with($host, 'youtu.be')) {
            $id = trim($path, '/');
        } elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
            $id = $m[1];
        } elseif ($path === '/watch') {
            parse_str((string)($u['query'] ?? ''), $q);
            $id = is_string($q['v'] ?? null) ? $q['v'] : null;
        }
        return is_string($id) && self::validId($id) ? $id : null;
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1;
    }

    public static function watchUrl(string $id): string
    {
        return 'https://www.youtube.com/watch?v=' . $id;
    }

    public static function posterUrl(string $id): string
    {
        return 'https://img.youtube.com/vi/' . $id . '/hqdefault.jpg';
    }

    /** Название ролика через oEmbed (таймаут 5 с). null — сервис недоступен или ролик закрыт. */
    public static function fetchTitle(string $id): ?string
    {
        if (!self::validId($id)) {
            return null;
        }
        $url = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode(self::watchUrl($id));
        $body = self::httpGet($url, 5);
        $data = $body !== null ? json_decode($body, true) : null;
        $title = is_array($data) && is_string($data['title'] ?? null) ? trim($data['title']) : '';
        return $title !== '' ? mb_substr($title, 0, 200) : null;
    }

    private static function httpGet(string $url, int $timeout): ?string
    {
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 262144,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'kindergarten-admin',
                ]);
                $out = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                curl_close($ch);
                return is_string($out) && $code === 200 ? $out : null;
            }
            if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
                return null;
            }
            $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0, 'user_agent' => 'kindergarten-admin']]);
            $out = @file_get_contents($url, false, $ctx, 0, 262144);
            return is_string($out) && str_contains((string)($http_response_header[0] ?? ''), ' 200') ? $out : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
