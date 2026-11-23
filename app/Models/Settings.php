<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Настройки сайта: таблица settings (key -> JSON), кэш на запрос. */
final class Settings
{
    /** @var ?array<string,mixed> */
    private static ?array $cache = null;
    private static ?int $conn = null;

    /** @return array<string,mixed> */
    public static function all(): array
    {
        try {
            $id = spl_object_id(Database::connection());
        } catch (\Throwable) {
            return [];
        }
        if (self::$cache === null || self::$conn !== $id) {
            self::$cache = [];
            self::$conn = $id;
            try {
                foreach (Database::fetchAll('SELECT key, value FROM settings') as $r) {
                    self::$cache[(string)$r['key']] = json_decode((string)$r['value'], true);
                }
            } catch (\Throwable) {
                self::$cache = [];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        if (!array_key_exists($key, $all) || $all[$key] === null || $all[$key] === '') {
            return $default;
        }
        return $all[$key];
    }

    public static function set(string $key, mixed $value): void
    {
        Database::query(
            'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]
        );
        self::reset();
    }

    /** @param array<string,mixed> $values */
    public static function setMany(array $values): void
    {
        Database::transaction(static function () use ($values): void {
            foreach ($values as $k => $v) {
                self::set((string)$k, $v);
            }
        });
        self::reset();
    }

    public static function reset(): void
    {
        self::$cache = null;
        self::$conn = null;
    }
}
