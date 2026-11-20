<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Migrator
{
    private PDO $pdo;
    private string $dir;

    public function __construct(?PDO $pdo = null, ?string $dir = null)
    {
        $this->pdo = $pdo ?? Database::connection();
        $this->dir = rtrim($dir ?? ROOT . '/migrations', '/');
    }

    /** @return array<string,array{name:string,file:string}> version => info, по возрастанию */
    private function files(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $file) {
            $name = basename($file);
            if (!preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', $name, $m)) {
                continue;
            }
            if (isset($out[$m[1]])) {
                throw new \RuntimeException("Дубликат номера миграции {$m[1]}: {$out[$m[1]]['name']} и $name");
            }
            $out[$m[1]] = ['name' => $name, 'file' => $file];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version    TEXT PRIMARY KEY,
            name       TEXT NOT NULL,
            applied_at TEXT NOT NULL
        )');
    }

    /** @return string[] имена файлов, ожидающих применения */
    public function pending(): array
    {
        $this->ensureTable();
        $applied = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $pending = [];
        foreach ($this->files() as $version => $info) {
            if (!in_array($version, $applied, true)) {
                $pending[] = $info['name'];
            }
        }
        return $pending;
    }

    /** @return string[] применённые в этом вызове */
    public function migrate(): array
    {
        $pending = $this->pending();
        if (!$pending) {
            return [];
        }
        $files = $this->files();
        $done = [];
        foreach ($files as $version => $info) {
            if (!in_array($info['name'], $pending, true)) {
                continue;
            }
            $sql = (string)file_get_contents($info['file']);
            try {
                $this->pdo->exec('BEGIN IMMEDIATE');
                $st = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
                $st->execute([$version]);
                if ($st->fetchColumn() === false) {
                    $this->pdo->exec($sql);
                    $this->pdo->prepare('INSERT INTO schema_migrations (version, name, applied_at) VALUES (?, ?, ?)')
                        ->execute([$version, $info['name'], gmdate('Y-m-d H:i:s')]);
                    $done[] = $info['name'];
                }
                $this->pdo->exec('COMMIT');
            } catch (\Throwable $e) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (\Throwable) {
                    // транзакции уже нет — откатывать нечего
                }
                throw new \RuntimeException('Миграция ' . $info['name'] . ': ' . $e->getMessage(), 0, $e);
            }
        }
        return $done;
    }
}
