<?php
declare(strict_types=1);

// Выполняет один UPDATE/INSERT/DELETE в тестовой БД, печатает число затронутых строк.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
ini_set('display_errors', '1');

if (getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "Только для APP_ENV=testing\n");
    exit(1);
}
$sql = rtrim(trim((string)($argv[1] ?? '')), "; \t\n\r");
if (!preg_match('/^(UPDATE|INSERT|DELETE)\b/i', $sql) || str_contains($sql, ';')) {
    fwrite(STDERR, "Разрешены только UPDATE, INSERT, DELETE (один запрос)\n");
    exit(1);
}

define('ROOT', dirname(__DIR__, 3));
require ROOT . '/app/autoload.php';
require ROOT . '/app/helpers.php';

use App\Core\Config;
use App\Core\Database;

date_default_timezone_set('UTC');
$pdo = Database::open(Config::dbPath());
echo $pdo->exec($sql), "\n";
