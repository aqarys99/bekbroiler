<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use WeakMap;

final class Guard
{
    /** @var WeakMap<Closure,string>|null */
    private static ?WeakMap $roles = null;

    /** Роль, с которой обёрнут обработчик (null — не обёрнут). Для теста охраны. */
    public static function roleOf(mixed $handler): ?string
    {
        return $handler instanceof Closure && self::$roles !== null && isset(self::$roles[$handler])
            ? self::$roles[$handler] : null;
    }

    public static function wrap(array|Closure $handler, string $role = 'editor'): Closure
    {
        $fn = static function (array $params = []) use ($handler, $role) {
            $user = Auth::user();
            $isRead = in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true);
            if ($user === null) {
                $reason = Auth::endReason();
                if ($reason === 'timeout') {
                    Flash::set('info', 'auth.timeout');
                } elseif ($reason === 'version') {
                    Flash::set('info', 'auth.password_changed_elsewhere');
                }
                $login = admin_url('/login');
                if ($isRead) {
                    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
                    redirect($login . '?next=' . rawurlencode($uri));
                }
                redirect($login);
            }
            if ($role === 'owner' && ($user['role'] ?? '') !== 'owner') {
                throw new HttpException(403);
            }
            if (!$isRead && !csrf_verify(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
                throw new HttpException(400);
            }
            if ($handler instanceof Closure) {
                return $handler($params);
            }
            [$class, $action] = $handler;
            return (new $class())->$action($params);
        };
        self::$roles ??= new WeakMap();
        self::$roles[$fn] = $role;
        return $fn;
    }

    /** Допустимый адрес возврата после входа (внутри админки), иначе адрес главной админки. */
    public static function safeNext(mixed $next): string
    {
        $default = admin_url();
        if (!is_string($next) || $next === '' || strlen($next) > 500) {
            return $default;
        }
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $next)) {
            return $default;
        }
        $path = explode('?', $next, 2)[0];
        if (str_contains($path, '//') || str_contains($path, ':') || str_contains($path, '..')) {
            return $default;
        }
        $a = $default;
        if ($path !== $a && !str_starts_with($path, $a . '/')) {
            return $default;
        }
        // не отправлять обратно на сам вход
        if ($path === admin_url('/login')) {
            return $default;
        }
        return $next;
    }
}
