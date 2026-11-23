<?php
declare(strict_types=1);

namespace App\Media;

use App\Core\Database;

/** Разделы сайта как потребитель медиа: фото педагогов и достижений (I-02, I-07). */
final class SectionsUsageProvider implements UsageProvider
{
    public function key(): string
    {
        return 'sections';
    }

    public function find(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mediaIds)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (Database::fetchAll("SELECT id, full_name, photo_media_id AS mid FROM staff WHERE photo_media_id IN ($in) ORDER BY id", $ids) as $r) {
            $out[(int)$r['mid']][] = ['label' => t('staff.usage', ['name' => (string)$r['full_name']]), 'url' => admin_url('/staff/' . (int)$r['id'])];
        }
        foreach (Database::fetchAll("SELECT id, title, media_id AS mid FROM achievements WHERE media_id IN ($in) ORDER BY id", $ids) as $r) {
            $out[(int)$r['mid']][] = ['label' => t('achievements.usage', ['title' => (string)$r['title']]), 'url' => admin_url('/achievements/' . (int)$r['id'])];
        }
        return $out;
    }
}
