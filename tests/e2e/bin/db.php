<?php
declare(strict_types=1);

// Печатает JSON строк из тестовой БД. Разрешён только один SELECT.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
ini_set('display_errors', '1');

$sql = trim((string)($argv[1] ?? ''));
$sql = rtrim($sql, "; \t\n\r");
if (!preg_match('/^SELECT\b/i', $sql) || str_contains($sql, ';')) {
    fwrite(STDERR, "Разрешён только один SELECT\n");
    exit(1);
}

define('ROOT', dirname(__DIR__, 3));
require ROOT . '/app/autoload.php';
require ROOT . '/app/helpers.php';

use App\Core\Config;
use App\Core\Database;

date_default_timezone_set('UTC');
if (!is_file(Config::dbPath())) {
    echo "[]\n";
    exit(0);
}
$pdo = Database::open(Config::dbPath());
$pdo->exec('PRAGMA query_only = 1');
$rows = $pdo->query($sql)->fetchAll();
echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
