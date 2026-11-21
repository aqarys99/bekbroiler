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
        // новый вход на этом устройстве заменяет его прежний токен
        $oldRaw = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($oldRaw) && preg_match('/^([0-9a-f]{24}):/', $oldRaw, $om)) {
            Database::query('DELETE FROM remember_tokens WHERE selector = ? AND user_id = ?', [$om[1], $userId]);
        }
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
        $presented = hash('sha256', $validator);
        $rotate = false;
        if (hash_equals((string)$row['token_hash'], $presented)) {
            $rotate = true;
        } elseif (!self::matchesPrev($row, $presented)) {
            // и не текущий, и не недавно заменённый: признак кражи
            Database::query('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
            Auth::bumpVersion($userId);
            self::clearCookie();
            return null;
        }
        $user = Database::fetch('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null || (int)$user['is_active'] !== 1) {
            Database::query('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
            self::clearCookie();
            return null;
        }
        if ($rotate) {
            $newValidator = bin2hex(random_bytes(32));
            $st = Database::query(
                'UPDATE remember_tokens SET token_hash = ?, prev_hash = ?, rotated_at = ?, last_used_at = ?
                 WHERE id = ? AND token_hash = ?',
                [hash('sha256', $newValidator), $row['token_hash'], gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'),
                 $row['id'], $row['token_hash']]
            );
            if ($st->rowCount() === 1) {
                self::setCookie($selector . ':' . $newValidator, (int)strtotime($row['expires_at'] . ' UTC'));
            } else {
                // параллельный запрос уже обновил токен: проверяем окно предыдущего
                $fresh = Database::fetch('SELECT * FROM remember_tokens WHERE id = ?', [$row['id']]);
                if ($fresh === null || !self::matchesPrev($fresh, $presented)) {
                    self::clearCookie();
                    return null;
                }
            }
        }
        // в окне предыдущего токена cookie не трогаем: браузер уже получил новый от параллельного запроса
        Auth::establish($user);
        Audit::log($userId, 'login_remember', 'user', $userId);
        return $userId;
    }

    public const PREV_WINDOW = 30;

    private static function matchesPrev(array $row, string $presented): bool
    {
        if (empty($row['prev_hash']) || empty($row['rotated_at'])) {
            return false;
        }
        $age = time() - (int)strtotime($row['rotated_at'] . ' UTC');
        return $age >= 0 && $age <= self::PREV_WINDOW && hash_equals((string)$row['prev_hash'], $presented);
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
