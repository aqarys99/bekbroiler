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
            $json = Json::wanted();
            if ($user === null) {
                if ($json) {
                    return Json::respond(401, ['ok' => false, 'code' => 'session', 'message' => t('media.error.session')]);
                }
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
                if ($json) {
                    return Json::respond(403, ['ok' => false, 'code' => 'forbidden', 'message' => t('error.403.title')]);
                }
                throw new HttpException(403);
            }
            if (!$isRead && $json) {
                // тело больше post_max_size: PHP отдаёт пустые $_POST/$_FILES — это 413, а не ложный 400
                $postMax = self::iniBytes((string)ini_get('post_max_size'));
                $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
                if ($postMax > 0 && $len > $postMax) {
                    return Json::respond(413, ['ok' => false, 'code' => 'too_large', 'message' => t('media.error.too_large', ['max' => format_bytes(min($postMax, \App\Models\Media::maxUploadBytes()))])]);
                }
            }
            if (!$isRead) {
                $token = is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null;
                if ($json && ($token === null || $token === '') && is_string($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
                    $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
                }
                if (!csrf_verify($token)) {
                    if ($json) {
                        return Json::respond(400, ['ok' => false, 'code' => 'csrf', 'message' => t('media.error.csrf')]);
                    }
                    throw new HttpException(400);
                }
            }
            if (!$isRead) {
                // любое сохранение в админке сбрасывает кэш сайта (I-13), даже если обработчик завершится redirect()/exit
                register_shutdown_function([SiteCache::class, 'flush']);
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

    /** Значение php.ini ('8M', '2G', '512K', '-1') в байтах; <= 0 — без лимита. */
    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || !preg_match('/^(-?\d+)\s*([kmg]?)/i', $v, $m)) {
            return 0;
        }
        $n = (int)$m[1];
        return match (strtolower($m[2])) {
            'k' => $n * 1024,
            'm' => $n * 1024 * 1024,
            'g' => $n * 1024 * 1024 * 1024,
            default => $n,
        };
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
