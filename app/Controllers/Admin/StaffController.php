<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Media;
use App\Models\Staff;

final class StaffController extends SectionController
{
    protected const KEY = 'staff';
    protected const MODEL = Staff::class;

    protected function listRows(): array
    {
        $rows = Staff::all();
        $media = Media::findMany(array_values(array_filter(array_map(
            static fn(array $r): ?int => $r['photo_media_id'] !== null ? (int)$r['photo_media_id'] : null, $rows
        ))));
        foreach ($rows as &$r) {
            $r['photo'] = $r['photo_media_id'] !== null ? ($media[(int)$r['photo_media_id']] ?? null) : null;
        }
        unset($r);
        return $rows;
    }

    protected function blank(): array
    {
        return ['is_published' => 1, 'category' => ''];
    }

    protected function extras(?array $row, bool $form = true): array
    {
        if (!$form) {
            return [];
        }
        $sel = $_POST ? (Media::parseIds($_POST['photo_media_id'] ?? null)[0] ?? null)
            : ($row !== null && $row['photo_media_id'] !== null ? (int)$row['photo_media_id'] : null);
        return ['mf' => $this->photoField('photo_media_id', t('staff.field.photo'), $sel)];
    }
}
