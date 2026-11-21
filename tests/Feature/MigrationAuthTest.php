<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Migrator;
use Tests\TestCase;

final class MigrationAuthTest extends TestCase
{
    public function testAppliesOnTopOfCoreAndCascades(): void
    {
        $dir = $this->tempDir();
        copy(ROOT . '/migrations/001_core.sql', $dir . '/001_core.sql');
        $pdo = Database::open($this->tempDir() . '/m.sqlite');
        Database::setConnection($pdo);
        (new Migrator($pdo, $dir))->migrate();
        $pdo->exec("INSERT INTO users (name, login, password_hash) VALUES ('A','a','x')");

        $m = new Migrator($pdo);
        $applied = $m->migrate();
        $this->assertNotEmpty($applied);
        $this->assertSame([], $m->migrate(), 'второй раз не применяется');

        $this->assertSame(1, (int)$pdo->query('SELECT auth_version FROM users')->fetchColumn());
        $pdo->exec("INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (1,'s','h','2999-01-01 00:00:00')");
        $pdo->exec('DELETE FROM users WHERE id = 1');
        $this->assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM remember_tokens')->fetchColumn());
    }
}
