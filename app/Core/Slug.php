<?php
declare(strict_types=1);

namespace App\Core;

final class Slug
{
    public const MAX = 80;

    private const MAP = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
        'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya',
        'ә' => 'a', 'ғ' => 'g', 'қ' => 'q', 'ң' => 'n', 'ө' => 'o', 'ұ' => 'u', 'ү' => 'u', 'һ' => 'h', 'і' => 'i',
    ];

    public static function make(string $text, string $fallback = 'novost'): string
    {
        $s = strtr(mb_strtolower($text, 'UTF-8'), self::MAP);
        $s = (string)preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        if (strlen($s) > self::MAX) {
            $cut = substr($s, 0, self::MAX);
            $pos = strrpos($cut, '-');
            $s = $pos !== false && $pos > 0 && $s[self::MAX] !== '-' ? substr($cut, 0, $pos) : rtrim($cut, '-');
        }
        return $s === '' ? $fallback : $s;
    }
}
