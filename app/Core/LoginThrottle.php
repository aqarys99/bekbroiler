<?php
declare(strict_types=1);

namespace App\Core;

/** Защита от подбора пароля. Хранит только неудачные попытки. */
final class LoginThrottle
{
    public const MAX_PER_LOGIN = 5;
    public const MAX_PER_IP = 20;
    public const WINDOW = 900;
    public const LOCK = 900;

    public static function normalize(string $login): string
    {
        return mb_substr(mb_strtolower(trim($login)), 0, 100);
    }

    public static function fail(string $ip, string $login): void
    {
        $now = time();
        Database::insert('login_attempts', [
            'ip'         => $ip,
            'login'      => self::normalize($login),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
        Database::query('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', $now - 86400)]);
    }

    /** Секунды до разблокировки (0 — свободно). */
    public static function lockedFor(string $ip, string $login): int
    {
        $login = self::normalize($login);
        $a = self::remaining('ip = ? AND login = ?', [$ip, $login], self::MAX_PER_LOGIN);
        $b = self::remaining('ip = ?', [$ip], self::MAX_PER_IP);
        return max($a, $b);
    }

    public static function clear(string $ip, string $login): void
    {
        Database::query('DELETE FROM login_attempts WHERE ip = ? AND login = ?', [$ip, self::normalize($login)]);
    }

    private static function remaining(string $where, array $params, int $limit): int
    {
        $last = Database::fetchValue('SELECT MAX(created_at) FROM login_attempts WHERE ' . $where, $params);
        if ($last === null) {
            return 0;
        }
        $t = self::ts((string)$last);
        $count = (int)Database::fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE ' . $where . ' AND created_at >= ? AND created_at <= ?',
            [...$params, gmdate('Y-m-d H:i:s', $t - self::WINDOW), (string)$last]
        );
        if ($count < $limit) {
            return 0;
        }
        return max(0, $t + self::LOCK - time());
    }

    private static function ts(string $s): int
    {
        return (int)strtotime($s . ' UTC');
    }
}
