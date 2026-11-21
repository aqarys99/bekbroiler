<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function findByLogin(string $login): ?array
    {
        return Database::fetch('SELECT * FROM users WHERE login = ? COLLATE NOCASE', [$login]);
    }

    /** owner раньше editor, затем по имени. */
    public static function all(): array
    {
        return Database::fetchAll(
            "SELECT * FROM users ORDER BY CASE role WHEN 'owner' THEN 0 ELSE 1 END, name COLLATE NOCASE, id"
        );
    }

    public static function create(string $name, string $login, string $password, string $role): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return Database::insert('users', [
            'name'          => $name,
            'login'         => $login,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
            'is_active'     => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
    }

    public static function update(int $id, string $name, string $login, string $role): void
    {
        Database::query(
            'UPDATE users SET name = ?, login = ?, role = ?, updated_at = ? WHERE id = ?',
            [$name, $login, $role, gmdate('Y-m-d H:i:s'), $id]
        );
    }

    public static function setActive(int $id, bool $active): void
    {
        Database::query('UPDATE users SET is_active = ?, updated_at = ? WHERE id = ?',
            [$active ? 1 : 0, gmdate('Y-m-d H:i:s'), $id]);
    }

    public static function setPassword(int $id, string $password): void
    {
        Database::query('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), gmdate('Y-m-d H:i:s'), $id]);
    }

    public static function countActiveOwners(): int
    {
        return (int)Database::fetchValue("SELECT COUNT(*) FROM users WHERE role = 'owner' AND is_active = 1");
    }
}
