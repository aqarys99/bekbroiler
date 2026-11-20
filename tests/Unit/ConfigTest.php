<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function testDotNotationAndDefault(): void
    {
        $this->assertSame('sqlite', Config::get('db.driver'));
        $this->assertSame('Asia/Almaty', Config::get('timezone'));
        $this->assertSame('x', Config::get('db.nothing', 'x'));
        $this->assertNull(Config::get('a.b.c'));
    }

    public function testUnknownDriverThrows(): void
    {
        Config::set(['db' => ['driver' => 'mysql']]);
        $this->expectException(\RuntimeException::class);
        Config::dbPath();
    }

    public function testDbPathResolution(): void
    {
        Config::set(['db' => ['driver' => 'sqlite', 'path' => '/abs/x.sqlite']]);
        $this->assertSame('/abs/x.sqlite', Config::dbPath());
        Config::set(['db' => ['path' => 'storage/database.sqlite']]);
        $this->assertSame(Config::storageDir() . '/database.sqlite', Config::dbPath());
    }

    public function testLoadsFileOverDefaults(): void
    {
        $file = $this->tempDir() . '/c.php';
        file_put_contents($file, "<?php return ['debug' => true, 'base_path' => '/q'];");
        putenv('APP_CONFIG_FILE=' . $file);
        Config::reset();
        try {
            $this->assertTrue(Config::get('debug'));
            $this->assertSame('/q', Config::get('base_path'));
            $this->assertSame('ru', Config::get('locale'));
        } finally {
            putenv('APP_CONFIG_FILE=' . $GLOBALS['__sadik_tmp'] . '/config.php');
        }
    }
}
