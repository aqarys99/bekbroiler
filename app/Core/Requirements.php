<?php
declare(strict_types=1);

namespace App\Core;

final class Requirements
{
    private const MIN_BYTES = 64 * 1024 * 1024;

    /**
     * @param array{storage?:string,config?:string,uploads?:string}|null $paths подмена каталогов (тесты)
     * @return array<int,array{key:string,label:string,status:string,value:string,hint:string}>
     */
    public static function check(?array $paths = null): array
    {
        $storage = $paths['storage'] ?? Config::storageDir();
        $configDir = $paths['config'] ?? dirname(Config::configFile());
        $uploads = $paths['uploads'] ?? ROOT . '/public/uploads';

        $rows = [];
        $add = static function (string $key, bool $ok, string $value, string $failStatus, string $hintKey) use (&$rows): void {
            $rows[] = [
                'key'    => $key,
                'label'  => t('install.req.' . $key),
                'status' => $ok ? 'ok' : $failStatus,
                'value'  => $value,
                'hint'   => $ok ? '' : t($hintKey),
            ];
        };
        $yn = static fn(bool $b): string => t($b ? 'install.req.yes' : 'install.req.no');
        $wr = static fn(bool $b): string => t($b ? 'install.req.writable' : 'install.req.not_writable');

        $add('php', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION, 'fail', 'install.hint.php');
        foreach (['pdo_sqlite', 'fileinfo', 'mbstring', 'dom'] as $ext) {
            $has = extension_loaded($ext);
            $add($ext, $has, $yn($has), 'fail', 'install.hint.extension');
        }
        $gd = ImageProcessor::available();
        $add('image', $gd, $gd ? 'GD + WebP' : t('install.req.no'), 'fail', 'install.hint.extension');
        $heic = ImageProcessor::heicSupported();
        $add('heic', $heic, $yn($heic), 'warn', 'install.hint.heic');
        $zip = extension_loaded('zip');
        $add('zip', $zip, $yn($zip), 'warn', 'install.hint.extension');

        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $bytes = self::toBytes((string)ini_get($ini));
            $ok = $bytes === 0 || $bytes >= self::MIN_BYTES;
            $add($ini, $ok, $bytes === 0 ? '∞' : (string)round($bytes / 1048576) . ' МБ', 'warn', 'install.hint.limit');
        }

        $okStorage = self::ensureWritable($storage);
        foreach (['logs', 'cache', 'backups'] as $sub) {
            $okStorage = self::ensureWritable($storage . '/' . $sub) && $okStorage;
        }
        $add('writable_storage', $okStorage, $wr($okStorage), 'fail', 'install.hint.writable');
        $okConfig = self::ensureWritable($configDir);
        $add('writable_config', $okConfig, $wr($okConfig), 'fail', 'install.hint.writable');
        $okUploads = self::ensureWritable($uploads);
        $add('writable_uploads', $okUploads, $wr($okUploads), 'fail', 'install.hint.writable');

        $save = (string)ini_get('session.save_path');
        $save = $save !== '' ? (string)preg_replace('/^\d+;(?:0?\d+;)?/', '', $save) : sys_get_temp_dir();
        $okSessions = is_dir($save) && is_writable($save);
        $add('writable_sessions', $okSessions, $wr($okSessions), 'warn', 'install.hint.sessions');

        // Подмена статусов — только в тестовом окружении
        if (getenv('APP_ENV') === 'testing') {
            $fake = $storage . '/fake-requirements.json';
            if (is_file($fake)) {
                $over = json_decode((string)file_get_contents($fake), true);
                if (is_array($over)) {
                    foreach ($rows as &$row) {
                        if (isset($over[$row['key']]) && in_array($over[$row['key']], ['ok', 'warn', 'fail'], true)) {
                            $row['status'] = $over[$row['key']];
                            if ($row['status'] !== 'ok' && $row['hint'] === '') {
                                $row['hint'] = t(self::hintFor($row['key']));
                            }
                        }
                    }
                    unset($row);
                }
            }
        }
        return $rows;
    }

    public static function hasFail(array $rows): bool
    {
        foreach ($rows as $r) {
            if ($r['status'] === 'fail') {
                return true;
            }
        }
        return false;
    }

    private static function hintFor(string $key): string
    {
        return match (true) {
            $key === 'php' => 'install.hint.php',
            str_starts_with($key, 'writable_') => 'install.hint.writable',
            in_array($key, ['upload_max_filesize', 'post_max_size'], true) => 'install.hint.limit',
            default => 'install.hint.extension',
        };
    }

    private static function ensureWritable(string $dir): bool
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) && is_writable($dir);
    }

    private static function toBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
