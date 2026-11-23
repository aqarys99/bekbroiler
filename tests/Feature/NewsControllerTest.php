<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Admin\NewsController;
use App\Core\Auth;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Guard;
use App\Core\HttpException;
use App\Core\Router;
use App\Core\View;
use App\Models\Media;
use App\Models\News;
use Tests\AuthTestCase;
use Tests\Support\NewsHelpers;

/** Контроллер новостей: коды ответов, права ролей, CSRF, предпросмотр, автосохранение, аудит. */
final class NewsControllerTest extends AuthTestCase
{
    use NewsHelpers;

    private NewsController $c;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = $this->tempDir();
        mkdir($dir . '/layouts');
        mkdir($dir . '/news');
        file_put_contents($dir . '/layouts/admin.php', '<?= $content ?>');
        file_put_contents($dir . '/news/index.php', '<?= json_encode(["tpl" => "index", "items" => array_column($items, "title"), "status" => $status, "page" => $page, "counts" => $counts]) ?>');
        file_put_contents($dir . '/news/form.php', '<?= json_encode(["tpl" => "form", "errors" => $errors, "conflict" => $conflict, "state" => $state, "tab" => $activeTab,'
            . ' "values" => $values, "autosave" => $autosave, "labels" => $submitLabels, "pub" => $pubCurrent, "tz" => $tzHint, "min" => $minDate]) ?>');
        file_put_contents($dir . '/news/preview.php', '<?= json_encode(["tpl" => "preview", "banner" => $banner, "langs" => $langs, "date" => $date, "unsaved" => $unsaved, "cover" => $coverHtml, "photos" => $photosHtml]) ?>');
        View::setPath($dir);
        $this->c = new NewsController();
        $this->loginAs('editor');
    }

    protected function tearDown(): void
    {
        @unlink(Clock::testFile());
        parent::tearDown();
    }

    private function out(string $html): array
    {
        $j = json_decode($html, true);
        $this->assertIsArray($j, $html);
        return $j;
    }

    private function code(): int
    {
        return (int)http_response_code();
    }

    private function store(array $over = []): \App\Core\RedirectException
    {
        $this->nextRequest();
        $this->post($this->form($over));
        return $this->redirectOf(fn() => $this->c->store());
    }

    private function newsId(): int
    {
        return (int)Database::fetchValue('SELECT MAX(id) FROM news');
    }

    // ---------------------------------------------------------------- роли и охрана

    public function testEveryNewsRouteIsGuardedAsEditor(): void
    {
        $r = new Router();
        (require ROOT . '/app/routes.php')($r);
        $seen = 0;
        foreach ($r->routes() as $route) {
            if (!str_contains($route['pattern'], '/admin/news')) {
                continue;
            }
            $seen++;
            $this->assertSame('editor', Guard::roleOf($route['handler']), $route['method'] . ' ' . $route['pattern']);
        }
        $this->assertSame(10, $seen);
    }

    public function testEditorCanDoEverythingOnForeignNews(): void
    {
        $owner = \App\Models\User::findByLogin('owner');
        $prep = News::prepare($this->form(['title_ru' => 'Чужая']), null);
        $res = News::save(null, $prep['data'], (int)$owner['id'], null);
        $id = $res['id'];
        $this->nextRequest();
        $this->assertSame('form', $this->out($this->c->edit(['id' => (string)$id]))['tpl']);
        $this->nextRequest();
        $this->post($this->form(['title_ru' => 'Правка редактора', 'publish_mode' => 'draft', 'version' => '1']));
        $this->redirectOf(fn() => $this->c->update(['id' => (string)$id]));
        $this->assertSame('Правка редактора', News::find($id)['title']);
        $this->assertSame('draft', News::find($id)['state']);
        $this->nextRequest();
        $this->post([]);
        $this->redirectOf(fn() => $this->c->delete(['id' => (string)$id]));
        $this->assertNull(News::find($id));
        $actions = array_values(array_filter($this->audit(), static fn($a) => str_starts_with($a, 'news.')));
        $this->assertContains('news.delete:' . $this->editorId . ':' . $id, $actions, 'автор действия — editor');
        $this->assertContains('news.unpublish:' . $this->editorId . ':' . $id, $actions);
    }

    public function testOwnerPassesGuardToo(): void
    {
        Auth::reset();
        $_SESSION = [];
        $this->loginAs('owner');
        $h = Guard::wrap([NewsController::class, 'index'], 'editor');
        $this->nextRequest();
        $this->assertSame('index', $this->out($h([]))['tpl']);
    }

    public function testGuestRedirectedToLoginAndJsonGets401(): void
    {
        Auth::reset();
        $_SESSION = [];
        $h = Guard::wrap([NewsController::class, 'index'], 'editor');
        $r = $this->redirectOf(fn() => $h([]));
        $this->assertStringContainsString('/admin/login', $r->url);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
        $out = json_decode(Guard::wrap([NewsController::class, 'autosave'])([]), true);
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $this->assertSame(401, $this->code());
        $this->assertFalse($out['ok']);
    }

    public function testPostWithoutCsrfIs400(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->form();
        foreach (['store', 'update', 'delete', 'previewDraft'] as $action) {
            $h = Guard::wrap([NewsController::class, $action]);
            try {
                $h(['id' => '1']);
                $this->fail("$action без CSRF прошёл");
            } catch (HttpException $e) {
                $this->assertSame(400, $e->getStatusCode(), $action);
            }
        }
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
        $out = json_decode(Guard::wrap([NewsController::class, 'autosave'])([]), true);
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
        $this->assertSame(400, $this->code());
        $this->assertSame('csrf', $out['code']);
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM news'));
    }

    public function testCsrfHeaderAcceptedForAutosave(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token();
        $_POST = ['title_ru' => 'Через заголовок', 'form_key' => News::newFormKey()];
        $out = json_decode(Guard::wrap([NewsController::class, 'autosave'])([]), true);
        unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_X_CSRF_TOKEN']);
        $this->assertTrue($out['ok']);
    }

    // ---------------------------------------------------------------- список

    public function testIndexFiltersAndClampsInput(): void
    {
        $this->clock('2026-11-24 00:00:00');
        foreach (range(1, 22) as $i) {
            $this->create(['title_ru' => "Н$i"]);
        }
        $this->create(['title_ru' => 'Чернь', 'publish_mode' => 'draft']);
        $this->nextRequest();
        $o = $this->out($this->c->index());
        $this->assertSame([1, 'all'], [$o['page'], $o['status']]);
        $this->assertCount(20, $o['items']);
        foreach ([['status' => 'xxx'], ['status' => ['a']], ['status' => '']] as $get) {
            $this->nextRequest();
            $_GET = $get;
            $this->assertSame('all', $this->out($this->c->index())['status']);
        }
        $this->nextRequest();
        $_GET = ['page' => '999'];
        $this->assertSame(2, $this->out($this->c->index())['page']);
        foreach (['abc', '-1', '0', '1e9', ['1']] as $bad) {
            $this->nextRequest();
            $_GET = ['page' => $bad];
            $this->assertSame(1, $this->out($this->c->index())['page'], var_export($bad, true));
        }
        $this->nextRequest();
        $_GET = ['status' => 'draft', 'q' => '  чер  '];
        $o = $this->out($this->c->index());
        $this->assertSame(['Чернь'], $o['items']);
        $this->assertSame(1, $o['counts']['all']);
        $this->nextRequest();
        $_GET = ['q' => ['x']];
        $this->assertSame(200, $this->code() ?: 200);
        $this->out($this->c->index());
    }

    // ---------------------------------------------------------------- создание

    public function testCreateFormDefaults(): void
    {
        $this->nextRequest();
        $o = $this->out($this->c->create());
        $this->assertSame(200, $this->code());
        $this->assertSame('new', $o['state']);
        $this->assertSame('now', $o['values']['publish_mode']);
        $this->assertSame('09:00', $o['values']['publish_time']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $o['values']['form_key']);
        $this->assertTrue($o['autosave']);
        $this->assertSame('Время по часовому поясу Asia/Almaty (UTC+05:00)', $o['tz']);
        $this->assertSame('Опубликовать', $o['labels']['now']);
        $this->assertSame('Запланировать', $o['labels']['later']);
        $this->assertSame('Сохранить черновик', $o['labels']['draft']);
        $this->assertSame('', $o['pub']);
        $this->nextRequest();
        $this->assertNotSame($o['values']['form_key'], $this->out($this->c->create())['values']['form_key'], 'ключ новый при каждом открытии');
    }

    public function testStoreRedirectsAndFlashByMode(): void
    {
        $this->clock('2026-11-20 00:00:00');
        $r = $this->store();
        $this->assertSame('/admin/news', $r->url);
        $this->assertFlash('news.flash.published');
        $r = $this->store(['intent' => 'draft']);
        $this->assertSame('/admin/news/' . $this->newsId() . '/edit', $r->url);
        $this->assertFlash('news.flash.draft');
        $this->store(['publish_mode' => 'later', 'publish_date' => '2026-11-25', 'publish_time' => '09:00']);
        $this->assertFlash('news.flash.scheduled');
        $this->store(['publish_mode' => 'draft']);
        $this->assertFlash('news.flash.draft');
    }

    /** Текст тоста (Flash хранит уже переведённую строку). */
    private function flash(): string
    {
        return (string)($_SESSION['_flash']['text'] ?? '');
    }

    private function assertFlash(string $key): void
    {
        $expected = t($key, ['date' => '', 'time' => '']);
        $this->assertStringStartsWith(trim(explode(':date', $expected)[0]), $this->flash(), $key);
        $this->assertNotSame('', $this->flash());
    }

    public function testStoreInvalidIs422WithEnteredData(): void
    {
        $this->nextRequest();
        $this->post($this->form(['title_ru' => '', 'title_kk' => 'Қазақша', 'excerpt_ru' => 'Описание', 'publish_mode' => 'later', 'publish_date' => '2000-01-01']));
        $o = $this->out($this->c->store());
        $this->assertSame(422, $this->code());
        $this->assertSame('Введите заголовок', $o['errors']['title_ru']);
        $this->assertSame('Укажите будущие дату и время', $o['errors']['publish_date']);
        $this->assertSame('Қазақша', $o['values']['title_kk']);
        $this->assertSame('Описание', $o['values']['excerpt_ru']);
        $this->assertSame('later', $o['values']['publish_mode']);
        $this->assertSame('ru', $o['tab']);
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM news'));
    }

    public function testKazakhErrorActivatesKkTab(): void
    {
        $this->nextRequest();
        $this->post($this->form(['title_kk' => str_repeat('ә', 121)]));
        $o = $this->out($this->c->store());
        $this->assertSame(422, $this->code());
        $this->assertSame('kk', $o['tab']);
        $this->nextRequest();
        $this->post($this->form(['title_ru' => '', 'title_kk' => str_repeat('ә', 121)]));
        $this->assertSame('ru', $this->out($this->c->store())['tab'], 'ошибки в обоих — русская вкладка');
    }

    public function testDoubleSubmitSameFormKeyMakesOneRow(): void
    {
        $key = News::newFormKey();
        $this->store(['form_key' => $key]);
        $this->store(['form_key' => $key]);
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM news'));
    }

    // ---------------------------------------------------------------- правка

    public function testEditAndPreviewUnknownIdAre404(): void
    {
        foreach (['edit', 'preview', 'update', 'delete'] as $m) {
            $this->nextRequest();
            $this->post($this->form(['version' => '1']));
            try {
                $this->c->$m(['id' => '9999']);
                $this->fail("$m: ожидался 404");
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode(), $m);
            }
        }
    }

    public function testEditShowsCurrentStateTexts(): void
    {
        $this->clock('2026-11-20 10:00:00');
        $this->store(['publish_mode' => 'later', 'publish_date' => '2026-11-25', 'publish_time' => '09:00']);
        $id = $this->newsId();
        $this->nextRequest();
        $o = $this->out($this->c->edit(['id' => (string)$id]));
        $this->assertSame('scheduled', $o['state']);
        $this->assertSame('Запланирована на 25.11.2026 в 09:00', $o['pub']);
        $this->assertSame('later', $o['values']['publish_mode']);
        $this->assertSame(['2026-11-25', '09:00'], [$o['values']['publish_date'], $o['values']['publish_time']]);
        $this->assertSame('Сохранить изменения', $o['labels']['later']);
        $this->assertFalse($o['autosave'], 'у запланированной автосохранения нет');
        $this->clock('2026-11-25 04:00:00');
        $this->nextRequest();
        $o = $this->out($this->c->edit(['id' => (string)$id]));
        $this->assertSame('published', $o['state']);
        $this->assertSame('Опубликована 25.11.2026 в 09:00', $o['pub']);
        $this->assertSame('Снять с публикации', $o['labels']['draft']);
    }

    public function testUpdateStale409KeepsEnteredDataAndDb(): void
    {
        $this->store(['title_ru' => 'Исходная']);
        $id = $this->newsId();
        $this->nextRequest();
        $this->post($this->form(['title_ru' => 'Вкладка 1', 'version' => '1']));
        $this->redirectOf(fn() => $this->c->update(['id' => (string)$id]));
        $this->nextRequest();
        $this->post($this->form(['title_ru' => 'Вкладка 2', 'title_kk' => 'Мой текст', 'version' => '1']));
        $o = $this->out($this->c->update(['id' => (string)$id]));
        $this->assertSame(409, $this->code());
        $this->assertTrue($o['conflict']);
        $this->assertSame('Мой текст', $o['values']['title_kk']);
        $this->assertSame('Вкладка 1', News::find($id)['title']);
        foreach (['', 'abc', '-1', '1; DROP TABLE news'] as $badVersion) {
            $this->nextRequest();
            $this->post($this->form(['title_ru' => 'Х', 'version' => $badVersion]));
            $this->out($this->c->update(['id' => (string)$id]));
            $this->assertSame(409, $this->code(), var_export($badVersion, true));
        }
        $this->assertSame('Вкладка 1', News::find($id)['title']);
    }

    public function testUpdateInvalid422KeepsDb(): void
    {
        $this->store(['title_ru' => 'Исходная']);
        $id = $this->newsId();
        $this->nextRequest();
        $this->post($this->form(['title_ru' => '', 'version' => '1']));
        $o = $this->out($this->c->update(['id' => (string)$id]));
        $this->assertSame(422, $this->code());
        $this->assertArrayHasKey('title_ru', $o['errors']);
        $this->assertSame('Исходная', News::find($id)['title']);
        $this->assertSame(1, (int)News::find($id)['version']);
    }

    public function testUpdateFlashAndRedirectByTransition(): void
    {
        $this->clock('2026-11-20 10:00:00');
        $this->store(['publish_mode' => 'draft']);
        $id = $this->newsId();
        $v = 1;
        $cases = [
            ['publish_mode' => 'now', 'intent' => 'submit', 'flash' => 'news.flash.published', 'url' => '/admin/news'],
            ['publish_mode' => 'now', 'intent' => 'submit', 'flash' => 'news.flash.saved', 'url' => '/admin/news'],
            ['publish_mode' => 'draft', 'intent' => 'submit', 'flash' => 'news.flash.unpublished', 'url' => '/admin/news'],
            ['publish_mode' => 'draft', 'intent' => 'submit', 'flash' => 'news.flash.draft', 'url' => '/admin/news'],
            ['publish_mode' => 'now', 'intent' => 'draft', 'flash' => 'news.flash.draft', 'url' => '/admin/news/' . $id . '/edit'],
        ];
        foreach ($cases as $c) {
            $this->nextRequest();
            $this->post($this->form(['publish_mode' => $c['publish_mode'], 'intent' => $c['intent'], 'version' => (string)$v]));
            $r = $this->redirectOf(fn() => $this->c->update(['id' => (string)$id]));
            $this->assertSame($c['url'], $r->url, $c['flash']);
            $this->assertFlash($c['flash']);
            $v++;
        }
    }

    // ---------------------------------------------------------------- автосохранение

    private function autosave(array $post): array
    {
        $this->nextRequest();
        $this->post($post);
        $out = json_decode($this->c->autosave(), true);
        $this->assertIsArray($out);
        return $out;
    }

    public function testAutosaveCreatesOnceThenUpdates(): void
    {
        $key = News::newFormKey();
        $a = $this->autosave(['title_ru' => 'Ч1', 'form_key' => $key, 'publish_mode' => 'now']);
        $this->assertTrue($a['ok']);
        $this->assertSame(200, $this->code());
        $this->assertSame(1, $a['version']);
        $this->assertMatchesRegularExpression('/^\d\d:\d\d$/', $a['saved_at']);
        $this->assertSame('/admin/news/' . $a['id'] . '/edit', $a['edit_url']);
        $this->assertSame('/admin/news/' . $a['id'], $a['update_url']);
        $this->assertSame('draft', News::find($a['id'])['state'], 'автосохранение — всегда черновик, даже при режиме «now»');
        $b = $this->autosave(['title_ru' => 'Ч2', 'form_key' => $key]);
        $this->assertSame($a['id'], $b['id'], 'тот же form_key — та же запись (параллельное автосохранение)');
        $c = $this->autosave(['title_ru' => 'Ч3', 'form_key' => $key, 'id' => (string)$a['id'], 'version' => (string)$b['version']]);
        $this->assertSame(3, $c['version']);
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM news'));
        $actions = array_values(array_filter($this->audit(), static fn($x) => str_starts_with($x, 'news.')));
        $this->assertSame(['news.create:' . $this->editorId . ':' . $a['id']], $actions, 'автосохранение журналирует только создание');
    }

    public function testAutosaveErrorCodes(): void
    {
        $o = $this->autosave(['title_ru' => '', 'form_key' => News::newFormKey()]);
        $this->assertSame([422, 'validation'], [$this->code(), $o['code']]);
        $this->assertArrayHasKey('title_ru', $o['errors']);
        $o = $this->autosave(['title_ru' => 'X', 'id' => '9999', 'version' => '1']);
        $this->assertSame([404, 'not_found'], [$this->code(), $o['code']]);
        $o = $this->autosave(['title_ru' => 'X', 'id' => 'abc', 'version' => '1']);
        $this->assertSame([404, 'not_found'], [$this->code(), $o['code']]);
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM news'));
    }

    public function testAutosaveConflictAndNotDraft(): void
    {
        $a = $this->autosave(['title_ru' => 'Ч', 'form_key' => News::newFormKey()]);
        $this->autosave(['title_ru' => 'Ч2', 'id' => (string)$a['id'], 'version' => '1']);
        $o = $this->autosave(['title_ru' => 'Старая', 'id' => (string)$a['id'], 'version' => '1']);
        $this->assertSame([409, 'conflict'], [$this->code(), $o['code']]);
        $this->assertSame('Ч2', News::find($a['id'])['title']);

        $this->nextRequest();
        $this->post($this->form(['version' => '2']));
        $this->redirectOf(fn() => $this->c->update(['id' => (string)$a['id']]));
        $o = $this->autosave(['title_ru' => 'Перезапись опубликованной', 'id' => (string)$a['id'], 'version' => '3']);
        $this->assertSame([409, 'not_draft'], [$this->code(), $o['code']]);
        $this->assertSame('Осенний утренник', News::find($a['id'])['title'], 'опубликованная новость автосохранением не меняется');
        $this->assertSame('published', News::find($a['id'])['state']);
    }

    public function testAutosaveRefusesScheduled(): void
    {
        $this->clock('2026-11-20 00:00:00');
        $r = $this->create(['publish_mode' => 'later', 'publish_date' => '2026-11-25', 'publish_time' => '09:00']);
        $o = $this->autosave(['title_ru' => 'X', 'id' => (string)$r['id'], 'version' => '1']);
        $this->assertSame('not_draft', $o['code']);
        $this->assertSame('scheduled', News::find($r['id'])['state']);
    }

    // ---------------------------------------------------------------- удаление

    public function testDeleteRedirectKeepsQueryAndGoesToPreviousPageWhenEmpty(): void
    {
        $this->clock('2026-11-24 00:00:00');
        foreach (range(1, 21) as $i) {
            $this->clock(sprintf('2026-11-24 00:00:%02d', $i));
            $this->create(['title_ru' => "Н$i"]);
        }
        $id = $this->newsId();
        $this->nextRequest();
        $this->post(['page' => '2', 'q' => 'н', 'status' => 'published']);
        // на 2-й странице одна запись (Н1); удаляем её — страница опустела
        $first = (int)Database::fetchValue("SELECT id FROM news WHERE title = 'Н1'");
        $r = $this->redirectOf(fn() => $this->c->delete(['id' => (string)$first]));
        $this->assertSame('/admin/news?q=%D0%BD&status=published', $r->url);
        $this->assertFlash('news.flash.deleted');
        $this->nextRequest();
        $this->post(['page' => 'abc', 'status' => 'xxx']);
        $r = $this->redirectOf(fn() => $this->c->delete(['id' => (string)$id]));
        $this->assertSame('/admin/news', $r->url);
    }

    public function testDeleteFreesPhotosAndAudits(): void
    {
        $p = $this->photo();
        $r = $this->create(['cover_media_id' => (string)$p]);
        $this->nextRequest();
        $this->post([]);
        $this->redirectOf(fn() => $this->c->delete(['id' => (string)$r['id']]));
        $this->assertSame([], Media::usages($p));
        $this->assertContains('news.delete:' . $this->editorId . ':' . $r['id'], $this->audit());
        // повторное удаление (в другой вкладке) -> 404
        $this->nextRequest();
        $this->post([]);
        $this->expectException(HttpException::class);
        $this->c->delete(['id' => (string)$r['id']]);
    }

    // ---------------------------------------------------------------- slug JSON

    public function testSlugEndpoint(): void
    {
        $this->create(['title_ru' => 'Праздник']);
        $this->nextRequest();
        $_GET = ['text' => '   '];
        $this->assertSame(['slug' => '', 'taken' => false], json_decode($this->c->slug(), true));
        $this->nextRequest();
        $_GET = ['text' => 'Праздник'];
        $j = json_decode($this->c->slug(), true);
        $this->assertSame(['prazdnik-2', false], [$j['slug'], $j['taken']]);
        $this->nextRequest();
        $_GET = ['text' => 'Праздник', 'manual' => '1'];
        $j = json_decode($this->c->slug(), true);
        $this->assertSame(['prazdnik', true, 'Праздник'], [$j['slug'], $j['taken'], $j['taken_by']]);
        $this->nextRequest();
        $_GET = ['text' => 'Праздник', 'manual' => '1', 'id' => '1'];
        $this->assertFalse(json_decode($this->c->slug(), true)['taken'], 'своя новость не конфликтует');
        $this->nextRequest();
        $_GET = ['text' => ['x'], 'id' => ['1']];
        $this->assertSame('', json_decode($this->c->slug(), true)['slug']);
        $this->nextRequest();
        $_GET = ['text' => str_repeat('ж', 1000)];
        $this->assertLessThanOrEqual(80, strlen(json_decode($this->c->slug(), true)['slug']));
    }

    // ---------------------------------------------------------------- предпросмотр

    public function testPreviewDraftDoesNotTouchDbAndTolerantToInvalid(): void
    {
        $p = $this->photo();
        $before = (int)Database::fetchValue('SELECT COUNT(*) FROM news');
        $this->nextRequest();
        $this->post([
            'title_ru' => str_repeat('я', 500), 'excerpt_ru' => str_repeat('я', 500), 'cover_media_id' => '99999', 'photos' => [(string)$p, '99999'],
            'body_ru' => '<p onclick="x">Текст</p><script>alert(1)</script><a href="javascript:alert(1)">j</a><iframe src="//e"></iframe>',
            'body_kk' => '', 'publish_mode' => 'later', 'publish_date' => '1999-01-01',
        ]);
        $o = $this->out($this->c->previewDraft());
        $this->assertSame(200, $this->code());
        $this->assertTrue($o['unsaved']);
        $this->assertSame('Предпросмотр — изменения ещё не сохранены', $o['banner']);
        $this->assertSame(120, mb_strlen($o['langs']['ru']['title']));
        $this->assertCount(1, $o['photos'], 'неверные id пропущены');
        $this->assertSame('', $o['cover']);
        $this->assertSame('не опубликована', $o['date']);
        $this->assertStringNotContainsString('script', $o['langs']['ru']['body_html']);
        $this->assertStringNotContainsString('onclick', $o['langs']['ru']['body_html']);
        $this->assertStringNotContainsString('javascript', $o['langs']['ru']['body_html']);
        $this->assertSame($before, (int)Database::fetchValue('SELECT COUNT(*) FROM news'));
    }

    public function testPreviewEmptyTitleAndKkFallback(): void
    {
        $this->nextRequest();
        $this->post(['title_ru' => '', 'body_ru' => '<p>Русский текст</p>', 'title_kk' => '', 'body_kk' => '']);
        $o = $this->out($this->c->previewDraft());
        $this->assertSame('Без заголовка', $o['langs']['ru']['title']);
        $this->assertTrue($o['langs']['kk']['missing']);
        $this->assertSame('Русский текст', trim(strip_tags($o['langs']['kk']['body_html'])), 'пустой KK заменён русским');
        $this->assertFalse($o['langs']['ru']['missing']);
    }

    public function testPreviewKkTranslatedNotMissing(): void
    {
        $this->nextRequest();
        $this->post(['title_ru' => 'Т', 'body_ru' => '<p>р</p>', 'title_kk' => 'Тақырып', 'body_kk' => '<p>мәтін</p>']);
        $o = $this->out($this->c->previewDraft());
        $this->assertFalse($o['langs']['kk']['missing']);
        $this->assertSame('Тақырып', $o['langs']['kk']['title']);
    }

    public function testSavedPreviewShowsStateDate(): void
    {
        $this->clock('2026-11-20 00:00:00');
        $r = $this->create(['publish_mode' => 'later', 'publish_date' => '2026-11-25', 'publish_time' => '09:00']);
        $this->nextRequest();
        $o = $this->out($this->c->preview(['id' => (string)$r['id']]));
        $this->assertFalse($o['unsaved']);
        $this->assertSame('Предпросмотр — так новость увидят на сайте', $o['banner']);
        $this->assertSame('25.11.2026, 09:00', $o['date']);
        $d = $this->create(['publish_mode' => 'draft']);
        $this->nextRequest();
        $this->assertSame('не опубликована', $this->out($this->c->preview(['id' => (string)$d['id']]))['date']);
    }

    public function testPreviewOfUnsavedFormWithKnownIdKeepsRowUntouched(): void
    {
        $r = $this->create(['title_ru' => 'Сохранённая']);
        $stamp = Database::fetchValue('SELECT updated_at FROM news WHERE id = ?', [$r['id']]);
        $this->nextRequest();
        $this->post(['id' => (string)$r['id'], 'title_ru' => 'Несохранённая правка']);
        $o = $this->out($this->c->previewDraft());
        $this->assertSame('Несохранённая правка', $o['langs']['ru']['title']);
        $this->assertSame('Сохранённая', News::find($r['id'])['title']);
        $this->assertSame($stamp, Database::fetchValue('SELECT updated_at FROM news WHERE id = ?', [$r['id']]));
        $this->assertSame(1, (int)News::find($r['id'])['version']);
    }

    // ---------------------------------------------------------------- медиа через форму

    public function testStoreWithPhotosAndPreviewRenderUsesPicture(): void
    {
        $cover = $this->photo();
        $g = $this->photo();
        $this->store(['cover_media_id' => (string)$cover, 'photos' => [(string)$g, (string)$cover]]);
        $id = $this->newsId();
        $this->nextRequest();
        $o = $this->out($this->c->preview(['id' => (string)$id]));
        $this->assertCount(2, $o['photos']);
        $this->nextRequest();
        $e = $this->out($this->c->edit(['id' => (string)$id]));
        $this->assertSame([$g, $cover], $e['values']['photos'], 'порядок сохранён');
        $this->assertSame($cover, $e['values']['cover_media_id']);
    }

    public function testStoreWithDeletedPhotoIs422AndKeepsOrder(): void
    {
        $a = $this->photo();
        $this->nextRequest();
        $this->post($this->form(['photos' => ['77777', (string)$a]]));
        $o = $this->out($this->c->store());
        $this->assertSame(422, $this->code());
        $this->assertSame('Фото удалено из медиатеки — выберите другое', $o['errors']['photos']);
        $this->assertSame([77777, $a], $o['values']['photos']);
    }
}
