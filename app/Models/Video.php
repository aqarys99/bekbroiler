<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\YouTube;
use App\Media\MediaInUseException;

final class Video
{
    public const TITLE_MAX = 200;

    public static function find(int $id): ?array
    {
        $row = Database::fetch('SELECT * FROM videos WHERE id = ?', [$id]);
        return $row === null ? null : self::decorate([$row])[0];
    }

    /** Все видео для админки по порядку. @return array<int,array> */
    public static function all(): array
    {
        return self::decorate(Database::fetchAll('SELECT * FROM videos ORDER BY sort, id DESC'));
    }

    /** Для публичного сайта (фича 11) и вставки в новости (I-09): показанные и готовые. @return array<int,array> */
    public static function published(): array
    {
        return self::decorate(Database::fetchAll("SELECT * FROM videos WHERE is_published = 1 AND status = 'ready' ORDER BY sort, id DESC"));
    }

    /** Опубликованное готовое видео или null (удалено/скрыто — вставка в новость покажет заглушку). */
    public static function findPublished(int $id): ?array
    {
        $v = self::find($id);
        return $v !== null && (int)$v['is_published'] === 1 && $v['status'] === 'ready' ? $v : null;
    }

    /**
     * Добавляет к строкам: poster_media (строка media|null), poster_url, file_url, youtube_url.
     * @param array<int,array> $rows
     */
    private static function decorate(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            foreach (['media_id', 'poster_media_id'] as $k) {
                if ($r[$k] !== null) {
                    $ids[] = (int)$r[$k];
                }
            }
        }
        $media = Media::findMany($ids);
        foreach ($rows as &$r) {
            $r['poster_media'] = $r['poster_media_id'] !== null ? ($media[(int)$r['poster_media_id']] ?? null) : null;
            $file = $r['media_id'] !== null ? ($media[(int)$r['media_id']] ?? null) : null;
            $r['file_media'] = $file;
            $r['file_url'] = $file !== null ? url('/uploads/' . ltrim((string)$file['path'], '/')) : '';
            $r['youtube_url'] = $r['source'] === 'youtube' && $r['youtube_id'] !== null ? YouTube::watchUrl((string)$r['youtube_id']) : '';
            $r['poster_url'] = self::posterUrl($r);
        }
        unset($r);
        return $rows;
    }

    /** Адрес картинки-обложки: выбранная из медиатеки, иначе кадр YouTube; '' если обложки нет. */
    public static function posterUrl(array $v): string
    {
        $pm = $v['poster_media'] ?? null;
        if (is_array($pm) && media_url($pm, 'medium', 'jpg') !== '') {
            return media_url($pm, 'medium', 'jpg');
        }
        if (($v['source'] ?? '') === 'youtube' && YouTube::validId((string)($v['youtube_id'] ?? ''))) {
            return YouTube::posterUrl((string)$v['youtube_id']);
        }
        return '';
    }

    public static function youtubeExists(string $ytId): bool
    {
        return Database::fetchValue('SELECT 1 FROM videos WHERE youtube_id = ?', [$ytId]) !== null;
    }

    private static function nextTopSort(): int
    {
        return (int)Database::fetchValue('SELECT COALESCE(MIN(sort), 0) FROM videos') - 1;
    }

    public static function createYoutube(string $ytId, string $title, string $titleKk, ?int $userId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return Database::insert('videos', [
            'title' => $title, 'title_kk' => $titleKk, 'source' => 'youtube', 'youtube_id' => $ytId,
            'is_published' => 1, 'sort' => self::nextTopSort(), 'status' => 'ready',
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public static function createUpload(string $title, int $mediaId, ?int $duration, string $status, ?int $userId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return Database::insert('videos', [
            'title' => $title, 'source' => 'upload', 'media_id' => $mediaId, 'duration' => $duration,
            'is_published' => 1, 'sort' => self::nextTopSort(), 'status' => $status,
            'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * Проверка формы редактирования. @return array{data:array,errors:array<string,string>}
     */
    public static function prepare(array $in): array
    {
        $s = static fn(string $k): string => is_string($in[$k] ?? null) ? trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $in[$k]) ?? '') : '';
        $d = ['title' => $s('title'), 'title_kk' => $s('title_kk'), 'is_published' => !empty($in['is_published']) ? 1 : 0];
        $poster = Media::parseIds($in['poster_media_id'] ?? []);
        $d['poster_media_id'] = $poster[0] ?? null;
        $err = [];
        if ($d['title'] === '') {
            $err['title'] = t('videos.error.title_required');
        }
        foreach (['title', 'title_kk'] as $k) {
            if (mb_strlen($d[$k]) > self::TITLE_MAX) {
                $err[$k] = t('videos.error.title_long', ['max' => self::TITLE_MAX]);
            }
        }
        if ($d['poster_media_id'] !== null) {
            $m = Media::find($d['poster_media_id']);
            if ($m === null || $m['type'] !== 'image') {
                $err['poster'] = t('videos.error.poster');
            }
        }
        return ['data' => $d, 'errors' => $err];
    }

    public static function update(int $id, array $d): void
    {
        Database::query(
            'UPDATE videos SET title = ?, title_kk = ?, is_published = ?, poster_media_id = ?, updated_at = ? WHERE id = ?',
            [$d['title'], $d['title_kk'], $d['is_published'], $d['poster_media_id'], gmdate('Y-m-d H:i:s'), $id]
        );
    }

    public static function setPublished(int $id, bool $on): void
    {
        Database::query('UPDATE videos SET is_published = ?, updated_at = ? WHERE id = ?', [$on ? 1 : 0, gmdate('Y-m-d H:i:s'), $id]);
    }

    /** Сдвиг на одну позицию в списке админки. */
    public static function move(int $id, int $dir): bool
    {
        return (bool)Database::immediate(static function () use ($id, $dir): bool {
            $ids = array_map('intval', array_column(Database::fetchAll('SELECT id FROM videos ORDER BY sort, id DESC'), 'id'));
            $i = array_search($id, $ids, true);
            $j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
            if ($i === false || $j < 0 || $j >= count($ids)) {
                return false;
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $pos => $vid) {
                Database::query('UPDATE videos SET sort = ? WHERE id = ?', [$pos, $vid]);
            }
            return true;
        });
    }

    /**
     * Удалить видео и файл ролика (обложка из медиатеки остаётся).
     * @return array|null удалённая строка; null, если её уже нет
     * @throws MediaInUseException
     */
    public static function delete(int $id): ?array
    {
        $row = Database::fetch('SELECT * FROM videos WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        Database::query('DELETE FROM videos WHERE id = ?', [$id]);
        if ($row['media_id'] !== null) {
            try {
                Media::delete((int)$row['media_id']);
            } catch (MediaInUseException) {
                // файл ролика нигде больше не используется; если всё же да — оставляем в медиатеке
            }
        }
        return $row;
    }

    public static function formatDuration(?int $sec): string
    {
        if ($sec === null || $sec <= 0) {
            return '';
        }
        return $sec >= 3600 ? sprintf('%d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60)
            : sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
    }
}
