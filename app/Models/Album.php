<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Slug;

final class Album
{
    public const TITLE_MAX = 120;
    public const DESC_MAX = 1000;
    public const CAPTION_MAX = 200;

    private const NOW = "strftime('%Y-%m-%d %H:%M:%S','now')";

    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM albums WHERE id = ?', [$id]);
    }

    /** Альбомы для админки: обложка (строка media) и число фото. @return array<int,array> */
    public static function all(): array
    {
        $rows = Database::fetchAll(
            'SELECT a.*, (SELECT COUNT(*) FROM album_media am WHERE am.album_id = a.id) AS photo_count
             FROM albums a ORDER BY COALESCE(a.event_date, substr(a.created_at, 1, 10)) DESC, a.id DESC'
        );
        return self::withCovers($rows);
    }

    /** @param array<int,array> $rows */
    private static function withCovers(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $cid = self::coverId((int)$r['id'], $r['cover_media_id'] !== null ? (int)$r['cover_media_id'] : null);
            $ids[(int)$r['id']] = $cid;
        }
        $media = Media::findMany(array_values(array_filter($ids)));
        foreach ($rows as &$r) {
            $cid = $ids[(int)$r['id']] ?? null;
            $r['cover'] = $cid !== null ? ($media[$cid] ?? null) : null;
        }
        unset($r);
        return $rows;
    }

    /** Обложка: выбранная, если фото ещё в альбоме; иначе первое фото. */
    private static function coverId(int $albumId, ?int $cover): ?int
    {
        if ($cover !== null && Database::fetchValue('SELECT 1 FROM album_media WHERE album_id = ? AND media_id = ?', [$albumId, $cover])) {
            return $cover;
        }
        $first = Database::fetchValue('SELECT media_id FROM album_media WHERE album_id = ? ORDER BY sort, media_id LIMIT 1', [$albumId]);
        return $first !== null && $first !== false ? (int)$first : null;
    }

    /** Фото альбома по порядку: строки media + caption, sort. @return array<int,array> */
    public static function photos(int $albumId): array
    {
        return Database::fetchAll(
            'SELECT m.*, am.caption, am.sort AS album_sort FROM album_media am JOIN media m ON m.id = am.media_id
             WHERE am.album_id = ? ORDER BY am.sort, m.id',
            [$albumId]
        );
    }

    /** Для публичного сайта (фича 11): опубликованные альбомы, у каждого cover и photos по порядку (I-08). */
    public static function published(): array
    {
        $rows = Database::fetchAll(
            'SELECT a.*, (SELECT COUNT(*) FROM album_media am WHERE am.album_id = a.id) AS photo_count
             FROM albums a WHERE a.is_published = 1
             ORDER BY COALESCE(a.event_date, substr(a.created_at, 1, 10)) DESC, a.id DESC'
        );
        $rows = self::withCovers($rows);
        foreach ($rows as &$r) {
            $r['photos'] = self::photos((int)$r['id']);
        }
        unset($r);
        return $rows;
    }

    /** Один опубликованный альбом по slug (для страницы альбома на сайте). */
    public static function publishedBySlug(string $slug): ?array
    {
        foreach (self::published() as $a) {
            if ($a['slug'] === $slug) {
                return $a;
            }
        }
        return null;
    }

    /**
     * Проверка полей. @return array{data:array,errors:array<string,string>}
     */
    public static function prepare(array $in): array
    {
        $str = static fn(string $k): string => trim(is_string($in[$k] ?? null) ? (string)$in[$k] : '');
        $d = [
            'title' => $str('title'), 'title_kk' => $str('title_kk'),
            'description' => $str('description'), 'description_kk' => $str('description_kk'),
            'event_date' => $str('event_date'),
            'is_published' => !empty($in['is_published']) ? 1 : 0,
        ];
        $err = [];
        if ($d['title'] === '') {
            $err['title'] = t('gallery.error.title_required');
        }
        foreach (['title', 'title_kk'] as $k) {
            if (mb_strlen($d[$k]) > self::TITLE_MAX) {
                $err[$k] = t('gallery.error.title_long', ['max' => self::TITLE_MAX]);
            }
        }
        foreach (['description', 'description_kk'] as $k) {
            if (mb_strlen($d[$k]) > self::DESC_MAX) {
                $err[$k] = t('gallery.error.desc_long', ['max' => self::DESC_MAX]);
            }
        }
        if ($d['event_date'] !== '') {
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d['event_date']);
            if (!$dt || $dt->format('Y-m-d') !== $d['event_date']) {
                $err['event_date'] = t('gallery.error.date');
            }
        }
        $d['event_date'] = $d['event_date'] === '' ? null : $d['event_date'];
        return ['data' => $d, 'errors' => $err];
    }

    public static function uniqueSlug(string $title, ?int $exceptId = null): string
    {
        $base = Slug::make($title);
        $base = $base === 'novost' ? 'album' : $base;
        $slug = $base;
        for ($i = 2; Database::fetchValue('SELECT 1 FROM albums WHERE slug = ? AND id <> ?', [$slug, $exceptId ?? 0]); $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    public static function create(array $data, ?int $userId): int
    {
        return Database::insert('albums', [
            'slug' => self::uniqueSlug($data['title']),
            'title' => $data['title'], 'title_kk' => $data['title_kk'],
            'description' => $data['description'], 'description_kk' => $data['description_kk'],
            'event_date' => $data['event_date'], 'is_published' => $data['is_published'],
            'sort' => (int)Database::fetchValue('SELECT COALESCE(MAX(sort), 0) + 1 FROM albums'),
            'created_by' => $userId,
        ]);
    }

    public static function update(int $id, array $data): void
    {
        Database::query(
            'UPDATE albums SET title = ?, title_kk = ?, description = ?, description_kk = ?, event_date = ?, is_published = ?, updated_at = ' . self::NOW . ' WHERE id = ?',
            [$data['title'], $data['title_kk'], $data['description'], $data['description_kk'], $data['event_date'], $data['is_published'], $id]
        );
    }

    public static function setPublished(int $id, bool $on): void
    {
        Database::query('UPDATE albums SET is_published = ?, updated_at = ' . self::NOW . ' WHERE id = ?', [$on ? 1 : 0, $id]);
    }

    /** Удаление альбома: фото остаются в медиатеке (строки album_media уходят каскадом). */
    public static function delete(int $id): ?array
    {
        return Database::immediate(function () use ($id): ?array {
            $row = self::find($id);
            if ($row === null) {
                return null;
            }
            Database::query('DELETE FROM album_media WHERE album_id = ?', [$id]);
            Database::query('DELETE FROM albums WHERE id = ?', [$id]);
            return $row;
        });
    }

    /**
     * Добавить фото (только type=image, без дублей) в конец. Первое фото становится обложкой.
     * @param int[] $mediaIds
     * @return int[] id реально добавленных
     */
    public static function addMedia(int $albumId, array $mediaIds): array
    {
        return Database::immediate(function () use ($albumId, $mediaIds): array {
            $album = self::find($albumId);
            if ($album === null) {
                return [];
            }
            $rows = Media::findMany($mediaIds);
            $sort = (int)Database::fetchValue('SELECT COALESCE(MAX(sort), 0) FROM album_media WHERE album_id = ?', [$albumId]);
            $added = [];
            foreach ($mediaIds as $mid) {
                $m = $rows[$mid] ?? null;
                if ($m === null || $m['type'] !== 'image') {
                    continue;
                }
                if (Database::fetchValue('SELECT 1 FROM album_media WHERE album_id = ? AND media_id = ?', [$albumId, $mid])) {
                    continue;
                }
                Database::insert('album_media', ['album_id' => $albumId, 'media_id' => $mid, 'sort' => ++$sort, 'caption' => '']);
                $added[] = $mid;
            }
            if ($added && $album['cover_media_id'] === null) {
                Database::query('UPDATE albums SET cover_media_id = ? WHERE id = ?', [$added[0], $albumId]);
            }
            return $added;
        });
    }

    public static function hasMedia(int $albumId, int $mediaId): bool
    {
        return (bool)Database::fetchValue('SELECT 1 FROM album_media WHERE album_id = ? AND media_id = ?', [$albumId, $mediaId]);
    }

    /** Убрать фото из альбома (в медиатеке остаётся). Если было обложкой — обложкой станет первое оставшееся. */
    public static function removeMedia(int $albumId, int $mediaId): bool
    {
        return Database::immediate(function () use ($albumId, $mediaId): bool {
            if (!self::hasMedia($albumId, $mediaId)) {
                return false;
            }
            Database::query('DELETE FROM album_media WHERE album_id = ? AND media_id = ?', [$albumId, $mediaId]);
            $cover = Database::fetchValue('SELECT cover_media_id FROM albums WHERE id = ?', [$albumId]);
            if ($cover !== null && (int)$cover === $mediaId) {
                $first = Database::fetchValue('SELECT media_id FROM album_media WHERE album_id = ? ORDER BY sort, media_id LIMIT 1', [$albumId]);
                Database::query('UPDATE albums SET cover_media_id = ? WHERE id = ?', [$first !== null && $first !== false ? (int)$first : null, $albumId]);
            }
            return true;
        });
    }

    public static function setCover(int $albumId, int $mediaId): bool
    {
        if (!self::hasMedia($albumId, $mediaId)) {
            return false;
        }
        Database::query('UPDATE albums SET cover_media_id = ?, updated_at = ' . self::NOW . ' WHERE id = ?', [$mediaId, $albumId]);
        return true;
    }

    public static function setCaption(int $albumId, int $mediaId, string $caption): bool
    {
        if (!self::hasMedia($albumId, $mediaId)) {
            return false;
        }
        Database::query('UPDATE album_media SET caption = ? WHERE album_id = ? AND media_id = ?', [$caption, $albumId, $mediaId]);
        return true;
    }

    /** Сдвиг на одну позицию: -1 влево, +1 вправо. */
    public static function move(int $albumId, int $mediaId, int $dir): bool
    {
        return Database::immediate(function () use ($albumId, $mediaId, $dir): bool {
            $ids = array_map(static fn(array $r): int => (int)$r['media_id'],
                Database::fetchAll('SELECT media_id FROM album_media WHERE album_id = ? ORDER BY sort, media_id', [$albumId]));
            $i = array_search($mediaId, $ids, true);
            $j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
            if ($i === false || $j < 0 || $j >= count($ids)) {
                return false;
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            self::writeOrder($albumId, $ids);
            return true;
        });
    }

    /** Новый порядок (перетаскивание): неизвестные id игнорируются, пропущенные остаются в конце. @param int[] $order */
    public static function reorder(int $albumId, array $order): void
    {
        Database::immediate(function () use ($albumId, $order): void {
            $cur = array_map(static fn(array $r): int => (int)$r['media_id'],
                Database::fetchAll('SELECT media_id FROM album_media WHERE album_id = ? ORDER BY sort, media_id', [$albumId]));
            $new = array_values(array_intersect($order, $cur));
            self::writeOrder($albumId, [...$new, ...array_values(array_diff($cur, $new))]);
        });
    }

    /** @param int[] $ids */
    private static function writeOrder(int $albumId, array $ids): void
    {
        foreach ($ids as $pos => $mid) {
            Database::query('UPDATE album_media SET sort = ? WHERE album_id = ? AND media_id = ?', [$pos + 1, $albumId, $mid]);
        }
    }

    /** Текущая обложка (id media) или null. */
    public static function effectiveCover(array $album): ?int
    {
        return self::coverId((int)$album['id'], $album['cover_media_id'] !== null ? (int)$album['cover_media_id'] : null);
    }
}
