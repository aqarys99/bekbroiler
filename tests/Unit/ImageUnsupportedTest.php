<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ImageProcessor;
use App\Core\Requirements;
use App\Core\UploadException;
use App\Core\Uploader;
use Tests\Support\ImageFixtures as F;
use Tests\TestCase;

final class ImageUnsupportedTest extends TestCase
{
    protected function tearDown(): void
    {
        ImageProcessor::overrideAvailable(null);
        parent::tearDown();
    }

    public function testUploadWithoutGdIs503NotCrash(): void
    {
        ImageProcessor::overrideAvailable(false);
        $tmp = $this->tempDir() . '/u';
        file_put_contents($tmp, F::jpegBytes(F::marker(20, 20)));
        try {
            (new Uploader($this->tempDir(), null, static fn() => true))->handle(['name' => 'a.jpg', 'tmp_name' => $tmp, 'error' => 0, 'size' => 10], null);
            $this->fail('ожидался отказ');
        } catch (UploadException $e) {
            $this->assertSame('image_unsupported', $e->errorCode());
            $this->assertSame(503, $e->status());
            $this->assertStringContainsString('GD с WebP', $e->userMessage());
        }
        $this->expectException(UploadException::class);
        ImageProcessor::process($tmp, 'image/jpeg');
    }

    public function testRequirementsImageRowFailsWithoutGd(): void
    {
        $d = $this->tempDir();
        $paths = ['storage' => $d . '/s', 'config' => $d . '/c', 'uploads' => $d . '/u'];
        $by = array_column(Requirements::check($paths), null, 'key');
        $this->assertSame('ok', $by['image']['status']);
        $this->assertContains($by['heic']['status'], ['ok', 'warn']);

        ImageProcessor::overrideAvailable(false);
        $by = array_column(Requirements::check($paths), null, 'key');
        $this->assertSame('fail', $by['image']['status']);
        $this->assertNotSame('', $by['image']['hint']);
    }
}
