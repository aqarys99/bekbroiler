<?php
declare(strict_types=1);

namespace App\Models;

final class Staff extends SectionModel
{
    protected const TABLE = 'staff';
    public const CATEGORIES = ['teacher', 'moderator', 'expert', 'researcher', 'master'];

    public static function prepare(array $in): array
    {
        $err = [];
        $cat = self::str($in, 'category');
        if ($cat !== '' && !in_array($cat, self::CATEGORIES, true)) {
            $err['category'] = t('staff.error.category');
            $cat = '';
        }
        $d = [
            'full_name'        => self::text($in, 'full_name', 150, $err, t('staff.field.full_name'), true),
            'full_name_kk'     => self::text($in, 'full_name_kk', 150, $err, t('staff.field.full_name_kk')),
            'position'         => self::text($in, 'position', 150, $err, t('staff.field.position'), true),
            'position_kk'      => self::text($in, 'position_kk', 150, $err, t('staff.field.position_kk')),
            'category'         => $cat,
            'education'        => self::text($in, 'education', 300, $err, t('staff.field.education')),
            'education_kk'     => self::text($in, 'education_kk', 300, $err, t('staff.field.education_kk')),
            'experience_years' => self::intOrNull($in, 'experience_years', 0, 70, $err, t('staff.field.experience')),
            'photo_media_id'   => self::photoId($in, 'photo_media_id', $err),
            'is_published'     => !empty($in['is_published']) ? 1 : 0,
        ];
        return ['data' => $d, 'errors' => $err];
    }

    /** Для сайта (фича 11): показанные педагоги по порядку, с photo (строка media|null). */
    public static function published(): array
    {
        $rows = self::publishedRows();
        $media = Media::findMany(array_values(array_filter(array_map(
            static fn(array $r): ?int => $r['photo_media_id'] !== null ? (int)$r['photo_media_id'] : null, $rows
        ))));
        foreach ($rows as &$r) {
            $r['photo'] = $r['photo_media_id'] !== null ? ($media[(int)$r['photo_media_id']] ?? null) : null;
        }
        unset($r);
        return $rows;
    }

    /** @return array<int,string> id => ФИО (для выбора воспитателей в группе) */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $r) {
            $out[(int)$r['id']] = (string)$r['full_name'];
        }
        return $out;
    }
}
