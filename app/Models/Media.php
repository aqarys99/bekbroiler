<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\DiskUsage;
use App\Core\Guard;
use App\Core\Logger;
use App\Media\MediaInUseException;
use App\Media\UsageCheckException;
use App\Media\UsageProvider;

final class Media
{
    public const TYPES = ['image', 'video', 'file'];

    /** @var array<int,UsageProvider|string>|null подмена списка потребителей (тесты) */
    private static ?array $providers = null;

    public static function setProviders(?array $providers): void
    {
        self::$providers = $providers;
    }

    /** Действующий лимит одного файла, байт: min(конфиг, upload_max_filesize, post_max_size). */
    public static function maxUploadBytes(): int
    {
        $limits = [(int)round((float)config('media.max_upload_mb', 20) * 1048576)];
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $b = Guard::iniBytes((string)ini_get($k));
            if ($b > 0) {
                $limits[] = $b;
            }
        }
        return max(1, min($limits));
    }

    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM media WHERE id = ?', [$id]);
    }

    /** @param array<int,int> $ids @return array<int,array> [id => row] */
    public static function findMany(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $rows = Database::fetchAll(
            'SELECT * FROM media WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['id']] = $r;
        }
        return $out;
    }

    /**
     * Типы для фильтра: «all» + реально поддерживаемые сейчас (фото) + те, что уже есть в базе.
     * @return string[]
     */
    public static function availableTypes(): array
    {
        $present = array_column(Database::fetchAll('SELECT DISTINCT type FROM media'), 'type');
        $types = ['all', 'image'];
        foreach (self::TYPES as $t) {
            if ($t !== 'image' && in_array($t, $present, true)) {
                $types[] = $t;
            }
        }
        return $types;
    }

    /** Неизвестный или недоступный тип -> 'all'. */
    public static function normalizeType(mixed $type): string
    {
        return is_string($type) && in_array($type, self::availableTypes(), true) ? $type : 'all';
    }

    public static function cleanQuery(mixed $q): string
    {
        $q = is_string($q) ? trim($q) : '';
        return trim(mb_substr($q, 0, 100));
    }

    /**
     * @return array{items:array<int,array>,total:int,page:int,pages:int,per_page:int}
     */
    public static function list(string $q = '', string $type = 'all', int $page = 1, ?int $perPage = null): array
    {
        $perPage ??= max(1, (int)config('media.per_page', 48));
        $q = self::cleanQuery($q);
        $where = [];
        $params = [];
        if ($type !== 'all' && in_array($type, self::TYPES, true)) {
            $where[] = 'type = ?';
            $params[] = $type;
        }
        if ($q !== '') {
            self::registerLower();
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q, 'UTF-8')) . '%';
            $where[] = "(php_lower(original_name) LIKE ? ESCAPE '\\' OR php_lower(alt) LIKE ? ESCAPE '\\')";
            $params[] = $like;
            $params[] = $like;
        }
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int)Database::fetchValue("SELECT COUNT(*) FROM media $w", $params);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $items = Database::fetchAll(
            "SELECT * FROM media $w ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
            $params
        );
        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    private static function registerLower(): void
    {
        $pdo = Database::connection();
        $pdo->sqliteCreateFunction('php_lower', static fn($s) => mb_strtolower((string)$s, 'UTF-8'), 1);
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return Database::insert('media', $data + [
            'status' => 'ready', 'alt' => '', 'alt_kk' => '', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public static function updateAlt(int $id, string $alt): void
    {
        Database::query('UPDATE media SET alt = ?, updated_at = ? WHERE id = ?', [$alt, gmdate('Y-m-d H:i:s'), $id]);
    }

    /** @return UsageProvider[] */
    private static function providers(): array
    {
        $list = self::$providers ?? (array)require ROOT . '/app/media_usages.php';
        $out = [];
        foreach ($list as $p) {
            $out[] = $p instanceof UsageProvider ? $p : new $p();
        }
        return $out;
    }

    /**
     * Все места, где файл используется.
     * @return array<int,array{source:string,label:string,url:string}>
     * @throws UsageCheckException если потребитель не смог ответить
     */
    public static function usages(int $id): array
    {
        return self::usagesFor([$id])[$id] ?? [];
    }

    /** @return array<int,array<int,array{source:string,label:string,url:string}>> */
    private static function usagesFor(array $ids): array
    {
        $out = [];
        foreach (self::providers() as $p) {
            try {
                $found = $p->find($ids);
            } catch (\Throwable $e) {
                Logger::error('Потребитель медиа не ответил', ['provider' => $p->key(), 'error' => $e->getMessage()]);
                throw new UsageCheckException($p->key(), 0, $e);
            }
            foreach ($found as $mid => $list) {
                foreach ($list as $u) {
                    $out[(int)$mid][] = ['source' => $p->key(), 'label' => (string)$u['label'], 'url' => (string)$u['url']];
                }
            }
        }
        return $out;
    }

    /**
     * Число использований для значка в сетке. Сбой потребителя -> считаем используемым.
     * @param int[] $ids
     * @return array<int,int>
     */
    public static function usageCounts(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $counts = array_fill_keys($ids, 0);
        if (!$ids) {
            return $counts;
        }
        try {
            foreach (self::usagesFor($ids) as $mid => $list) {
                if (isset($counts[$mid])) {
                    $counts[$mid] = count($list);
                }
            }
        } catch (UsageCheckException) {
            return array_fill_keys($ids, 1);
        }
        return $counts;
    }

    /**
     * Удалить запись и файлы, если файл нигде не используется.
     * @param callable|null $inTransaction вызывается внутри транзакции после удаления строки (аудит)
     * @return array|null удалённая строка; null, если её уже нет
     * @throws MediaInUseException
     * @throws UsageCheckException
     */
    public static function delete(int $id, ?callable $inTransaction = null): ?array
    {
        $row = Database::immediate(function () use ($id, $inTransaction): ?array {
            $row = self::find($id);
            if ($row === null) {
                return null;
            }
            $usages = self::usages($id);
            if ($usages) {
                throw new MediaInUseException($usages);
            }
            try {
                Database::query('DELETE FROM media WHERE id = ?', [$id]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[0] ?? $e->getCode()) == '23000') {
                    throw new MediaInUseException([]);
                }
                throw $e;
            }
            if ($inTransaction !== null) {
                $inTransaction($row);
            }
            return $row;
        });
        if ($row !== null) {
            self::deleteFiles($row);
        }
        return $row;
    }

    /** Файлы всех вариантов; отсутствующий файл — не ошибка. */
    public static function deleteFiles(array $row): void
    {
        $root = DiskUsage::uploadsDir();
        foreach (self::filePaths($row) as $rel) {
            $file = $root . '/' . $rel;
            if (!is_file($file)) {
                Logger::warning('Файл медиа уже отсутствует на диске', ['path' => $rel]);
                continue;
            }
            if (!@unlink($file)) {
                Logger::warning('Не удалось удалить файл медиа', ['path' => $rel]);
            }
        }
    }

    /** @return string[] относительные пути всех файлов записи (только безопасные) */
    public static function filePaths(array $row): array
    {
        $paths = [];
        foreach (media_variants($row) as $v) {
            foreach (['webp', 'jpg'] as $f) {
                $p = $v[$f] ?? null;
                if (is_string($p) && $p !== '' && !str_contains($p, '..') && $p[0] !== '/' && !str_contains($p, "\0")) {
                    $paths[] = $p;
                }
            }
        }
        return $paths;
    }

    /** Элемент JSON для пикера и ответа загрузки. */
    public static function pickerItem(array $row): array
    {
        return [
            'id'            => (int)$row['id'],
            'original_name' => (string)$row['original_name'],
            'alt'           => (string)$row['alt'],
            'width'         => $row['width'] !== null ? (int)$row['width'] : null,
            'height'        => $row['height'] !== null ? (int)$row['height'] : null,
            'thumb_webp'    => media_url($row, 'thumb', 'webp'),
            'thumb_jpg'     => media_url($row, 'thumb', 'jpg'),
        ];
    }

    /** Список id из формы: положительные целые, без дублей, порядок сохраняется. @return int[] */
    public static function parseIds(mixed $value): array
    {
        $values = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
        $out = [];
        foreach ($values as $v) {
            if ((is_string($v) || is_int($v)) && preg_match('/^\d{1,12}$/', (string)$v) && (int)$v > 0) {
                $out[(int)$v] = (int)$v;
            }
        }
        return array_values($out);
    }

    /**
     * Значения поля выбора в порядке выбора: media = null, если файл удалён.
     * @param int[] $ids
     * @return array<int,array{id:int,media:?array}>
     */
    public static function pickerValues(array $ids): array
    {
        $rows = self::findMany($ids);
        return array_map(static fn(int $id): array => ['id' => $id, 'media' => $rows[$id] ?? null], $ids);
    }
}
