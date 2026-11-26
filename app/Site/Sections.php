<?php
declare(strict_types=1);

namespace App\Site;

use App\Core\Clock;
use App\Core\Database;
use App\Models\Page;

/** Что есть на сайте сейчас: меню и карта сайта показывают только непустые разделы. */
final class Sections
{
    /** @return array<string,bool> */
    public static function flags(): array
    {
        $now = Clock::nowUtc()->format(Clock::FORMAT);
        $n = static fn(string $sql, array $p = []): bool => (int)Database::fetchValue($sql, $p) > 0;
        return [
            'staff'        => $n('SELECT COUNT(*) FROM staff WHERE is_published = 1'),
            'groups'       => $n('SELECT COUNT(*) FROM groups WHERE is_published = 1'),
            'news'         => $n("SELECT COUNT(*) FROM news WHERE status = 'published' AND published_at <= ?", [$now]),
            'gallery'      => $n('SELECT COUNT(*) FROM albums WHERE is_published = 1'),
            'videos'       => $n("SELECT COUNT(*) FROM videos WHERE is_published = 1 AND status = 'ready'"),
            'documents'    => $n('SELECT COUNT(*) FROM documents d JOIN pages p ON p.id = d.page_id WHERE d.is_published = 1 AND p.is_published = 1'),
            'achievements' => $n('SELECT COUNT(*) FROM achievements WHERE is_published = 1'),
            'reviews'      => $n('SELECT COUNT(*) FROM reviews WHERE is_published = 1'),
        ];
    }

    /** @return array<string,array> slug => строка страницы */
    public static function pages(): array
    {
        $out = [];
        foreach (Page::publishedList() as $p) {
            $out[(string)$p['slug']] = $p;
        }
        return $out;
    }
}
