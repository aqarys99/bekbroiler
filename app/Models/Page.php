<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\Slug;

/** Страницы сайта: создаются, правятся и удаляются в админке (как в WordPress). */
final class Page
{
    public const BODY_MAX = 60000;
    public const SCHEDULE_MAX = 30;
    /** Адреса, занятые маршрутами сайта и служебными папками. */
    public const RESERVED = ['kk', 'admin', 'theme', 'themes', 'uploads', 'assets', 'storage', 'install', 'novosti', 'pedagogi', 'gruppy',
        'fotogalereya', 'video', 'dokumenty', 'dostizheniya', 'otzyvy', 'kontakty', 'sitemap', 'robots', 'public', 'app', 'vendor'];

    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM pages WHERE id = ?', [$id]);
    }

    public static function all(): array
    {
        return Database::fetchAll('SELECT * FROM pages ORDER BY sort, id');
    }

    /** Список для админки: поиск по названию/адресу, статус (all|shown|hidden), число документов. */
    public static function search(string $q = '', string $status = 'all'): array
    {
        $where = ["p.kind = 'regular'"];
        $args = [];
        if ($q !== '') {
            Database::registerLower();
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%';
            $where[] = "(php_lower(p.title) LIKE ? ESCAPE '\\' OR php_lower(p.title_kk) LIKE ? ESCAPE '\\' OR p.slug LIKE ? ESCAPE '\\')";
            array_push($args, $like, $like, $like);
        }
        if ($status === 'shown' || $status === 'hidden') {
            $where[] = 'p.is_published = ' . ($status === 'shown' ? '1' : '0');
        }
        return Database::fetchAll(
            'SELECT p.*, (SELECT COUNT(*) FROM documents d WHERE d.page_id = p.id) AS docs_count FROM pages p'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY p.sort, p.id',
            $args
        );
    }

    public static function slugTaken(string $slug, ?int $exceptId = null): bool
    {
        return in_array($slug, self::RESERVED, true) || $slug === trim((string)config('admin_path', 'admin'), '/')
            || Database::fetchValue('SELECT 1 FROM pages WHERE slug = ? AND id != ?', [$slug, $exceptId ?? 0]) !== null;
    }

    /** Свободный адрес на основе slug: «-2», «-3»… */
    public static function uniqueSlug(string $slug, ?int $exceptId = null): string
    {
        $base = $slug = $slug === '' ? 'stranitsa' : $slug;
        for ($i = 2; self::slugTaken($slug, $exceptId) && $i < 1000; $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    public static function slugFromTitle(string $title): string
    {
        $s = Slug::make($title, 'stranitsa');
        return $s;
    }

    /** Страницы для выбора (меню, документы). */
    public static function options(): array
    {
        return Database::fetchAll('SELECT id, slug, title, title_kk, is_published, kind FROM pages ORDER BY sort, id');
    }

    public static function isAttestation(?array $row): bool
    {
        return $row !== null && ($row['kind'] ?? 'regular') === 'attestation';
    }

    /** Экран страницы в админке: «Аттестация» или «Страницы». */
    public static function adminUrl(array $row): string
    {
        return admin_url((self::isAttestation($row) ? '/attestation/' : '/pages/') . (int)$row['id']);
    }

    public static function create(array $data): int
    {
        return (int)Database::immediate(static function () use ($data): int {
            $sort = (int)Database::fetchValue('SELECT COALESCE(MAX(sort), 0) FROM pages') + 1;
            return Database::insert('pages', [
                'slug' => $data['slug'], 'title' => $data['title'], 'title_kk' => $data['title_kk'],
                'body' => $data['body'], 'body_kk' => $data['body_kk'], 'is_published' => $data['is_published'], 'sort' => $sort,
                'kind' => ($data['kind'] ?? 'regular') === 'attestation' ? 'attestation' : 'regular',
            ]);
        });
    }

    /**
     * Удалить страницу: пункты меню убираются (подпункты поднимаются на верхний уровень),
     * документы удаляются вместе со страницей, их файлы освобождаются в медиатеке.
     * @param callable|null $audit вызывается внутри транзакции со строкой страницы
     */
    public static function delete(int $id, ?callable $audit = null): ?array
    {
        $mediaIds = [];
        $row = Database::immediate(static function () use ($id, $audit, &$mediaIds): ?array {
            $row = self::find($id);
            if ($row === null) {
                return null;
            }
            $mediaIds = array_map('intval', array_column(Database::fetchAll('SELECT media_id FROM documents WHERE page_id = ?', [$id]), 'media_id'));
            MenuItem::detachPage($id);
            Database::query('DELETE FROM pages WHERE id = ?', [$id]);
            if ($audit !== null) {
                $audit($row);
            }
            return $row;
        });
        foreach ($mediaIds as $mid) {
            Document::dropMedia($mid);
        }
        return $row;
    }

    /** Для сайта (фича 11): показанная страница; у «rezhim» добавлен schedule. */
    public static function published(string $slug): ?array
    {
        $p = Database::fetch('SELECT * FROM pages WHERE slug = ? AND is_published = 1', [$slug]);
        if ($p === null) {
            return null;
        }
        if (!empty($p['has_schedule'])) {
            $p['schedule'] = self::schedule();
        }
        return $p;
    }

    /** Для сайта: показанные страницы (slug, title, title_kk) по порядку — меню и карта сайта. */
    public static function publishedList(): array
    {
        return Database::fetchAll('SELECT id, slug, title, title_kk, updated_at FROM pages WHERE is_published = 1 ORDER BY sort, id');
    }

    public static function schedule(): array
    {
        return Database::fetchAll('SELECT * FROM schedule_items ORDER BY sort, id');
    }

    /** @return array{data:array,errors:array<string,string>,schedule:array,schedule_errors:array<int,string>} */
    public static function prepare(array $in, bool $withSchedule, ?int $id = null): array
    {
        $err = [];
        $str = static fn(string $k): string => trim(is_string($in[$k] ?? null) ? (string)$in[$k] : '');
        $d = ['title' => $str('title'), 'title_kk' => $str('title_kk')];
        if ($d['title'] === '') {
            $err['title'] = t('pages.error.title');
        }
        foreach (['title', 'title_kk'] as $k) {
            if (mb_strlen($d[$k]) > 150) {
                $err[$k] = t('pages.error.title_long');
            }
        }
        foreach (['body', 'body_kk'] as $k) {
            $raw = $str($k);
            if (strlen($raw) > self::BODY_MAX * 3) {
                $err[$k] = t('pages.error.body_long');
                $d[$k] = '';
                continue;
            }
            $d[$k] = HtmlSanitizer::clean($raw);
            if (strlen($d[$k]) > self::BODY_MAX) {
                $err[$k] = t('pages.error.body_long');
            }
        }
        $d['is_published'] = !empty($in['is_published']) ? 1 : 0;
        $slug = strtolower($str('slug'));
        if ($slug === '') {
            $slug = self::uniqueSlug(self::slugFromTitle($d['title']), $id);
        } elseif (strlen($slug) > Slug::MAX || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            $err['slug'] = t('pages.error.slug');
        } elseif (self::slugTaken($slug, $id)) {
            $err['slug'] = t('pages.error.slug_taken');
        }
        $d['slug'] = $slug;

        $rows = [];
        $rowErr = [];
        if ($withSchedule) {
            $times = (array)($in['sched_time'] ?? []);
            $texts = (array)($in['sched_text'] ?? []);
            $kks = (array)($in['sched_text_kk'] ?? []);
            foreach ($times as $i => $time) {
                $t1 = trim(is_string($time) ? $time : '');
                $t2 = trim(is_string($texts[$i] ?? null) ? $texts[$i] : '');
                $t3 = trim(is_string($kks[$i] ?? null) ? $kks[$i] : '');
                if ($t1 === '' && $t2 === '' && $t3 === '') {
                    continue;
                }
                $n = count($rows);
                if ($t1 === '' || $t2 === '') {
                    $rowErr[$n] = t('pages.error.schedule_row');
                } elseif (mb_strlen($t1) > 30 || mb_strlen($t2) > 200 || mb_strlen($t3) > 200) {
                    $rowErr[$n] = t('pages.error.schedule_long');
                }
                $rows[] = ['time_range' => $t1, 'text' => $t2, 'text_kk' => $t3];
            }
            if (count($rows) > self::SCHEDULE_MAX) {
                $err['schedule'] = t('pages.error.schedule_many', ['max' => self::SCHEDULE_MAX]);
            }
        }
        return ['data' => $d, 'errors' => $err, 'schedule' => $rows, 'schedule_errors' => $rowErr];
    }

    public static function update(int $id, array $data, ?array $schedule): void
    {
        Database::immediate(static function () use ($id, $data, $schedule): void {
            Database::query(
                "UPDATE pages SET slug = ?, title = ?, title_kk = ?, body = ?, body_kk = ?, is_published = ?, updated_at = strftime('%Y-%m-%d %H:%M:%S','now') WHERE id = ?",
                [$data['slug'], $data['title'], $data['title_kk'], $data['body'], $data['body_kk'], $data['is_published'], $id]
            );
            if ($schedule !== null) {
                Database::query('DELETE FROM schedule_items');
                foreach ($schedule as $pos => $r) {
                    Database::insert('schedule_items', $r + ['sort' => $pos + 1]);
                }
            }
        });
    }

    public static function setPublished(int $id, bool $on): void
    {
        Database::query('UPDATE pages SET is_published = ? WHERE id = ?', [$on ? 1 : 0, $id]);
    }
}
