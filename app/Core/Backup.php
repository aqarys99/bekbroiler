<?php
declare(strict_types=1);

namespace App\Core;

/** Резервные копии: автокопии базы (раз в сутки, без cron) и архив «база + файлы + config». */
final class Backup
{
    public const KEEP = 14;
    private const NAME_RE = '/^db-\d{8}-\d{6}\.sqlite$/';

    public static function dir(): string
    {
        return Config::storageDir() . '/backups';
    }

    public static function zipAvailable(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    public static function validName(string $name): bool
    {
        return (bool)preg_match(self::NAME_RE, $name);
    }

    /** Согласованный снимок базы в файл $dest (VACUUM INTO, запасной вариант — checkpoint + копия). */
    public static function snapshotDb(string $dest): void
    {
        $pdo = Database::connection();
        @unlink($dest);
        try {
            $pdo->exec('VACUUM INTO ' . $pdo->quote($dest));
            if (is_file($dest) && filesize($dest) > 0) {
                return;
            }
        } catch (\PDOException $e) {
            // SQLite < 3.27 или VACUUM внутри транзакции — копируем файл
        }
        try {
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (\PDOException $e) {
        }
        if (!@copy(Config::dbPath(), $dest)) {
            throw new \RuntimeException('Не удалось создать копию базы');
        }
    }

    /** Создать копию базы в storage/backups; вернуть имя файла. */
    public static function createDbBackup(): string
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Нет папки для копий');
        }
        $name = 'db-' . gmdate('Ymd-His') . '.sqlite';
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(4));
        try {
            self::snapshotDb($tmp);
            if (!@rename($tmp, $dir . '/' . $name)) {
                throw new \RuntimeException('Не удалось сохранить копию');
            }
        } finally {
            @unlink($tmp);
        }
        @chmod($dir . '/' . $name, 0640);
        self::prune();
        return $name;
    }

    /** @return list<array{name:string,size:int,time:int}> новые первыми */
    public static function listDb(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/db-*.sqlite') ?: [] as $f) {
            $n = basename($f);
            if (self::validName($n) && is_file($f)) {
                $out[] = ['name' => $n, 'size' => (int)filesize($f), 'time' => (int)filemtime($f)];
            }
        }
        usort($out, static fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    public static function path(string $name): ?string
    {
        return self::validName($name) && is_file(self::dir() . '/' . $name) ? self::dir() . '/' . $name : null;
    }

    public static function delete(string $name): bool
    {
        $p = self::path($name);
        return $p !== null && @unlink($p);
    }

    /** Оставить только KEEP последних копий. */
    public static function prune(): int
    {
        $n = 0;
        foreach (array_slice(self::listDb(), self::KEEP) as $old) {
            if (@unlink(self::dir() . '/' . $old['name'])) {
                $n++;
            }
        }
        // забытые временные файлы старше суток
        foreach (glob(self::dir() . '/.tmp-*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        return $n;
    }

    /** Автокопия при первом запросе админки за день. Ошибки не мешают работе. */
    public static function maybeAuto(): void
    {
        if (getenv('APP_ENV') === 'testing' && !getenv('APP_BACKUP_AUTO')) {
            return;
        }
        try {
            self::auto();
        } catch (\Throwable $e) {
            Logger::warning('Автокопия базы не создана', ['error' => $e->getMessage()]);
        }
    }

    /** @return ?string имя созданной копии или null, если за сегодня уже есть */
    public static function auto(): ?string
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $today = 'db-' . gmdate('Ymd') . '-';
        if (glob($dir . '/' . $today . '*.sqlite')) {
            return null;
        }
        $lock = @fopen($dir . '/.lock', 'c');
        if ($lock === false) {
            return null;
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return null;
            }
            if (glob($dir . '/' . $today . '*.sqlite')) {
                return null;
            }
            return self::createDbBackup();
        } finally {
            fclose($lock);
        }
    }

    /** Приблизительный размер данных архива в байтах. */
    public static function estimateSize(): int
    {
        $sum = (int)@filesize(Config::dbPath()) + (int)@filesize(Config::configFile());
        $up = DiskUsage::uploadsDir();
        if (is_dir($up)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($up, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $sum += $f->getSize();
                }
            }
        }
        return $sum;
    }

    /** Свободное место, достаточное для сборки архива. */
    public static function enoughSpace(): bool
    {
        $free = @disk_free_space(self::dir());
        return !is_float($free) || $free > self::estimateSize() * 1.1 + 5 * 1048576;
    }

    /**
     * Собрать zip: database.sqlite, uploads/..., config/config.php, README.txt.
     * Возвращает путь к временному файлу (его нужно удалить после отдачи).
     */
    public static function buildArchive(): string
    {
        if (!self::zipAvailable()) {
            throw new \RuntimeException('zip');
        }
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @set_time_limit(0);
        $db = $dir . '/.tmp-' . bin2hex(random_bytes(4)) . '.sqlite';
        $zipPath = $dir . '/.tmp-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            self::snapshotDb($db);
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Не удалось создать архив');
            }
            $zip->addFile($db, 'database.sqlite');
            $cfg = Config::configFile();
            if (is_file($cfg)) {
                $zip->addFile($cfg, 'config/config.php');
            }
            $up = DiskUsage::uploadsDir();
            $store = [];
            if (is_dir($up)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($up, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if (!$f->isFile() || $f->getFilename() === '.gitkeep') {
                        continue;
                    }
                    $rel = 'public/uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($up) + 1));
                    $zip->addFile($f->getPathname(), $rel);
                    $store[] = $rel; // фото/видео/pdf уже сжаты — не тратим процессор
                }
            }
            $zip->addFromString('README.txt', t('backup.readme', ['date' => gmdate('Y-m-d H:i') . ' UTC']));
            if (method_exists($zip, 'setCompressionName')) {
                foreach ($store as $rel) {
                    $zip->setCompressionName($rel, \ZipArchive::CM_STORE);
                }
            }
            if (!$zip->close()) {
                throw new \RuntimeException('Не удалось записать архив');
            }
            return $zipPath;
        } catch (\Throwable $e) {
            @unlink($zipPath);
            throw $e;
        } finally {
            @unlink($db);
        }
    }

    /** Потоковая отдача файла (кусками, без загрузки в память). $deleteAfter — удалить файл после отдачи. */
    public static function stream(string $path, string $downloadName, string $mime, bool $deleteAfter = false): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @set_time_limit(0);
        ignore_user_abort(true);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) . '"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $h = fopen($path, 'rb');
        if ($h !== false) {
            while (!feof($h)) {
                echo fread($h, 1048576);
                flush();
                if (connection_aborted()) {
                    break;
                }
            }
            fclose($h);
        }
        if ($deleteAfter) {
            @unlink($path);
        }
    }
}
