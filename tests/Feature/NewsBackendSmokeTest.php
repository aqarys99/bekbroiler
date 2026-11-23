<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Admin\NewsController;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\Slug;
use App\Core\View;
use App\Models\Media;
use App\Models\News;
use App\Models\NewsConflictException;
use Tests\AuthTestCase;

/** Быстрая проверка серверной части новостей (полный набор — у test-writer). */
final class NewsBackendSmokeTest extends AuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $dir = $this->tempDir();
        mkdir($dir . '/layouts');
        mkdir($dir . '/news');
        file_put_contents($dir . '/layouts/admin.php', '<?= $content ?>');
        foreach (['news/index', 'news/form', 'news/preview'] as $t) {
            file_put_contents($dir . '/' . $t . '.php', 'tpl:' . $t);
        }
        View::setPath($dir);
        $this->loginAs('editor');
    }

    protected function tearDown(): void
    {
        @unlink(Clock::testFile());
        parent::tearDown();
    }

    private function photo(string $type = 'image'): int
    {
        $h = bin2hex(random_bytes(8));
        return Media::create(['type' => $type, 'original_name' => $h . '.jpg', 'path' => 'images/' . $h, 'mime' => 'image/jpeg',
            'variants' => json_encode(['thumb' => ['w' => 1, 'h' => 1, 'jpg' => "images/$h-t.jpg", 'webp' => "images/$h-t.webp"]])]);
    }

    private function form(array $over = []): array
    {
        return $over + ['title_ru' => 'Осенний утренник', 'publish_mode' => 'now', 'intent' => 'submit', 'slug_auto' => '1', 'form_key' => bin2hex(random_bytes(16))];
    }

    private function create(array $over = []): array
    {
        $prep = News::prepare($this->form($over), null);
        $this->assertSame([], $prep['errors']);
        return News::save(null, $prep['data'], $this->editorId, null);
    }

    private function clock(string $now): void
    {
        file_put_contents(Clock::testFile(), json_encode(['now' => $now]));
    }

    public function testSlugAndClock(): void
    {
        $this->assertSame('konkurs-risunkov-menin-otanym', Slug::make('Конкурс рисунков «Менің Отаным»'));
        $this->assertSame('2026-11-25 04:00:00', Clock::fromLocal('2026-11-25', '09:00')?->format('Y-m-d H:i:s'));
        $this->assertNull(Clock::fromLocal('2026-02-30', '09:00'));
    }

    public function testUniqueSlugAndScheduleState(): void
    {
        $this->clock('2026-11-24 00:00:00');
        $a = $this->create();
        $b = $this->create();
        $this->assertSame('osenniy-utrennik', $a['slug']);
        $this->assertSame('osenniy-utrennik-2', $b['slug']);
        $c = $this->create(['publish_mode' => 'later', 'publish_date' => '2026-11-25', 'publish_time' => '09:00']);
        $this->assertSame('scheduled', $c['after']);
        $this->assertSame(2, News::published()['total']);
        $this->assertSame(['all' => 3, 'published' => 2, 'draft' => 0, 'scheduled' => 1], News::counts(''));
        $this->clock('2026-11-25 04:00:00');
        $this->assertSame(3, News::published()['total']);
        $this->assertNotNull(News::findPublishedBySlug($c['slug']));
        $this->assertCount(3, News::latest(3));
    }

    public function testScheduleInPastIsError(): void
    {
        $this->clock('2026-11-24 00:00:00');
        $prep = News::prepare($this->form(['publish_mode' => 'later', 'publish_date' => '2026-11-23', 'publish_time' => '09:00']), null);
        $this->assertArrayHasKey('publish_date', $prep['errors']);
    }

    public function testFormKeyIdempotentAndConflict(): void
    {
        $key = bin2hex(random_bytes(16));
        $a = $this->create(['form_key' => $key, 'intent' => 'draft']);
        $b = $this->create(['form_key' => $key, 'intent' => 'draft']);
        $this->assertSame($a['id'], $b['id']);
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM news'));
        $prep = News::prepare($this->form(['title_ru' => 'X']), News::find($a['id']));
        $this->expectException(NewsConflictException::class);
        News::save($a['id'], $prep['data'], $this->editorId, 1);
    }

    public function testMediaUsagesAndRestrict(): void
    {
        $cover = $this->photo();
        $g = $this->photo();
        $inText = $this->photo();
        $r = $this->create([
            'cover_media_id' => (string)$cover, 'photos' => [(string)$g, (string)$cover],
            'body_ru' => '<p>t</p><img data-media-id="' . $inText . '" src="x" onerror="1">',
        ]);
        $labels = array_column(Media::usages($cover), 'label');
        $this->assertSame(['Новость «Осенний утренник» (обложка, фото)'], $labels);
        $this->assertStringContainsString('в тексте', Media::usages($inText)[0]['label']);
        $this->assertSame([$g, $cover], array_column(News::find($r['id'])['photos'], 'id'));
        $this->assertNotNull(News::find($r['id']));
        $this->expectException(\PDOException::class);
        Database::query('DELETE FROM media WHERE id = ?', [$g]);
    }

    public function testBodyStoredSanitizedAndTranslation(): void
    {
        $r = $this->create([
            'body_ru' => '<p onclick="x">a<script>1</script></p>', 'title_kk' => 'Күзгі', 'body_kk' => '<p>ә</p>', 'excerpt_ru' => '<b>x</b>',
        ]);
        $n = News::find($r['id']);
        $this->assertSame('<p>a</p>', $n['body']);
        $this->assertSame('<b>x</b>', $n['excerpt']);
        $this->assertTrue(News::isTranslated($n));
        $this->assertSame(['total' => 1, 'translated' => 1], News::translationStats());
        // копия русского без изменений = нет перевода
        $copy = $this->create(['title_ru' => 'Тест', 'body_ru' => '<p>a</p>', 'title_kk' => 'Тест', 'body_kk' => '<p>a</p>']);
        $this->assertFalse(News::isTranslated(News::find($copy['id'])));
        $this->assertSame(['total' => 2, 'translated' => 1], News::translationStats());
        $list = News::list('күз', 'all', 1);
        $this->assertSame(1, $list['total']);
        $this->assertTrue($list['items'][0]['kk_ready']);
    }

    public function testDeleteCascadeFreesMedia(): void
    {
        $p = $this->photo();
        $r = $this->create(['photos' => [(string)$p]]);
        $this->assertNotNull(News::delete($r['id']));
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM news_media'));
        $this->assertNotNull(Media::delete($p));
    }

    public function testControllerStoreUpdateAutosaveAudit(): void
    {
        $c = new NewsController();
        $this->nextRequest();
        $this->post($this->form(['intent' => 'draft']));
        $r = $this->redirectOf(fn() => $c->store());
        $id = (int)Database::fetchValue('SELECT id FROM news');
        $this->assertSame('/admin/news/' . $id . '/edit', $r->url);

        $this->nextRequest();
        $this->post(['title_ru' => 'Новое', 'form_key' => bin2hex(random_bytes(16)), 'id' => (string)$id, 'version' => '1']);
        $out = json_decode($c->autosave(), true);
        $this->assertTrue($out['ok']);
        $this->assertSame(2, $out['version']);

        $this->nextRequest();
        $this->post($this->form(['version' => '1']));
        http_response_code(200);
        $c->update(['id' => (string)$id]);
        $this->assertSame(409, http_response_code());

        $this->nextRequest();
        $this->post($this->form(['version' => '2']));
        $r = $this->redirectOf(fn() => $c->update(['id' => (string)$id]));
        $this->assertSame('/admin/news', $r->url);

        $this->nextRequest();
        $this->post(['title_ru' => 'Z', 'id' => (string)$id, 'version' => '3']);
        $out = json_decode($c->autosave(), true);
        $this->assertSame('not_draft', $out['code']);

        $this->nextRequest();
        $this->post(['title_ru' => '']);
        $out = json_decode($c->autosave(), true);
        $this->assertSame('validation', $out['code']);

        $this->nextRequest();
        $this->post([]);
        $this->redirectOf(fn() => $c->delete(['id' => (string)$id]));
        $this->assertSame(
            ['news.create:' . $this->editorId . ':' . $id, 'news.update:' . $this->editorId . ':' . $id, 'news.publish:' . $this->editorId . ':' . $id,
                'news.delete:' . $this->editorId . ':' . $id],
            array_values(array_filter($this->audit(), static fn($a) => str_starts_with($a, 'news.')))
        );
    }

    public function testSanitizerCases(): void
    {
        $this->assertSame('<p>x <a href="mailto:a@b.kz">m</a>j</p>', HtmlSanitizer::clean('<p>x <a href="mailto:a@b.kz" onclick="1">m</a><a href="java&#9;script:1">j</a></p>'));
        $this->assertSame('', HtmlSanitizer::clean('<p><br></p><iframe src="x"></iframe>'));
        $once = HtmlSanitizer::clean('<div><b>x</b> <a href="https://a.kz">l</a></div><img src="x">');
        $this->assertSame($once, HtmlSanitizer::clean($once));
        $this->assertStringContainsString('rel="noopener noreferrer"', $once);
    }

    public function testPagesRender(): void
    {
        $c = new NewsController();
        $p = $this->photo();
        $r = $this->create(['cover_media_id' => (string)$p, 'photos' => [(string)$p]]);
        $this->nextRequest();
        $this->assertStringContainsString('tpl:news/index', $c->index());
        $this->assertStringContainsString('tpl:news/form', $c->create());
        $this->assertStringContainsString('tpl:news/form', $c->edit(['id' => (string)$r['id']]));
        $this->assertStringContainsString('tpl:news/preview', $c->preview(['id' => (string)$r['id']]));
        $this->post(['title_ru' => 'T', 'body_ru' => '<p>x</p>', 'body_kk' => '<script>1</script>']);
        $this->assertStringContainsString('tpl:news/preview', $c->previewDraft());
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM news'));
        $_GET = ['text' => 'Осенний утренник', 'manual' => '1'];
        $j = json_decode($c->slug(), true);
        $this->assertTrue($j['taken']);
        $this->assertStringContainsString('src=', News::bodyForEditor('<img data-media-id="' . $p . '">'));
        $this->assertStringContainsString('<picture>', News::renderBody('<p>a</p><img data-media-id="' . $p . '">', 'thumb'));
    }
}
