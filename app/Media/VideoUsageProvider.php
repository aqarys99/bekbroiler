<?php
declare(strict_types=1);

namespace App\Media;

use App\Core\Database;

/** Видео как потребитель медиа: файл ролика и обложка (I-02, I-25). */
final class VideoUsageProvider implements UsageProvider
{
    public function key(): string
    {
        return 'videos';
    }

    public function find(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mediaIds)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll(
            "SELECT x.media_id, x.video_id, v.title, MAX(x.is_poster) AS p
             FROM (
               SELECT media_id, id AS video_id, 0 AS is_poster FROM videos WHERE media_id IN ($in)
               UNION ALL
               SELECT poster_media_id AS media_id, id, 1 FROM videos WHERE poster_media_id IN ($in)
             ) x JOIN videos v ON v.id = x.video_id
             GROUP BY x.media_id, x.video_id ORDER BY x.media_id, x.video_id",
            [...$ids, ...$ids]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['media_id']][] = [
                'label' => t((int)$r['p'] === 1 ? 'videos.usage.poster' : 'videos.usage.file', ['title' => (string)$r['title']]),
                'url'   => admin_url('/videos'),
            ];
        }
        return $out;
    }
}
