<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\Database;
use App\Core\ImageProcessor;
use App\Core\UploadException;
use App\Core\Uploader;
use App\Models\Media;
use Tests\Support\ImageFixtures as F;
use Tests\TestCase;

final class UploaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshDatabase();
        $this->root = $this->tempDir();
    }

    private function upload(string $bytes, string $name = 'photo.jpg', ?Uploader $u = null, int $error = UPLOAD_ERR_OK): array
    {
        $tmp = $this->tempDir() . '/php-upload';
        file_put_contents($tmp, $bytes);
        $u ??= new Uploader($this->root, null, static fn() => true);
        return $u->handle(['name' => $name, 'tmp_name' => $tmp, 'error' => $error, 'size' => strlen($bytes), 'type' => 'image/jpeg'], null);
    }

    private function files(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $out[] = $f->getPathname();
        }
        sort($out);
        return $out;
    }

    private function assertRejected(string $code, int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("ожидался отказ $code");
        } catch (UploadException $e) {
            $this->assertSame($code, $e->errorCode());
            $this->assertSame($status, $e->status());
        }
        $this->assertSame([], $this->files(), 'файлов на диске нет');
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM media'), 'строк в БД нет');
    }

    public function testSuccessCreatesSixFilesAndRow(): void
    {
        $row = $this->upload(F::jpegWithExif(F::marker(2400, 1800), 6), '../../Мой снимок.JPG');
        $ym = gmdate('Y/m');
        $files = $this->files();
        $this->assertCount(6, $files);
        foreach ($files as $f) {
            $this->assertMatchesRegularExpression('~/images/' . preg_quote($ym, '~') . '/[0-9a-f]{32}-(thumb|medium|large)\.(webp|jpg)$~', $f);
            $this->assertSame('0644', substr(sprintf('%o', fileperms($f)), -4));
        }
        $this->assertSame('Мой снимок.JPG', $row['original_name']);
        $this->assertMatchesRegularExpression('~^images/' . preg_quote($ym, '~') . '/[0-9a-f]{32}$~', $row['path']);
        $this->assertSame('image', $row['type']);
        $this->assertSame('image/webp', $row['mime']);
        $this->assertSame([1440, 1920], [(int)$row['width'], (int)$row['height']]);
        $sum = array_sum(array_map('filesize', $files));
        $this->assertSame($sum, (int)$row['size']);
        $this->assertGreaterThan(0, (int)$row['source_size']);
        $v = media_variants($row);
        $this->assertSame(1200, $v['medium']['h']);
        $this->assertSame(400, $v['thumb']['h']);
        $this->assertFileExists($this->root . '/' . $v['large']['webp']);
        $this->assertStringNotContainsString('..', $row['variants']);
    }

    public function testTypeByContentNotByNameOrClientMime(): void
    {
        $this->assertRejected('not_image', 415, fn() => $this->upload('<?php echo 1;', 'fake.jpg'));
        $this->assertRejected('not_image', 415, fn() => $this->upload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'a.svg'));
        $this->assertRejected('not_image', 415, fn() => $this->upload("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'doc.pdf'));
        // настоящее фото под чужим расширением принимается: имя на тип не влияет
        $row = $this->upload(F::pngBytes(F::marker(30, 30)), 'x.php.txt');
        $this->assertSame('x.php.txt', $row['original_name']);
        $this->assertCount(6, $this->files());
    }

    public function testLimits(): void
    {
        Config::set(['media' => ['max_upload_mb' => 0.001]]); // ~1 КБ
        $this->assertLessThanOrEqual(1049, Media::maxUploadBytes());
        $this->assertRejected('too_large', 413, fn() => $this->upload(F::jpegBytes(F::marker(500, 500))));
        Config::set(['media' => ['max_upload_mb' => 20]]);
        $this->assertRejected('too_large', 413, fn() => $this->upload('x', 'a.jpg', null, UPLOAD_ERR_INI_SIZE));
        $this->assertRejected('too_large', 413, fn() => $this->upload('x', 'a.jpg', null, UPLOAD_ERR_FORM_SIZE));
        $this->assertRejected('no_file', 422, fn() => $this->upload('', 'a.jpg', null, UPLOAD_ERR_NO_FILE));
        $this->assertRejected('no_file', 422, fn() => (new Uploader($this->root))->handle(null, null));
        $this->assertRejected('no_file', 422, fn() => (new Uploader($this->root))->handle(['name' => 'a', 'tmp_name' => '/etc/passwd', 'error' => 0, 'size' => 1], null), 'не загруженный через HTTP файл');
    }

    public function testMaxUploadBytesTakesMinOfIni(): void
    {
        Config::set(['media' => ['max_upload_mb' => 100000]]);
        $ini = min(array_filter([
            \App\Core\Guard::iniBytes((string)ini_get('upload_max_filesize')),
            \App\Core\Guard::iniBytes((string)ini_get('post_max_size')),
        ], static fn($b) => $b > 0));
        $this->assertSame($ini, Media::maxUploadBytes());
    }

    public function testBombAndBrokenLeaveNothing(): void
    {
        $this->assertRejected('too_many_pixels', 422, fn() => $this->upload(F::pngHeader(30000, 30000), 'bomb.png'));
        $jpeg = F::jpegBytes(F::marker(800, 600));
        $this->assertRejected('broken', 422, fn() => $this->upload(substr($jpeg, 0, 700), 'cut.jpg'));
    }

    public function testHeicWithoutImagick(): void
    {
        if (ImageProcessor::heicSupported()) {
            $this->markTestSkipped('Imagick с HEIC есть');
        }
        $heic = "\0\0\0\x18ftypheic\0\0\0\0mif1heic" . str_repeat("\0", 64);
        $this->assertRejected('heic_unsupported', 415, fn() => $this->upload($heic, 'IMG_1.HEIC'));
    }

    public function testWriteFailureCleansEverything(): void
    {
        $n = 0;
        $writer = function (string $p, string $b) use (&$n): bool {
            if (++$n === 4) {
                return false; // «нет места» на четвёртом файле
            }
            return file_put_contents($p, $b) === strlen($b);
        };
        $u = new Uploader($this->root, $writer, static fn() => true);
        $this->assertRejected('disk_full', 507, fn() => $this->upload(F::jpegBytes(F::marker(900, 700)), 'a.jpg', $u));
        $this->assertSame(4, $n);
    }

    public function testDbFailureCleansFiles(): void
    {
        $this->pdo()->exec('DROP TABLE media');
        try {
            $this->upload(F::jpegBytes(F::marker(900, 700)));
            $this->fail('ожидалось исключение БД');
        } catch (\PDOException) {
        }
        $this->assertSame([], $this->files());
    }

    public function testNamesAreRandom(): void
    {
        $a = $this->upload(F::jpegBytes(F::marker(50, 50)));
        $b = $this->upload(F::jpegBytes(F::marker(50, 50)));
        $this->assertNotSame($a['path'], $b['path']);
        $this->assertCount(12, $this->files());
    }

    public function testCleanName(): void
    {
        $u = new Uploader($this->root);
        $this->assertSame('a.jpg', $u->cleanName('C:\\Users\\me\\a.jpg'));
        $this->assertSame('image', $u->cleanName(''));
        $this->assertSame('image', $u->cleanName('..'));
        $this->assertSame(255, mb_strlen($u->cleanName(str_repeat('я', 400) . '.jpg')));
        $this->assertSame('<script>.jpg', $u->cleanName("<script>.jpg\0"));
    }

    private function pdo(): \PDO
    {
        return Database::connection();
    }
}
