<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LangKeysTest extends TestCase
{
    private static function load(string $l): array
    {
        return (array)require dirname(__DIR__, 2) . '/app/lang/' . $l . '.php';
    }

    private static function placeholders(string $s): array
    {
        preg_match_all('/:[a-z_]+/', $s, $m);
        $r = array_unique($m[0]);
        sort($r);
        return $r;
    }

    public function testSameKeys(): void
    {
        $ru = self::load('ru');
        $kk = self::load('kk');
        $this->assertSame([], array_values(array_diff(array_keys($ru), array_keys($kk))), 'нет в kk.php');
        $this->assertSame([], array_values(array_diff(array_keys($kk), array_keys($ru))), 'лишнее в kk.php');
    }

    public function testSamePlaceholders(): void
    {
        $ru = self::load('ru');
        $kk = self::load('kk');
        foreach ($ru as $k => $v) {
            if (isset($kk[$k])) {
                $this->assertSame(self::placeholders((string)$v), self::placeholders((string)$kk[$k]), "плейсхолдеры в $k");
            }
        }
    }
}
