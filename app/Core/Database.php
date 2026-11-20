<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::open(Config::dbPath());
        }
        return self::$pdo;
    }

    /** Подставить готовое соединение (тесты) или сбросить (null). */
    public static function setConnection(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function open(string $path): PDO
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог БД');
        }
        $isNew = !is_file($path);
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $mode = strtolower((string)$pdo->query('PRAGMA journal_mode = WAL')->fetchColumn());
        if ($mode !== 'wal') {
            Logger::warning('SQLite: режим WAL недоступен', ['journal_mode' => $mode]);
        }
        $pdo->exec('PRAGMA synchronous = NORMAL');
        if ($isNew && is_file($path)) {
            @chmod($path, 0640);
        }
        return $pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $st = self::connection()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchValue(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** Имена таблицы и колонок — только из кода, не из пользовательского ввода. */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        foreach ([$table, ...$cols] as $name) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string)$name)) {
                throw new \InvalidArgumentException('Недопустимое имя в insert()');
            }
        }
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ')';
        self::query($sql, array_values($data));
        return (int)self::connection()->lastInsertId();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
