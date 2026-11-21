<?php
declare(strict_types=1);

namespace App\Core;

/** «Запомнить меня»: cookie selector:validator, в БД — SHA-256 хэш validator. */
final class RememberMe
{
    public const COOKIE = 'sadik_remember';
    public const DAYS = 30;
    public const MAX_PER_USER = 10;

    public static function issue(int $userId): void
    {
        $now = time();
        Database::query('DELETE FROM remember_tokens WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', $now)]);
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $expires = $now + self::DAYS * 86400;
        Database::insert('remember_tokens', [
            'user_id'    => $userId,
            'selector'   => $selector,
            'token_hash' => hash('sha256', $validator),
            'expires_at' => gmdate('Y-m-d H:i:s', $expires),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ]);
        Database::query(
            'DELETE FROM remember_tokens WHERE user_id = ? AND id NOT IN
               (SELECT id FROM remember_tokens WHERE user_id = ? ORDER BY id DESC LIMIT ' . self::MAX_PER_USER . ')',
            [$userId, $userId]
        );
        self::setCookie($selector . ':' . $validator, $expires);
    }

    /** Войти по cookie. Возвращает id пользователя или null. */
    public static function consume(): ?int
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw) || !preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $raw, $m)) {
            self::clearCookie();
            return null;
        }
        [, $selector, $validator] = $m;
        $row = Database::fetch('SELECT * FROM remember_tokens WHERE selector = ?', [$selector]);
        if ($row === null) {
            self::clearCookie();
            return null;
        }
        if ((string)$row['expires_at'] < gmdate('Y-m-d H:i:s')) {
            Database::query('DELETE FROM remember_tokens WHERE id = ?', [$row['id']]);
            self::clearCookie();
            return null;
        }
        $userId = (int)$row['user_id'];
        if (!hash_equals((string)$row['token_hash'], hash('sha256', $validator))) {
            Database::query('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
            self::clearCookie();
            return null;
        }
        $user = Database::fetch('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null || (int)$user['is_active'] !== 1) {
            Database::query('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
            self::clearCookie();
            return null;
        }
        $newValidator = bin2hex(random_bytes(32));
        Database::query('UPDATE remember_tokens SET token_hash = ?, last_used_at = ? WHERE id = ?',
            [hash('sha256', $newValidator), gmdate('Y-m-d H:i:s'), $row['id']]);
        self::setCookie($selector . ':' . $newValidator, (int)strtotime($row['expires_at'] . ' UTC'));
        Auth::establish($user);
        Audit::log($userId, 'login_remember', 'user', $userId);
        return $userId;
    }

    /** Удалить токен этого устройства и cookie. */
    public static function forgetCurrent(): void
    {
        $raw = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($raw) && preg_match('/^([0-9a-f]{24}):/', $raw, $m)) {
            Database::query('DELETE FROM remember_tokens WHERE selector = ?', [$m[1]]);
        }
        self::clearCookie();
    }

    private static function clearCookie(): void
    {
        unset($_COOKIE[self::COOKIE]);
        self::send('', time() - 3600);
    }

    private static function setCookie(string $value, int $expires): void
    {
        $_COOKIE[self::COOKIE] = $value;
        self::send($value, $expires);
    }

    private static function send(string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => Session::cookiePath(),
            'secure'   => Session::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
