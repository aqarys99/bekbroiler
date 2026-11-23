<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\Database;
use App\Core\DiskUsage;
use App\Media\MediaInUseException;
use App\Media\UsageCheckException;
use App\Media\UsageProvider;
use App\Models\Media;
use Tests\TestCase;

final class MediaTest extends TestCase
{
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshDatabase();
        $this->uploads = $this->tempDir();
        putenv('APP_UPLOADS_DIR=' . $this->uploads);
    }

    protected function tearDown(): void
    {
        putenv('APP_UPLOADS_DIR');
        Media::setProviders(null);
        parent::tearDown();
    }

    private function add(string $name, string $alt = '', string $type = 'image', int $size = 100, string $created = '2026-01-01 10:00:00'): int
    {
        $hex = bin2hex(random_bytes(16));
        $variants = [];
        $files = [];
        foreach (['thumb', 'medium', 'large'] as $s) {
            $variants[$s] = ['w' => 10, 'h' => 10, 'webp' => "images/2026/11/$hex-$s.webp", 'jpg' => "images/2026/11/$hex-$s.jpg"];
        }
        @mkdir($this->uploads . '/images/2026/11', 0775, true);
        foreach ($variants as $v) {
            foreach (['webp', 'jpg'] as $f) {
                file_put_contents($this->uploads . '/' . $v[$f], 'x');
                $files[] = $this->uploads . '/' . $v[$f];
            }
        }
        $id = Media::create([
            'type' => $type, 'original_name' => $name, 'path' => "images/2026/11/$hex", 'mime' => 'image/webp',
            'size' => $size, 'variants' => json_encode($variants), 'alt' => $alt, 'created_at' => $created, 'updated_at' => $created,
        ]);
        $this->files[$id] = $files;
        return $id;
    }

    /** @var array<int,string[]> */
    private array $files = [];

    private function provider(string $key, array $map, bool $throw = false, ?int &$calls = null): UsageProvider
    {
        return new class($key, $map, $throw, $calls) implements UsageProvider {
            public function __construct(private string $k, private array $map, private bool $throw, private ?int &$calls)
            {
            }
            public function key(): string
            {
                return $this->k;
            }
            public function find(array $mediaIds): array
            {
                $this->calls = ($this->calls ?? 0) + 1;
                if ($this->throw) {
                    throw new \RuntimeException('boom');
                }
                return array_intersect_key($this->map, array_flip($mediaIds));
            }
        };
    }

    public function testListSearchCyrillicAndLiteralWildcards(): void
    {
        $a = $this->add('Осенний УТРЕННИК.jpg');
        $b = $this->add('100%_done.jpg');
        $c = $this->add('abc.jpg', 'Дети на прогулке');
        $d = $this->add('other.jpg');
        $ids = static fn(array $l): array => array_map(static fn($r) => (int)$r['id'], $l['items']);

        $this->assertSame([$a], $ids(Media::list('утренник')));
        $this->assertSame([$c], $ids(Media::list('ПРОГУЛКЕ')));
        $this->assertSame([$b], $ids(Media::list('%_')));
        $this->assertSame([], $ids(Media::list('%x')));
        $this->assertSame([$d, $c, $b, $a], $ids(Media::list('')));
        $this->assertSame([$c, $a], $ids(Media::list('к')), 'часть имени и alt вместе');
        $this->assertSame(4, Media::list('  ')['total']);
    }

    public function testTypeFilterAndAvailableTypes(): void
    {
        $this->add('a.jpg');
        $this->assertSame(['all', 'image', 'file'], Media::availableTypes());
        $this->assertSame('all', Media::normalizeType('video'), 'в 03 видео нет — вариант не предлагается');
        $this->assertSame('all', Media::normalizeType('hack'));
        $this->assertSame('image', Media::normalizeType('image'));
        $this->add('v.mp4', '', 'video');
        $this->assertSame(['all', 'image', 'video', 'file'], Media::availableTypes());
        $this->assertSame('video', Media::normalizeType('video'));
        $this->assertSame(1, Media::list('', 'video')['total']);
        $this->assertSame(1, Media::list('', 'image')['total']);
    }

    public function testPagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->add("f$i.jpg", '', 'image', 1, sprintf('2026-01-0%d 10:00:00', $i));
        }
        $p1 = Media::list('', 'all', 1, 2);
        $this->assertSame(3, $p1['pages']);
        $this->assertSame(['f5.jpg', 'f4.jpg'], array_column($p1['items'], 'original_name'));
        $this->assertSame(['f1.jpg'], array_column(Media::list('', 'all', 999, 2)['items'], 'original_name'));
        $this->assertSame(1, Media::list('', 'all', -5, 2)['page']);
        $this->assertSame(1, Media::list('zzz')['pages']);
    }

    public function testUsagesFromSeveralProvidersAndCounts(): void
    {
        $id = $this->add('a.jpg');
        $free = $this->add('b.jpg');
        $callsA = 0;
        $callsB = 0;
        Media::setProviders([
            $this->provider('news', [$id => [['label' => 'Новость «Утренник»', 'url' => '/admin/news/5/edit']]], false, $callsA),
            $this->provider('albums', [$id => [['label' => 'Альбом «Осень»', 'url' => '/admin/albums/2']]], false, $callsB),
        ]);
        $u = Media::usages($id);
        $this->assertSame(['news', 'albums'], array_column($u, 'source'));
        $this->assertSame('Альбом «Осень»', $u[1]['label']);
        $this->assertSame([], Media::usages($free));

        $callsA = 0;
        $callsB = 0;
        $this->assertSame([$id => 2, $free => 0], Media::usageCounts([$id, $free]));
        $this->assertSame([1, 1], [$callsA, $callsB], 'один запрос на провайдера');
    }

    public function testDeleteBlockedWhenUsed(): void
    {
        $id = $this->add('a.jpg');
        Media::setProviders([$this->provider('news', [$id => [['label' => 'Новость', 'url' => '/n']]])]);
        try {
            Media::delete($id);
            $this->fail('нельзя удалять используемый файл');
        } catch (MediaInUseException $e) {
            $this->assertSame('Новость', $e->usages()[0]['label']);
        }
        $this->assertNotNull(Media::find($id));
        foreach ($this->files[$id] as $f) {
            $this->assertFileExists($f);
        }
        Media::setProviders([$this->provider('news', [])]);
        $this->assertSame($id, (int)Media::delete($id)['id']);
        $this->assertNull(Media::find($id));
        foreach ($this->files[$id] as $f) {
            $this->assertFileDoesNotExist($f);
        }
    }

    public function testDeleteBlockedWhenProviderFails(): void
    {
        $id = $this->add('a.jpg');
        Media::setProviders([$this->provider('news', [], true)]);
        $this->expectException(UsageCheckException::class);
        try {
            Media::delete($id);
        } finally {
            $this->assertNotNull(Media::find($id));
            $this->assertSame([$id => 1], Media::usageCounts([$id]));
        }
    }

    public function testForeignKeyRestrictIsTreatedAsInUse(): void
    {
        $id = $this->add('a.jpg');
        Database::connection()->exec('CREATE TABLE consumer (id INTEGER PRIMARY KEY, media_id INTEGER NOT NULL REFERENCES media(id) ON DELETE RESTRICT)');
        Database::insert('consumer', ['media_id' => $id]);
        Media::setProviders([]);
        $this->expectException(MediaInUseException::class);
        try {
            Media::delete($id);
        } finally {
            $this->assertNotNull(Media::find($id));
            $this->assertFileExists($this->files[$id][0]);
        }
    }

    public function testDeleteWithMissingFilesAndAuditCallback(): void
    {
        $id = $this->add('a.jpg');
        unlink($this->files[$id][0]);
        Media::setProviders([]);
        $seen = null;
        Media::delete($id, function (array $row) use (&$seen): void {
            $seen = (int)$row['id'];
        });
        $this->assertSame($id, $seen);
        $this->assertNull(Media::find($id));
        $this->assertNull(Media::delete($id), 'повторное удаление — null');
    }

    public function testUpdateAlt(): void
    {
        $id = $this->add('a.jpg');
        Media::updateAlt($id, '<b>x</b>');
        $this->assertSame('<b>x</b>', Media::find($id)['alt']);
    }

    public function testParseIdsAndPickerValues(): void
    {
        $this->assertSame([3, 1, 7], Media::parseIds(['3', '1', '3', 'x', '-4', '0', '7', ['a']]));
        $this->assertSame([5], Media::parseIds('5'));
        $this->assertSame([], Media::parseIds(null));
        $id = $this->add('a.jpg');
        $vals = Media::pickerValues([999, $id]);
        $this->assertNull($vals[0]['media']);
        $this->assertSame($id, (int)$vals[1]['media']['id']);
    }

    public function testDiskUsage(): void
    {
        Config::set(['disk_quota_mb' => null]);
        $this->assertSame(['used' => 0, 'total' => DiskUsage::summary()['total'], 'files' => 0], DiskUsage::summary());
        $a = $this->add('a.jpg', '', 'image', 1000);
        $this->add('b.jpg', '', 'image', 2345);
        $s = DiskUsage::summary();
        $this->assertSame([3345, 2], [$s['used'], $s['files']]);
        Media::setProviders([]);
        Media::delete($a);
        $this->assertSame(2345, DiskUsage::summary()['used']);

        Config::set(['disk_quota_mb' => 100]);
        $this->assertSame(104857600, DiskUsage::summary()['total']);
        $this->assertSame('Файлов: 1 · Занято 2 КБ из 100,0 МБ', DiskUsage::line(DiskUsage::summary()));
        $this->assertSame('Файлов: 0 · Занято 0 Б', DiskUsage::line(['used' => 0, 'total' => null, 'files' => 0]));
    }
}
