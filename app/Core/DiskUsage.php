<?php
declare(strict_types=1);

namespace App\Core;

final class DiskUsage
{
    /** @return array{used:int,total:?int,files:int} */
    public static function summary(): array
    {
        $row = Database::fetch('SELECT COALESCE(SUM(size), 0) AS used, COUNT(*) AS files FROM media') ?? [];
        return [
            'used'  => (int)($row['used'] ?? 0),
            'total' => self::total(),
            'files' => (int)($row['files'] ?? 0),
        ];
    }

    /** @return array{image:int,video:int,file:int} байты по типам */
    public static function byType(): array
    {
        $out = ['image' => 0, 'video' => 0, 'file' => 0];
        foreach (Database::fetchAll('SELECT type, COALESCE(SUM(size), 0) AS s FROM media GROUP BY type') as $r) {
            if (isset($out[$r['type']])) {
                $out[$r['type']] = (int)$r['s'];
            }
        }
        return $out;
    }

    private static function total(): ?int
    {
        $quota = \App\Models\Settings::get('disk_quota_mb') ?? config('disk_quota_mb');
        if (is_numeric($quota) && (float)$quota > 0) {
            return (int)round((float)$quota * 1048576);
        }
        $dir = self::uploadsDir();
        $total = is_dir($dir) ? @disk_total_space($dir) : false;
        return is_float($total) && $total > 0 ? (int)$total : null;
    }

    public static function uploadsDir(): string
    {
        return rtrim(getenv('APP_UPLOADS_DIR') ?: ROOT . '/public/uploads', '/');
    }

    /** Строка «Файлов: N · Занято X из Y». */
    public static function line(array $s): string
    {
        $r = ['files' => $s['files'], 'used' => format_bytes((int)$s['used'])];
        if ($s['total'] === null) {
            return t('media.disk.no_total', $r);
        }
        return t('media.disk', $r + ['total' => format_bytes((int)$s['total'])]);
    }
}
