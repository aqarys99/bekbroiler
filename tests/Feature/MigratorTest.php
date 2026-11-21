<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Migrator;
use PDO;
use Tests\TestCase;

final class MigratorTest extends TestCase
{
    private function tables(PDO $pdo): array
    {
        return $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testEmptyDbGetsCoreAndRepeatDoesNothing(): void
    {
        $pdo = Database::open($this->tempDir() . '/m.sqlite');
        $m = new Migrator($pdo);
        $this->assertSame(['001_core.sql', '002_auth.sql', '003_remember_rotation.sql'], $m->pending());
        $this->assertSame(['001_core.sql', '002_auth.sql', '003_remember_rotation.sql'], $m->migrate());
        foreach (['users', 'login_attempts', 'settings', 'audit_log', 'schema_migrations'] as $t) {
            $this->assertContains($t, $this->tables($pdo));
        }
        $this->assertSame([], $m->migrate());
        $this->assertSame([], $m->pending());
        $this->assertSame(['001', '002', '003'], $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
    }

    /** I-17 */
    public function testNewMigrationAppliedExactlyOnce(): void
    {
        $dir = $this->tempDir();
        copy(ROOT . '/migrations/001_core.sql', $dir . '/001_core.sql');
        $pdo = Database::open($this->tempDir() . '/m.sqlite');
        $this->assertSame(['001_core.sql'], (new Migrator($pdo, $dir))->migrate());
        file_put_contents($dir . '/002_extra.sql', 'CREATE TABLE extra (id INTEGER PRIMARY KEY);');
        $this->assertSame(['002_extra.sql'], (new Migrator($pdo, $dir))->migrate());
        $this->assertSame([], (new Migrator($pdo, $dir))->migrate());
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version='002'")->fetchColumn());
        $this->assertContains('extra', $this->tables($pdo));
    }

    /** I-17: сбой откатывает миграцию, данные целы, следующие не применяются */
    public function testBrokenMigrationRollsBack(): void
    {
        $dir = $this->tempDir();
        copy(ROOT . '/migrations/001_core.sql', $dir . '/001_core.sql');
        $pdo = Database::open($this->tempDir() . '/m.sqlite');
        (new Migrator($pdo, $dir))->migrate();
        $pdo->exec("INSERT INTO users (name, login, password_hash) VALUES ('A','a','h')");
        file_put_contents($dir . '/002_bad.sql', "CREATE TABLE half (id INTEGER);\nINSERT INTO nonexistent VALUES (1);");
        file_put_contents($dir . '/003_next.sql', 'CREATE TABLE next_t (id INTEGER);');
        try {
            (new Migrator($pdo, $dir))->migrate();
            $this->fail('exception expected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('002_bad.sql', $e->getMessage());
        }
        $this->assertNotContains('half', $this->tables($pdo));
        $this->assertNotContains('next_t', $this->tables($pdo));
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM schema_migrations WHERE version IN ('002','003')")->fetchColumn());
        $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertFalse($pdo->inTransaction());
    }

    public function testBadFileNamesIgnoredAndDuplicateNumberThrows(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir . '/readme.sql', 'garbage');
        file_put_contents($dir . '/1_x.sql', 'garbage');
        file_put_contents($dir . '/002_Upper.sql', 'garbage');
        file_put_contents($dir . '/002_ok.sql', 'CREATE TABLE ok_t (id INTEGER);');
        $pdo = Database::open($this->tempDir() . '/m.sqlite');
        $this->assertSame(['002_ok.sql'], (new Migrator($pdo, $dir))->migrate());
        file_put_contents($dir . '/002_dup.sql', 'SELECT 1;');
        $this->expectException(\RuntimeException::class);
        (new Migrator($pdo, $dir))->pending();
    }

    public function testForeignKeySetNullAndRoleCheck(): void
    {
        $pdo = $this->freshDatabase();
        $pdo->exec("INSERT INTO users (id, name, login, password_hash, role) VALUES (1,'A','a','h','owner')");
        $pdo->exec("INSERT INTO audit_log (user_id, action, entity, entity_id) VALUES (1,'create','user',1)");
        $pdo->exec('DELETE FROM users WHERE id = 1');
        $this->assertNull($pdo->query('SELECT user_id FROM audit_log')->fetchColumn());

        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO users (name, login, password_hash, role) VALUES ('B','b','h','admin')");
    }

    public function testLoginUniqueNoCase(): void
    {
        $pdo = $this->freshDatabase();
        $pdo->exec("INSERT INTO users (name, login, password_hash) VALUES ('A','Admin','h')");
        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO users (name, login, password_hash) VALUES ('B','ADMIN','h')");
    }
}
