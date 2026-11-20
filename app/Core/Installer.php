<?php
declare(strict_types=1);

namespace App\Core;

final class Installer
{
    public static function lockFile(): string
    {
        return Config::storageDir() . '/install.lock';
    }

    public static function isInstalled(): bool
    {
        return is_file(self::lockFile());
    }

    /**
     * @param array{site_name?:mixed,owner_name?:mixed,login?:mixed,password?:mixed,password_confirm?:mixed,base_path?:string} $input
     * @return int id владельца
     */
    public static function install(array $input): int
    {
        $storage = Config::storageDir();
        if (!is_dir($storage)) {
            @mkdir($storage, 0775, true);
        }
        $tmp = @fopen($storage . '/install.lock.tmp', 'c');
        if ($tmp === false || !flock($tmp, LOCK_EX | LOCK_NB)) {
            if (is_resource($tmp)) {
                fclose($tmp);
            }
            throw new AlreadyInstalledException();
        }
        try {
            if (self::isInstalled()) {
                throw new AlreadyInstalledException();
            }
            return self::run($input);
        } finally {
            flock($tmp, LOCK_UN);
            fclose($tmp);
            @unlink($storage . '/install.lock.tmp');
        }
    }

    private static function run(array $input): int
    {
        if (Requirements::hasFail(Requirements::check())) {
            throw new InstallException('install.error.requirements', 422);
        }
        $data = self::validate($input);

        $dbPath = Config::dbPath();
        $configFile = Config::configFile();
        $dbExisted = is_file($dbPath);
        $configExisted = is_file($configFile);
        $basePath = isset($input['base_path']) ? (string)$input['base_path'] : Config::detectBasePath();

        try {
            Database::setConnection(null);
            $pdo = Database::connection();
            $migrations = (new Migrator($pdo))->migrate();

            if ((int)Database::fetchValue("SELECT COUNT(*) FROM users WHERE role = 'owner'") > 0) {
                throw new InstallException('install.error.owner_exists', 422);
            }

            $ownerId = (int)Database::transaction(static function () use ($data): int {
                $now = gmdate('Y-m-d H:i:s');
                $id = Database::insert('users', [
                    'name'          => $data['owner_name'],
                    'login'         => $data['login'],
                    'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                    'role'          => 'owner',
                    'is_active'     => 1,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
                Database::insert('settings', [
                    'key'   => 'site_name',
                    'value' => json_encode($data['site_name'], JSON_UNESCAPED_UNICODE),
                ]);
                Audit::log($id, 'create', 'user', $id);
                return $id;
            });

            self::writeConfig($configFile, $basePath);
            self::writeFile(self::lockFile(), json_encode([
                'installed_at' => gmdate('Y-m-d H:i:s'),
                'migrations'   => $migrations,
            ], JSON_UNESCAPED_UNICODE));
            Config::set(['base_path' => $basePath]);
            return $ownerId;
        } catch (InstallException $e) {
            self::cleanup($dbPath, $configFile, !$dbExisted, !$configExisted);
            throw $e;
        } catch (\Throwable $e) {
            Logger::error('Установка не удалась: ' . $e->getMessage(), [
                'at'    => $e->getFile() . ':' . $e->getLine(),
                'trace' => ErrorHandler::formatTrace($e),
            ]);
            self::cleanup($dbPath, $configFile, !$dbExisted, !$configExisted);
            throw new InstallException('install.error.failed', 500, $e);
        }
    }

    private static function cleanup(string $dbPath, string $configFile, bool $removeDb, bool $removeConfig): void
    {
        Database::setConnection(null);
        if ($removeDb) {
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
        if ($removeConfig && is_file($configFile)) {
            @unlink($configFile);
        }
        if (is_file(self::lockFile())) {
            @unlink(self::lockFile());
        }
    }

    /** @return array{site_name:string,owner_name:string,login:string,password:string} */
    private static function validate(array $in): array
    {
        $clean = static fn($v): string => trim((string)preg_replace('/\s+/u', ' ', trim((string)($v ?? ''))));
        $errors = [];

        $site = $clean($in['site_name'] ?? '');
        $len = mb_strlen($site);
        if ($site === '') {
            $errors['site_name'] = 'validation.site_name.required';
        } elseif ($len < 2 || $len > 150) {
            $errors['site_name'] = 'validation.site_name.length';
        }

        $name = $clean($in['owner_name'] ?? '');
        if ($name === '') {
            $errors['owner_name'] = 'validation.owner_name.required';
        } elseif (mb_strlen($name) > 100) {
            $errors['owner_name'] = 'validation.owner_name.length';
        }

        $login = mb_strtolower(trim((string)($in['login'] ?? '')));
        $ll = mb_strlen($login);
        if ($login === '') {
            $errors['login'] = 'validation.login.required';
        } elseif ($ll < 3 || $ll > 50) {
            $errors['login'] = 'validation.login.length';
        } elseif (!preg_match('/^[a-z0-9._-]+$/', $login)) {
            $errors['login'] = 'validation.login.format';
        }

        $pass = (string)($in['password'] ?? '');
        if ($pass === '') {
            $errors['password'] = 'validation.password.required';
        } elseif (mb_strlen($pass) < 8) {
            $errors['password'] = 'validation.password.min';
        } elseif (mb_strlen($pass) > 64 || strlen($pass) > 72) {
            $errors['password'] = 'validation.password.max';
        } elseif ($pass !== (string)($in['password_confirm'] ?? '')) {
            $errors['password_confirm'] = 'validation.password.confirm';
        }

        if ($errors) {
            throw new ValidationException($errors);
        }
        return ['site_name' => $site, 'owner_name' => $name, 'login' => $login, 'password' => $pass];
    }

    private static function writeConfig(string $file, string $basePath): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Не удалось создать каталог конфига');
        }
        $config = array_replace_recursive(Config::defaults(), [
            'env'       => 'production',
            'debug'     => false,
            'base_path' => $basePath,
        ]);
        $php = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        self::writeFile($file, $php);
    }

    /** Атомарная запись: временный файл + rename, права 0640 (при проблемах чтения — 0644). */
    private static function writeFile(string $file, string $content): void
    {
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать файл');
        }
        @chmod($tmp, 0640);
        if (!is_readable($tmp)) {
            @chmod($tmp, 0644);
            Logger::warning('Права 0640 недоступны, использованы 0644', ['file' => basename($file)]);
        }
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Не удалось сохранить файл');
        }
    }
}
