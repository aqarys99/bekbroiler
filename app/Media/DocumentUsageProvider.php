<?php
declare(strict_types=1);

namespace App\Media;

use App\Core\Database;

/** Документы как потребитель медиа (I-02, I-25). */
final class DocumentUsageProvider implements UsageProvider
{
    public function key(): string
    {
        return 'documents';
    }

    public function find(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mediaIds)));
        if (!$ids) {
            return [];
        }
        $rows = Database::fetchAll(
            'SELECT id, media_id, title FROM documents WHERE media_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id',
            $ids
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['media_id']][] = [
                'label' => t('documents.usage.label', ['title' => (string)$r['title']]),
                'url'   => admin_url('/documents'),
            ];
        }
        return $out;
    }
}
