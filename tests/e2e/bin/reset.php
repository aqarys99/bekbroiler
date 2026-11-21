<?php
declare(strict_types=1);

// Сброс тестового окружения. Только APP_ENV=testing или --force-testing.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
ini_set('display_errors', '1');

$args = array_slice($argv, 1);
if (getenv('APP_ENV') !== 'testing' && !in_array('--force-testing', $args, true)) {
    fwrite(STDERR, "Только для APP_ENV=testing\n");
    exit(1);
}
$storage = getenv('APP_STORAGE_DIR');
$configFile = getenv('APP_CONFIG_FILE');
if (!$storage || !$configFile || !str_contains($storage, 'testing')) {
    fwrite(STDERR, "Нужны APP_STORAGE_DIR (путь с 'testing') и APP_CONFIG_FILE\n");
    exit(1);
}

define('ROOT', dirname(__DIR__, 3));
require ROOT . '/app/autoload.php';
require ROOT . '/app/helpers.php';
date_default_timezone_set('UTC');

use App\Core\Config;
use App\Core\Database;
use App\Core\Installer;

$opts = ['installed' => false, 'debug' => null, 'fail' => [], 'editor' => false];
foreach ($args as $a) {
    if ($a === '--installed') {
        $opts['installed'] = true;
    } elseif ($a === '--with-editor') {
        $opts['editor'] = true;
    } elseif (str_starts_with($a, '--debug=')) {
        $opts['debug'] = substr($a, 8) === '1';
    } elseif (str_starts_with($a, '--fail=')) {
        foreach (array_filter(explode(',', substr($a, 7))) as $item) {
            [$key, $status] = array_pad(explode(':', $item, 2), 2, 'fail');
            $opts['fail'][$key] = $status;
        }
    }
}

function rrmdirContents(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
}

function writeTestConfig(string $file, bool $debug): void
{
    $cfg = array_replace_recursive(Config::defaults(), ['debug' => $debug]);
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }
    file_put_contents($file, "<?php\nreturn " . var_export($cfg, true) . ";\n");
}

Database::setConnection(null);
rrmdirContents($storage);
if (is_file($configFile)) {
    unlink($configFile);
}
if (!is_dir($storage)) {
    mkdir($storage, 0775, true);
}
Config::reset();

if ($opts['debug'] !== null && !$opts['installed']) {
    writeTestConfig($configFile, $opts['debug']);
}

if ($opts['installed']) {
    Installer::install([
        'site_name'        => 'Детский сад «Солнышко»',
        'owner_name'       => 'Иванова Мария Петровна',
        'login'            => 'owner',
        'password'         => 'Owner-pass-2026',
        'password_confirm' => 'Owner-pass-2026',
    ]);
    if ($opts['debug'] !== null) {
        writeTestConfig($configFile, $opts['debug']);
    }
    if ($opts['editor']) {
        Config::load();
        Database::setConnection(null);
        \App\Models\User::create('Петрова Анна Сергеевна', 'editor', 'Editor-pass-2026', 'editor');
    }
}

if ($opts['fail']) {
    file_put_contents($storage . '/fake-requirements.json', json_encode($opts['fail']));
}
echo "OK\n";
