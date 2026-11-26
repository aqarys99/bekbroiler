<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Models\Document;
use App\Models\MenuItem;
use App\Models\Page;
use Tests\TestCase;

/** Фича 15: страницы, документы страницы и меню сайта. */
final class PagesMenuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freshDatabase();
    }

    public function testSeedMenuAndPages(): void
    {
        $main = MenuItem::tree('main');
        $titles = array_map(static fn(array $i): string => (string)($i['type'] === 'group' ? $i['title_ru'] : ($i['page_title'] ?? $i['section_key'])), $main);
        $this->assertSame(['home', 'О нас', 'Аттестация', 'news', 'gallery', 'staff', 'contacts'], $titles);
        $this->assertCount(7, $main[2]['children']);
        $this->assertNotNull(Database::fetch("SELECT 1 FROM pages WHERE slug = 'monitoring'"));
        $this->assertCount(6, MenuItem::tree('footer'));
    }

    public function testSlugUniqueAndReserved(): void
    {
        $this->assertSame('monitoring-2', Page::uniqueSlug('monitoring'));
        $this->assertSame('dokumenty-2', Page::uniqueSlug('dokumenty'));
        $this->assertSame('stranitsa', Page::slugFromTitle('???'));
    }

    public function testDeletePageRemovesMenuItemAndDocuments(): void
    {
        $pid = (int)Database::fetchValue("SELECT id FROM pages WHERE slug = 'monitoring'");
        $mid = Database::insert('media', ['type' => 'file', 'original_name' => 'a.pdf', 'path' => 'docs/x/a.pdf', 'mime' => 'application/pdf',
            'size' => 5, 'source_size' => 5, 'variants' => '{}']);
        Document::create(['title' => 'A', 'title_kk' => '', 'page_id' => $pid, 'checklist_key' => null, 'doc_date' => null, 'valid_until' => null, 'view_mode' => 'reader'], $mid, null);
        $this->assertCount(1, Document::all($pid));
        $this->assertNotNull(Page::delete($pid));
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM menu_items WHERE page_id = ?', [$pid]));
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM documents WHERE page_id = ?', [$pid]));
    }

    public function testMenuValidation(): void
    {
        $ok = MenuItem::normalize([['type' => 'group', 'title_ru' => 'Г', 'children' => [['type' => 'link', 'title_ru' => 'Л', 'url' => '/kontakty']]]]);
        $this->assertSame([], $ok['errors']);
        $deep = MenuItem::normalize([['type' => 'group', 'title_ru' => 'A', 'children' => [['type' => 'group', 'title_ru' => 'B', 'children' => [['type' => 'group', 'title_ru' => 'C']]]]]]);
        $this->assertNotSame([], $deep['errors']);
        foreach (['javascript:alert(1)', '//evil.com', 'ftp://x.y'] as $u) {
            $this->assertNotSame([], MenuItem::normalize([['type' => 'link', 'title_ru' => 'x', 'url' => $u]])['errors'], $u);
        }
        $this->assertNotSame([], MenuItem::normalize([['type' => 'page', 'page_id' => 99999]])['errors']);
        $this->assertNotSame([], MenuItem::normalize([['type' => 'section', 'section_key' => 'nope']])['errors']);
    }

    public function testReplaceMenu(): void
    {
        $res = MenuItem::normalize([['type' => 'section', 'section_key' => 'news'], ['type' => 'group', 'title_ru' => 'Г', 'children' => [['type' => 'section', 'section_key' => 'staff']]]]);
        MenuItem::replace('footer', $res['items']);
        $t = MenuItem::tree('footer');
        $this->assertCount(2, $t);
        $this->assertCount(1, $t[1]['children']);
        $this->assertGreaterThan(0, count(MenuItem::tree('main')));
    }
}
