<?php
declare(strict_types=1);

namespace App\Media;

use App\Core\Database;

/** Новости как потребитель медиа: обложка, фото к новости, фото в тексте (I-01, I-25). */
final class NewsUsageProvider implements UsageProvider
{
    public function key(): string
    {
        return 'news';
    }

    public function find(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mediaIds)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::fetchAll(
            "SELECT x.media_id, x.news_id, n.title, MAX(x.is_cover) AS c, MAX(x.is_gallery) AS g, MAX(x.is_body) AS b
             FROM (
               SELECT cover_media_id AS media_id, id AS news_id, 1 AS is_cover, 0 AS is_gallery, 0 AS is_body
                 FROM news WHERE cover_media_id IN ($in)
               UNION ALL
               SELECT media_id, news_id, 0, CASE WHEN place = 'gallery' THEN 1 ELSE 0 END, CASE WHEN place = 'body' THEN 1 ELSE 0 END
                 FROM news_media WHERE media_id IN ($in)
             ) x JOIN news n ON n.id = x.news_id
             GROUP BY x.media_id, x.news_id ORDER BY x.media_id, x.news_id",
            [...$ids, ...$ids]
        );
        $out = [];
        foreach ($rows as $r) {
            $roles = [];
            foreach (['c' => 'cover', 'g' => 'gallery', 'b' => 'body'] as $col => $role) {
                if ((int)$r[$col] === 1) {
                    $roles[] = t('news.usage.' . $role);
                }
            }
            $out[(int)$r['media_id']][] = [
                'label' => t('news.usage.label', ['title' => (string)$r['title'], 'roles' => implode(', ', $roles)]),
                'url'   => admin_url('/news/' . (int)$r['news_id'] . '/edit'),
            ];
        }
        return $out;
    }
}
