<?php
declare(strict_types=1);

namespace App\Models;

final class Achievement extends SectionModel
{
    protected const TABLE = 'achievements';

    public static function prepare(array $in): array
    {
        $err = [];
        $year = self::str($in, 'year');
        $max = (int)date('Y') + 1;
        if (!preg_match('/^\d{4}$/', $year) || (int)$year < 1950 || (int)$year > $max) {
            $err['year'] = t('achievements.error.year', ['max' => $max]);
        }
        $d = [
            'year'           => (int)$year,
            'title'          => self::text($in, 'title', 150, $err, t('achievements.field.title'), true),
            'title_kk'       => self::text($in, 'title_kk', 150, $err, t('achievements.field.title_kk')),
            'description'    => self::text($in, 'description', 1000, $err, t('achievements.field.description')),
            'description_kk' => self::text($in, 'description_kk', 1000, $err, t('achievements.field.description_kk')),
            'media_id'       => self::photoId($in, 'media_id', $err),
            'is_published'   => !empty($in['is_published']) ? 1 : 0,
        ];
        return ['data' => $d, 'errors' => $err];
    }

    /** Для сайта (фича 11): показанные достижения по порядку, с photo (строка media|null). */
    public static function published(): array
    {
        $rows = self::publishedRows();
        $media = Media::findMany(array_values(array_filter(array_map(
            static fn(array $r): ?int => $r['media_id'] !== null ? (int)$r['media_id'] : null, $rows
        ))));
        foreach ($rows as &$r) {
            $r['photo'] = $r['media_id'] !== null ? ($media[(int)$r['media_id']] ?? null) : null;
        }
        unset($r);
        return $rows;
    }
}
