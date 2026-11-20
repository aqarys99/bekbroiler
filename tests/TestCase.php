<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Новый временный каталог (удаляется при завершении тестов вместе с корнем). */
    protected function tempDir(): string
    {
        $dir = $GLOBALS['__sadik_tmp'] . '/t-' . bin2hex(random_bytes(5));
        mkdir($dir, 0775, true);
        return $dir;
    }

    /** Новый файл SQLite со всеми миграциями; подставляется как соединение Database. */
    protected function freshDatabase(): PDO
    {
        $pdo = Database::open($this->tempDir() . '/test.sqlite');
        Database::setConnection($pdo);
        (new Migrator($pdo))->migrate();
        return $pdo;
    }

    protected function tearDown(): void
    {
        Database::setConnection(null);
        Config::reset();
        parent::tearDown();
    }
}
