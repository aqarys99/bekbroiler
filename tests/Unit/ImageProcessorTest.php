<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\ImageProcessor;
use App\Core\UploadException;
use Tests\Support\ImageFixtures as F;
use Tests\TestCase;

final class ImageProcessorTest extends TestCase
{
    private function file(string $bytes): string
    {
        $p = $this->tempDir() . '/in.bin';
        file_put_contents($p, $bytes);
        return $p;
    }

    public function testThreeSizesWithoutUpscaling(): void
    {
        $r = ImageProcessor::process($this->file(F::jpegBytes(F::marker(2400, 1800))), 'image/jpeg');
        $this->assertSame([1920, 1440], [$r['large']['w'], $r['large']['h']]);
        $this->assertSame([1200, 900], [$r['medium']['w'], $r['medium']['h']]);
        $this->assertSame([400, 300], [$r['thumb']['w'], $r['thumb']['h']]);
        foreach ($r as $v) {
            $this->assertStringStartsWith('RIFF', $v['webp']);
            $this->assertStringStartsWith("\xFF\xD8", $v['jpg']);
        }

        $small = ImageProcessor::process($this->file(F::jpegBytes(F::marker(300, 200))), 'image/jpeg');
        foreach ($small as $v) {
            $this->assertSame([300, 200], [$v['w'], $v['h']]);
        }
    }

    /** @dataProvider orientations */
    public function testOrientation(int $o, bool $swapped, string $corner): void
    {
        $bytes = F::jpegWithExif(F::marker(60, 40), $o, false);
        $this->assertSame($o, ImageProcessor::readOrientation($bytes));
        $r = ImageProcessor::process($this->file($bytes), 'image/jpeg')['large'];
        $this->assertSame($swapped ? [40, 60] : [60, 40], [$r['w'], $r['h']]);
        foreach (['webp', 'jpg'] as $fmt) {
            $x = ['TL' => 6, 'TR' => $r['w'] - 7, 'BL' => 6, 'BR' => $r['w'] - 7][$corner];
            $y = ['TL' => 6, 'TR' => 6, 'BL' => $r['h'] - 7, 'BR' => $r['h'] - 7][$corner];
            [$red, $g] = F::pixel($r[$fmt], $x, $y);
            $this->assertGreaterThan(180, $red, "$o $fmt: красный угол должен быть в $corner");
            $this->assertLessThan(90, $g);
        }
    }

    public static function orientations(): array
    {
        return [
            '1' => [1, false, 'TL'], '2' => [2, false, 'TR'], '3' => [3, false, 'BR'], '4' => [4, false, 'BL'],
            '5' => [5, true, 'TL'], '6' => [6, true, 'TR'], '7' => [7, true, 'BR'], '8' => [8, true, 'BL'],
        ];
    }

    public function testReadOrientationEdgeCases(): void
    {
        $this->assertSame(1, ImageProcessor::readOrientation(F::jpegBytes(F::marker(20, 20))));
        $this->assertSame(1, ImageProcessor::readOrientation('not a jpeg at all'));
        $this->assertSame(1, ImageProcessor::readOrientation(''));
        $this->assertSame(1, ImageProcessor::readOrientation("\xFF\xD8\xFF\xE1\x00\x08Exif\0\0"));
    }

    public function testMetadataIsStripped(): void
    {
        $bytes = F::jpegWithExif(F::marker(500, 400), 6);
        $this->assertStringContainsString("Exif\0\0", $bytes);
        $r = ImageProcessor::process($this->file($bytes), 'image/jpeg');
        foreach ($r as $size => $v) {
            foreach (['webp', 'jpg'] as $fmt) {
                $this->assertStringNotContainsString("Exif\0\0", $v[$fmt], "$size.$fmt");
                $this->assertStringNotContainsString('http://ns.adobe.com/xap', $v[$fmt]);
                $this->assertStringNotContainsString('GPS', $v[$fmt]);
            }
            if (function_exists('exif_read_data')) {
                $p = $this->tempDir() . '/o.jpg';
                file_put_contents($p, $v['jpg']);
                $exif = @exif_read_data($p, 'ANY_TAG', true) ?: [];
                $this->assertArrayNotHasKey('GPS', $exif);
                $this->assertArrayNotHasKey('GPSLatitude', $exif['IFD0'] ?? []);
            }
        }
    }

    public function testPngAlphaKeptInWebpAndWhiteInJpeg(): void
    {
        $im = imagecreatetruecolor(80, 60);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 255, 127));
        $r = ImageProcessor::process($this->file(F::pngBytes($im)), 'image/png')['large'];
        $this->assertGreaterThan(100, F::pixel($r['webp'], 40, 30)[3], 'WebP хранит прозрачность');
        [$red, $g, $b] = F::pixel($r['jpg'], 40, 30);
        $this->assertGreaterThan(240, min($red, $g, $b), 'JPEG залит белым');
    }

    public function testGifFirstFrame(): void
    {
        $im = F::marker(50, 50);
        ob_start();
        imagegif($im);
        $gif = (string)ob_get_clean();
        $r = ImageProcessor::process($this->file($gif), 'image/gif')['large'];
        $this->assertSame([50, 50], [$r['w'], $r['h']]);
        $this->assertGreaterThan(180, F::pixel($r['jpg'], 5, 5)[0]);
    }

    public function testBombRejectedByHeaderWithoutDecoding(): void
    {
        $before = memory_get_peak_usage(true);
        try {
            ImageProcessor::process($this->file(F::pngHeader(30000, 30000)), 'image/png');
            $this->fail('ожидался отказ');
        } catch (UploadException $e) {
            $this->assertSame('too_many_pixels', $e->errorCode());
            $this->assertSame(422, $e->status());
            $this->assertSame(900, $e->params()['mp']);
        }
        $this->assertLessThan(64 * 1048576, memory_get_peak_usage(true) - $before);
    }

    public function testPixelLimitFromConfig(): void
    {
        Config::set(['media' => ['max_pixels' => 1000]]);
        $this->expectException(UploadException::class);
        ImageProcessor::process($this->file(F::jpegBytes(F::marker(100, 100))), 'image/jpeg');
    }

    public function testBrokenFiles(): void
    {
        $jpeg = F::jpegBytes(F::marker(800, 600));
        foreach (['truncated' => substr($jpeg, 0, (int)(strlen($jpeg) * 0.6)), 'png-cut' => substr(F::pngBytes(F::marker(100, 100)), 0, 60), 'no-image' => 'hello world, not an image'] as $name => $bytes) {
            try {
                ImageProcessor::process($this->file($bytes), str_starts_with($bytes, "\xFF") ? 'image/jpeg' : 'image/png');
                $this->fail("$name должен быть отклонён");
            } catch (UploadException $e) {
                $this->assertSame('broken', $e->errorCode(), $name);
                $this->assertSame(422, $e->status());
            }
        }
    }
}
