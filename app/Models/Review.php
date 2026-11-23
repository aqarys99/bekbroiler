<?php
declare(strict_types=1);

namespace App\Models;

final class Review extends SectionModel
{
    protected const TABLE = 'reviews';

    public static function prepare(array $in): array
    {
        $err = [];
        $d = [
            'author'       => self::text($in, 'author', 150, $err, t('reviews.field.author'), true),
            'author_kk'    => self::text($in, 'author_kk', 150, $err, t('reviews.field.author_kk')),
            'text'         => self::text($in, 'text', 1000, $err, t('reviews.field.text'), true),
            'text_kk'      => self::text($in, 'text_kk', 1000, $err, t('reviews.field.text_kk')),
            'is_published' => !empty($in['is_published']) ? 1 : 0,
        ];
        return ['data' => $d, 'errors' => $err];
    }

    /** Для сайта (фича 11). */
    public static function published(): array
    {
        return self::publishedRows();
    }
}
