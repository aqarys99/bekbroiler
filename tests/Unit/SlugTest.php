<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SlugTest extends TestCase
{
    public static function letters(): array
    {
        $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ы' => 'y', 'э' => 'e',
            'ю' => 'yu', 'я' => 'ya',
            'ә' => 'a', 'ғ' => 'g', 'қ' => 'q', 'ң' => 'n', 'ө' => 'o', 'ұ' => 'u', 'ү' => 'u', 'һ' => 'h', 'і' => 'i'];
        $out = [];
        foreach ($map as $ru => $lat) {
            $out[$ru] = [$ru . 'x', $lat . 'x'];
        }
        return $out;
    }

    #[DataProvider('letters')]
    public function testEveryLetter(string $in, string $expected): void
    {
        $this->assertSame($expected, Slug::make($in));
    }

    public function testUpperCaseKazakhLetters(): void
    {
        $this->assertSame('agqnouuhi', Slug::make('ӘҒҚҢӨҰҮҺІ'));
    }

    public function testHardAndSoftSignsAreDropped(): void
    {
        $this->assertSame('obem', Slug::make('объем'));
        $this->assertSame('semya', Slug::make('семья'));
        $this->assertSame('ab', Slug::make('аъьб'));
    }

    public function testSpecExamples(): void
    {
        $this->assertSame('osenniy-utrennik-v-starshey-gruppe', Slug::make('Осенний утренник в старшей группе'));
        $this->assertSame('konkurs-risunkov-menin-otanym', Slug::make('Конкурс рисунков «Менің Отаным»'));
    }

    public function testCollapsesAndTrimsDashes(): void
    {
        $this->assertSame('a-b-c', Slug::make('  --a___b!!!c--  '));
        $this->assertSame('x-2026', Slug::make('X, 2026!'));
    }

    public function testEmptyGivesNovost(): void
    {
        foreach (['', '   ', '!!!', '«»', 'ъь', '日本語'] as $s) {
            $this->assertSame('novost', Slug::make($s), var_export($s, true));
        }
    }

    public function testHtmlAndInjectionAreNeutralised(): void
    {
        $s = Slug::make('<script>alert(1)</script> ../../etc/passwd');
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $s);
        $this->assertSame('script-alert-1-script-etc-passwd', $s);
    }

    public function testMaxLengthCutAtLastDash(): void
    {
        $s = Slug::make(str_repeat('слово ', 40));
        $this->assertLessThanOrEqual(80, strlen($s));
        $this->assertStringEndsNotWith('-', $s);
        $this->assertStringEndsWith('slovo', $s);
        $long = Slug::make(str_repeat('щ', 100));
        $this->assertSame(80, strlen($long));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]{1,80}$/', Slug::make(str_repeat('Ж-', 200)));
    }

    public function testIdempotent(): void
    {
        $s = Slug::make('Осенний утренник — 2026!');
        $this->assertSame($s, Slug::make($s));
    }
}
