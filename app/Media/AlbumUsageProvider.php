<?php
declare(strict_types=1);

namespace App\Media;

use App\Core\Database;

/** Фотоальбомы как потребитель медиа: фото в альбоме и обложка (I-02, I-07). */
final class AlbumUsageProvider implements UsageProvider
{
    public function key(): string
    {
        return 'albums';
    }

    public function find(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mediaIds)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll(
            "SELECT x.media_id, x.album_id, a.title, MAX(x.is_cover) AS c
             FROM (
               SELECT cover_media_id AS media_id, id AS album_id, 1 AS is_cover FROM albums WHERE cover_media_id IN ($in)
               UNION ALL
               SELECT media_id, album_id, 0 FROM album_media WHERE media_id IN ($in)
             ) x JOIN albums a ON a.id = x.album_id
             GROUP BY x.media_id, x.album_id ORDER BY x.media_id, x.album_id",
            [...$ids, ...$ids]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['media_id']][] = [
                'label' => t((int)$r['c'] === 1 ? 'gallery.usage.cover' : 'gallery.usage.photo', ['title' => (string)$r['title']]),
                'url'   => admin_url('/gallery/' . (int)$r['album_id']),
            ];
        }
        return $out;
    }
}
