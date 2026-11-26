<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\DocumentUploader;
use App\Core\Migrator;
use App\Models\Attestation;
use App\Models\MenuItem;
use App\Models\Page;
use Tests\TestCase;

/** Фича 15.1: раздел «Аттестация» (pages.kind, порядок, меню, загрузка нескольких файлов без JS). */
final class AttestationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freshDatabase();
    }

    private function pdf(string $text = 'x'): string
    {
        $f = $this->tempDir() . '/' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($f, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF " . $text);
        return $f;
    }

    private function docx(): string
    {
        $f = $this->tempDir() . '/' . bin2hex(random_bytes(4)) . '.docx';
        $z = new \ZipArchive();
        $z->open($f, \ZipArchive::CREATE);
        $z->addFromString('[Content_Types].xml', '<Types/>');
        $z->addFromString('word/document.xml', '<w/>');
        $z->close();
        return $f;
    }

    private function uploader(): DocumentUploader
    {
        return new DocumentUploader($this->tempDir(), static fn(string $f): bool => is_file($f), static fn(string $a, string $b): bool => copy($a, $b));
    }

    public function testMigrationMarksAttestationPages(): void
    {
        $this->assertSame('regular', Database::fetchValue("SELECT kind FROM pages WHERE slug = 'o-nas'"));
        foreach (['attestatsiya', 'monitoring', 'perspektivnyy-plan', 'godovoy-plan', 'otchety'] as $slug) {
            $this->assertSame('attestation', Database::fetchValue('SELECT kind FROM pages WHERE slug = ?', [$slug]), $slug);
        }
        $this->assertCount(0, array_filter(Page::search(), static fn(array $p): bool => $p['kind'] !== 'regular'));
        $this->assertGreaterThan(0, count(Attestation::pages()));
    }

    public function testMigrationMovesPagesFromAttestationMenuGroup(): void
    {
        $dir = $this->tempDir();
        foreach (glob(ROOT . '/migrations/*.sql') as $f) {
            if ((int)basename($f) <= 13) {
                copy($f, $dir . '/' . basename($f));
            }
        }
        $pdo = Database::open($this->tempDir() . '/old.sqlite');
        Database::setConnection($pdo);
        (new Migrator($pdo, $dir))->migrate();
        $pid = Database::insert('pages', ['slug' => 'moya', 'title' => 'Моя', 'title_kk' => '', 'body' => '', 'body_kk' => '', 'is_published' => 1, 'sort' => 50]);
        $gid = (int)Database::fetchValue("SELECT id FROM menu_items WHERE type = 'group'");
        Database::insert('menu_items', ['menu' => 'main', 'parent_id' => $gid, 'sort' => 99, 'type' => 'page', 'page_id' => $pid]);
        $rid = Database::insert('pages', ['slug' => 'prosto', 'title' => 'Просто', 'title_kk' => '', 'body' => '', 'body_kk' => '', 'is_published' => 1, 'sort' => 51]);
        copy(ROOT . '/migrations/014_pages_kind.sql', $dir . '/014_pages_kind.sql');
        (new Migrator($pdo, $dir))->migrate();
        $this->assertSame('attestation', Database::fetchValue('SELECT kind FROM pages WHERE id = ?', [$pid]));
        $this->assertSame('regular', Database::fetchValue('SELECT kind FROM pages WHERE id = ?', [$rid]));
    }

    public function testCreatePageAddsGroupItemAndCreatesGroupIfMissing(): void
    {
        $prep = Attestation::prepare(['title' => 'Годовой план 2026', 'title_kk' => '', 'is_published' => '1']);
        $this->assertSame([], $prep['errors']);
        $id = Attestation::create($prep['data'], true);
        $row = Page::find($id);
        $this->assertSame('attestation', $row['kind']);
        $this->assertSame('', $row['body']);
        $gid = MenuItem::attestationGroupId();
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM menu_items WHERE page_id = ? AND parent_id = ?', [$id, $gid]));

        Database::query('DELETE FROM menu_items WHERE parent_id = ?', [$gid]);
        Database::query('DELETE FROM menu_items WHERE id = ?', [$gid]);
        $this->assertNull(MenuItem::attestationGroupId());
        $id2 = Attestation::create(Attestation::prepare(['title' => 'Вторая'])['data'], true);
        $this->assertNotNull(MenuItem::attestationGroupId());
        $id3 = Attestation::create(Attestation::prepare(['title' => 'Без меню'])['data'], false);
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM menu_items WHERE page_id = ?', [$id3]));
        $this->assertNotNull($id2);
        $this->assertNotSame([], Attestation::prepare(['title' => ' '])['errors']);
    }

    public function testMultipleFilesWithoutJs(): void
    {
        $id = Attestation::create(Attestation::prepare(['title' => 'Пачка'])['data'], true);
        $a = $this->pdf('a');
        $b = $this->pdf('b');
        $c = $this->docx();
        $m = $this->pdf('m');
        $bad = $this->tempDir() . '/evil.php';
        file_put_contents($bad, '<?php echo 1;');
        $mk = static fn(array $files): array => [
            'name' => array_column($files, 0), 'type' => array_fill(0, count($files), ''), 'tmp_name' => array_column($files, 1),
            'error' => array_fill(0, count($files), UPLOAD_ERR_OK), 'size' => array_map(static fn(array $f): int => (int)filesize($f[1]), $files),
        ];
        $post = ['titles' => ['План первый', '', 'Docx', ''], 'titles_kk' => ['', '', '', ''], 'doc_dates' => ['', '', '', ''],
            'valid_untils' => ['', '', '', ''], 'checklist_keys' => ['', '', '', ''], 'view_modes' => ['book', '', '', '']];
        $files = ['files' => $mk([['a.pdf', $a], ['Годовой_план_2026.pdf', $b], ['c.docx', $c], ['evil.php', $bad]]),
                  'multi' => $mk([['из_пачки_файл.pdf', $m]])];
        $rows = Attestation::rowsFromRequest($post, $files);
        $this->assertCount(5, $rows);
        $res = Attestation::addFiles($id, $rows, null, $this->uploader());
        $this->assertSame(4, $res['ok']);
        $this->assertSame(5, $res['total']);
        $this->assertCount(1, $res['errors']);
        $docs = \App\Models\Document::all($id);
        $this->assertSame(['План первый', 'Годовой план 2026', 'Docx', 'из пачки файл'], array_column($docs, 'title'));
        $this->assertSame('book', $docs[0]['view_mode']);
        $this->assertSame('reader', $docs[2]['view_mode']);
        $this->assertSame([1, 2, 3, 4], array_map('intval', array_column($docs, 'sort')));
    }

    public function testTitleFromFilename(): void
    {
        $this->assertSame('Годовой план 2026', Attestation::titleFromFilename('Годовой_план_2026.pdf'));
        $this->assertSame('a b', Attestation::titleFromFilename('C:\\x\\a__b.docx'));
    }

    public function testOrderSyncsWithMenuBothWays(): void
    {
        $ids = [];
        foreach (['Аа', 'Бб', 'Вв'] as $t) {
            $ids[$t] = Attestation::create(Attestation::prepare(['title' => $t])['data'], true);
        }
        $order = static fn(): array => array_map('intval', array_column(Database::fetchAll("SELECT page_id FROM menu_items WHERE parent_id = ? AND page_id IN ({$ids['Аа']},{$ids['Бб']},{$ids['Вв']}) ORDER BY sort, id", [MenuItem::attestationGroupId()]), 'page_id'));
        $this->assertSame([$ids['Аа'], $ids['Бб'], $ids['Вв']], $order());
        $this->assertTrue(Attestation::move($ids['Вв'], -1));
        $this->assertSame([$ids['Аа'], $ids['Вв'], $ids['Бб']], $order());
        $pages = array_map('intval', array_column(Attestation::pages(), 'id'));
        $this->assertSame([$ids['Аа'], $ids['Вв'], $ids['Бб']], array_slice($pages, -3));

        // владелец двигает в «Меню» — побеждает последнее действие
        $tree = MenuItem::tree('main');
        foreach ($tree as &$top) {
            if ($top['type'] === 'group') {
                $top['children'] = array_reverse($top['children']);
            }
        }
        unset($top);
        $norm = [];
        foreach ($tree as $t) {
            $norm[] = ['type' => $t['type'], 'page_id' => $t['page_id'], 'section_key' => $t['section_key'], 'url' => $t['url'], 'title_ru' => $t['title_ru'],
                'title_kk' => $t['title_kk'], 'target_blank' => 0, 'children' => array_map(static fn(array $c): array => $c + ['children' => []], $t['children'])];
        }
        MenuItem::replace('main', $norm);
        $pages = array_map('intval', array_column(Attestation::pages(), 'id'));
        $this->assertSame([$ids['Бб'], $ids['Вв'], $ids['Аа']], array_values(array_intersect($pages, [$ids['Бб'], $ids['Вв'], $ids['Аа']])));
    }

    public function testDeleteAttestationPageRemovesMenuItem(): void
    {
        $id = Attestation::create(Attestation::prepare(['title' => 'Удалить'])['data'], true);
        Page::delete($id);
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM menu_items WHERE page_id = ?', [$id]));
    }
}
