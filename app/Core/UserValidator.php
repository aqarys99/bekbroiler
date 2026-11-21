<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\User;

/** Общие правила полей пользователя (установщик, раздел «Пользователи», профиль). Возвращают ключ перевода ошибки. */
final class UserValidator
{
    public static function cleanName(mixed $v): string
    {
        return is_string($v) ? trim((string)preg_replace('/\s+/u', ' ', trim($v))) : '';
    }

    public static function cleanLogin(mixed $v): string
    {
        return is_string($v) ? mb_strtolower(trim($v)) : '';
    }

    public static function nameError(string $name): ?string
    {
        if ($name === '') {
            return 'validation.owner_name.required';
        }
        return mb_strlen($name) > 100 ? 'validation.owner_name.length' : null;
    }

    public static function loginError(string $login, ?int $exceptId = null, bool $checkUnique = true): ?string
    {
        $len = mb_strlen($login);
        if ($login === '') {
            return 'validation.login.required';
        }
        if ($len < 3 || $len > 50) {
            return 'validation.login.length';
        }
        if (!preg_match('/^[a-z0-9._-]+$/', $login)) {
            return 'validation.login.format';
        }
        if ($checkUnique) {
            $other = User::findByLogin($login);
            if ($other !== null && (int)$other['id'] !== $exceptId) {
                return 'validation.login.taken';
            }
        }
        return null;
    }

    public static function passwordError(string $pass): ?string
    {
        if ($pass === '') {
            return 'validation.password.required';
        }
        if (mb_strlen($pass) < 8) {
            return 'validation.password.min';
        }
        if (mb_strlen($pass) > 64 || strlen($pass) > 72) {
            return 'validation.password.max';
        }
        return null;
    }

    public static function roleError(mixed $role): ?string
    {
        return is_string($role) && in_array($role, ['owner', 'editor'], true) ? null : 'validation.role.invalid';
    }
}
