<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

if (is_file(ROOT . '/vendor/autoload.php')) {
    require ROOT . '/vendor/autoload.php';
}
require ROOT . '/app/autoload.php';
require ROOT . '/app/helpers.php';

date_default_timezone_set('UTC');

$tmp = sys_get_temp_dir() . '/sadik-test-' . bin2hex(random_bytes(6));
mkdir($tmp . '/storage', 0775, true);
putenv('APP_ENV=testing');
putenv('APP_STORAGE_DIR=' . $tmp . '/storage');
putenv('APP_CONFIG_FILE=' . $tmp . '/config.php');
$GLOBALS['__sadik_tmp'] = $tmp;

register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
});
