<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

define('ROOT', dirname(__DIR__));
require ROOT . '/app/autoload.php';
require ROOT . '/app/helpers.php';

use App\Core\Config;
use App\Core\Migrator;

date_default_timezone_set('UTC');
Config::load();

try {
    $applied = (new Migrator())->migrate();
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
if (!$applied) {
    echo "Нечего применять." . PHP_EOL;
} else {
    foreach ($applied as $name) {
        echo "Применена: $name" . PHP_EOL;
    }
}
exit(0);
