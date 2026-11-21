<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\User;

final class Auth
{
    public const TIMEOUT = 7200;
    /** Фиктивный bcrypt-хэш: неизвестный логин проверяется за то же время. */
    private const DUMMY_HASH = '$2y$10$2z8b2Sc2um7K9R3lAHPjAOIEVjOzLIwbz6Zb2aK3vSYepmyabPohW';

    private static bool $resolved = false;
    private static ?array $user = null;
    /** Почему сессия закончилась в этом запросе: timeout | version | null */
    private static ?string $endReason = null;

    public static function reset(): void
    {
        self::$resolved = false;
        self::$user = null;
        self::$endReason = null;
    }

    public static function endReason(): ?string
    {
        return self::$endReason;
    }

    public static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public static function attempt(string $login, string $password, bool $remember, string $ip): AuthResult
    {
        $login = LoginThrottle::normalize($login);
        [$left, $slot] = LoginThrottle::reserve($ip, $login);
        if ($left > 0 || $slot === null) {
            return new AuthResult(AuthResult::LOCKED, null, (int)ceil($left / 60));
        }

        $user = $login === '' ? null : User::findByLogin($login);
        $hash = $user !== null ? (string)$user['password_hash'] : self::DUMMY_HASH;
        $valid = password_verify($password, $hash) && $user !== null;

        if (!$valid) {
            $uid = $user !== null ? (int)$user['id'] : null;
            Audit::log(null, 'login_failed', 'user', $uid);
            $left = LoginThrottle::lockedFor($ip, $login);
            if ($left > 0) {
                Audit::log(null, 'login_locked', 'user', $uid);
                return new AuthResult(AuthResult::LOCKED, null, (int)ceil($left / 60));
            }
            return new AuthResult(AuthResult::INVALID);
        }

        $id = (int)$user['id'];
        if ((int)$user['is_active'] !== 1) {
            LoginThrottle::release($slot);
            return new AuthResult(AuthResult::BLOCKED, $id);
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Database::query('UPDATE users SET password_hash = ? WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), $id]);
        }
        LoginThrottle::clear($ip, $login);
        self::establish($user);
        Audit::log($id, 'login', 'user', $id);
        if ($remember) {
            RememberMe::issue($id);
        }
        return new AuthResult(AuthResult::OK, $id);
    }

    /** Открыть сессию для пользователя (общая часть входа по паролю и по токену). */
    public static function establish(array $user): void
    {
        $id = (int)$user['id'];
        Session::start();
        Session::regenerate();
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        $_SESSION['user_id'] = $id;
        $_SESSION['auth_version'] = (int)$user['auth_version'];
        $_SESSION['_last_activity'] = time();
        $now = gmdate('Y-m-d H:i:s');
        Database::query('UPDATE users SET last_login_at = ? WHERE id = ?', [$now, $id]);
        $user['last_login_at'] = $now;
        self::$user = $user;
        self::$resolved = true;
        self::$endReason = null;
    }

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        Session::start();
        self::$resolved = true;
        self::$user = null;

        if (!empty($_SESSION['user_id'])) {
            $last = (int)($_SESSION['_last_activity'] ?? 0);
            if (time() - $last > self::TIMEOUT) {
                Session::destroy();
                self::$endReason = 'timeout';
            } else {
                $user = User::find((int)$_SESSION['user_id']);
                if ($user === null || (int)$user['is_active'] !== 1) {
                    self::end();
                } elseif ((int)$user['auth_version'] !== (int)($_SESSION['auth_version'] ?? 0)) {
                    self::end();
                    self::$endReason = 'version';
                } else {
                    $_SESSION['_last_activity'] = time();
                    return self::$user = $user;
                }
                return null;
            }
        }

        $id = RememberMe::consume();
        if ($id !== null) {
            self::$endReason = null;
            return self::$user;
        }
        return null;
    }

    private static function end(): void
    {
        Session::destroy();
        RememberMe::forgetCurrent();
        self::$user = null;
    }

    public static function logout(): void
    {
        $user = self::user();
        $id = $user !== null ? (int)$user['id'] : null;
        if ($id !== null) {
            Audit::log($id, 'logout', 'user', $id);
        }
        RememberMe::forgetCurrent();
        Session::destroy();
        self::$user = null;
    }

    /** Завершить чужие сессии и remember-токены пользователя (I-22). */
    public static function bumpVersion(int $userId): void
    {
        Database::query('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?', [$userId]);
        Database::query('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
        if ((int)($_SESSION['user_id'] ?? 0) === $userId && self::$user !== null) {
            $fresh = User::find($userId);
            if ($fresh !== null) {
                $_SESSION['auth_version'] = (int)$fresh['auth_version'];
                self::$user = $fresh;
                Session::regenerate();
            }
        }
    }
}
