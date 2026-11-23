<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Achievement;
use App\Models\Media;

final class AchievementsController extends SectionController
{
    protected const KEY = 'achievements';
    protected const MODEL = Achievement::class;

    protected function listRows(): array
    {
        $rows = Achievement::all();
        $media = Media::findMany(array_values(array_filter(array_map(
            static fn(array $r): ?int => $r['media_id'] !== null ? (int)$r['media_id'] : null, $rows
        ))));
        foreach ($rows as &$r) {
            $r['photo'] = $r['media_id'] !== null ? ($media[(int)$r['media_id']] ?? null) : null;
        }
        unset($r);
        return $rows;
    }

    protected function blank(): array
    {
        return ['is_published' => 1, 'year' => date('Y')];
    }

    protected function extras(?array $row, bool $form = true): array
    {
        if (!$form) {
            return [];
        }
        $sel = $_POST ? (Media::parseIds($_POST['media_id'] ?? null)[0] ?? null)
            : ($row !== null && $row['media_id'] !== null ? (int)$row['media_id'] : null);
        return ['mf' => $this->photoField('media_id', t('achievements.field.photo'), $sel)];
    }
}
