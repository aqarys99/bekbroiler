<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\HtmlSanitizer;

/** Фиксированные страницы сайта (slug задан миграцией, создать и удалить нельзя). */
final class Page
{
    public const BODY_MAX = 60000;
    public const SCHEDULE_MAX = 30;

    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM pages WHERE id = ?', [$id]);
    }

    public static function all(): array
    {
        return Database::fetchAll('SELECT * FROM pages ORDER BY sort, id');
    }

    /** Для сайта (фича 11): показанная страница; у «rezhim» добавлен schedule. */
    public static function published(string $slug): ?array
    {
        $p = Database::fetch('SELECT * FROM pages WHERE slug = ? AND is_published = 1', [$slug]);
        if ($p === null) {
            return null;
        }
        if ($slug === 'rezhim') {
            $p['schedule'] = self::schedule();
        }
        return $p;
    }

    /** Для сайта: показанные страницы (slug, title, title_kk) по порядку — меню и карта сайта. */
    public static function publishedList(): array
    {
        return Database::fetchAll('SELECT slug, title, title_kk, updated_at FROM pages WHERE is_published = 1 ORDER BY sort, id');
    }

    public static function schedule(): array
    {
        return Database::fetchAll('SELECT * FROM schedule_items ORDER BY sort, id');
    }

    /** @return array{data:array,errors:array<string,string>,schedule:array,schedule_errors:array<int,string>} */
    public static function prepare(array $in, bool $withSchedule): array
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
                "UPDATE pages SET title = ?, title_kk = ?, body = ?, body_kk = ?, is_published = ?, updated_at = strftime('%Y-%m-%d %H:%M:%S','now') WHERE id = ?",
                [$data['title'], $data['title_kk'], $data['body'], $data['body_kk'], $data['is_published'], $id]
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
