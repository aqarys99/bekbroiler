<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use Tests\TestCase;

final class DatabaseTest extends TestCase
{
    public function testPragmas(): void
    {
        $pdo = $this->freshDatabase();
        $this->assertSame(1, (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $this->assertSame('wal', strtolower((string)$pdo->query('PRAGMA journal_mode')->fetchColumn()));
        $this->assertSame(5000, (int)$pdo->query('PRAGMA busy_timeout')->fetchColumn());
    }

    public function testInsertReturnsIdAndFetchHelpers(): void
    {
        $this->freshDatabase();
        $uid = Database::insert('users', ['name' => 'N', 'login' => 'l1', 'password_hash' => 'h']);
        $this->assertSame(1, $uid);
        $this->assertSame('N', Database::fetch('SELECT name FROM users WHERE id = ?', [$uid])['name']);
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM users'));
        $this->assertCount(1, Database::fetchAll('SELECT * FROM users'));
        $this->assertNull(Database::fetch('SELECT * FROM users WHERE id = 999'));
    }

    public function testTransactionRollsBackAndRethrows(): void
    {
        $this->freshDatabase();
        try {
            Database::transaction(function () {
                Database::insert('users', ['name' => 'N', 'login' => 'l2', 'password_hash' => 'h']);
                throw new \DomainException('fail');
            });
            $this->fail('exception expected');
        } catch (\DomainException) {
        }
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM users'));
        $r = Database::transaction(fn() => 5);
        $this->assertSame(5, $r);
    }

    public function testPdoOptions(): void
    {
        $pdo = $this->freshDatabase();
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
        $this->assertSame(\PDO::FETCH_ASSOC, $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
    }
}
