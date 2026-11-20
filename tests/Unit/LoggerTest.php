<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Logger;
use Tests\TestCase;

final class LoggerTest extends TestCase
{
    private string $old;

    protected function setUp(): void
    {
        $this->old = (string)getenv('APP_STORAGE_DIR');
    }

    protected function tearDown(): void
    {
        putenv('APP_STORAGE_DIR=' . $this->old);
        parent::tearDown();
    }

    public function testWritesDatedFileWithFormat(): void
    {
        $dir = $this->tempDir();
        putenv('APP_STORAGE_DIR=' . $dir);
        Logger::error('Boom', ['k' => 'v']);
        $file = $dir . '/logs/app-' . gmdate('Y-m-d') . '.log';
        $this->assertFileExists($file);
        $this->assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] ERROR Boom \{"k":"v"\}$/m', file_get_contents($file));
        Logger::warning('W');
        Logger::info('I');
        $c = file_get_contents($file);
        $this->assertStringContainsString('WARNING W', $c);
        $this->assertStringContainsString('INFO I', $c);
    }

    public function testUnwritableDirDoesNotThrow(): void
    {
        $f = $this->tempDir() . '/file';
        file_put_contents($f, 'x');
        putenv('APP_STORAGE_DIR=' . $f); // storage — это файл
        $prev = ini_set('error_log', $this->tempDir() . '/php.log');
        Logger::error('still fine');
        ini_set('error_log', (string)$prev);
        $this->assertTrue(true);
    }
}
