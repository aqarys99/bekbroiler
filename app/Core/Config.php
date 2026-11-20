<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static ?array $data = null;

    public static function configFile(): string
    {
        return getenv('APP_CONFIG_FILE') ?: ROOT . '/config/config.php';
    }

    public static function storageDir(): string
    {
        return rtrim(getenv('APP_STORAGE_DIR') ?: ROOT . '/storage', '/');
    }

    public static function defaults(): array
    {
        return (array)require ROOT . '/config/config.example.php';
    }

    public static function load(): void
    {
        $data = self::defaults();
        $file = self::configFile();
        if (is_file($file)) {
            $user = require $file;
            if (is_array($user)) {
                $data = array_replace_recursive($data, $user);
            }
        }
        self::$data = $data;
    }

    /** Заменить значения (для установщика и тестов). */
    public static function set(array $values): void
    {
        if (self::$data === null) {
            self::load();
        }
        self::$data = array_replace_recursive(self::$data, $values);
    }

    public static function reset(): void
    {
        self::$data = null;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$data === null) {
            self::load();
        }
        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Абсолютный путь к файлу БД. */
    public static function dbPath(): string
    {
        if ((string)self::get('db.driver', 'sqlite') !== 'sqlite') {
            throw new \RuntimeException('Драйвер не поддерживается');
        }
        $path = (string)self::get('db.path', 'storage/database.sqlite');
        if ($path !== '' && ($path[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $path))) {
            return $path;
        }
        if (strncmp($path, 'storage/', 8) === 0) {
            return self::storageDir() . '/' . substr($path, 8);
        }
        return ROOT . '/' . $path;
    }

    /** Определить base_path по SCRIPT_NAME (сайт в подпапке). */
    public static function detectBasePath(): string
    {
        if (PHP_SAPI === 'cli' || empty($_SERVER['SCRIPT_NAME'])) {
            return '';
        }
        $dir = str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME']));
        $dir = preg_replace('~/public$~', '', $dir) ?? '';
        $dir = rtrim($dir, '/');
        return ($dir === '.' || $dir === '/') ? '' : $dir;
    }
}
