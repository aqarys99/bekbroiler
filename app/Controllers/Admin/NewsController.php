<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\Flash;
use App\Core\Json;
use App\Core\Slug;
use App\Core\ValidationException;
use App\Core\View;
use App\Models\Media;
use App\Models\News;
use App\Models\NewsConflictException;

final class NewsController extends BaseController
{
    private const TEXT_FIELDS = ['title_ru', 'excerpt_ru', 'title_kk', 'excerpt_kk'];

    // ------------------------------------------------------------------ список

    public function index(array $params = []): string
    {
        $this->needDom();
        $q = News::cleanQuery($_GET['q'] ?? '');
        $status = News::normalizeState($_GET['status'] ?? 'all');
        $kkMissing = ($_GET['kk'] ?? '') === 'missing';
        $list = News::list($q, $status, self::intParam($_GET['page'] ?? 1), null, $kkMissing);
        $tz = Clock::timezone();
        foreach ($list['items'] as &$r) {
            $r['meta_date'] = match ($r['state']) {
                'published' => Clock::toLocal((string)$r['published_at'])->format('d.m.Y'),
                'scheduled' => Clock::toLocal((string)$r['published_at'])->format('d.m.Y, H:i'),
                default => t('news.not_published'),
            };
            $r['author'] = $r['author_name'] !== null && $r['author_name'] !== '' ? (string)$r['author_name'] : '—';
            $r['edit_url'] = admin_url('/news/' . (int)$r['id'] . '/edit');
            $r['preview_url'] = admin_url('/news/' . (int)$r['id'] . '/preview');
        }
        unset($r);
        $total = $list['total'];
        $from = $total === 0 ? 0 : ($list['page'] - 1) * $list['per_page'] + 1;
        $to = min($total, $list['page'] * $list['per_page']);

        return $this->page('news/index', t('news.title'), [
            $this->home(),
            ['label' => t('news.title'), 'url' => null],
        ], [
            'items'     => $list['items'],
            'counts'    => News::counts($q),
            'q'         => $q,
            'status'    => $status,
            'page'      => $list['page'],
            'pages'     => $list['pages'],
            'total'     => $total,
            'from'      => $from,
            'to'        => $to,
            'pageList'  => self::pageList($list['page'], $list['pages']),
            'hasFilter' => $q !== '' || $status !== 'all' || $kkMissing,
            'kkMissing' => $kkMissing,
            'noneAtAll' => News::counts('')['all'] === 0,
            'createUrl' => admin_url('/news/new'),
            'timezone'  => $tz->getName(),
        ]);
    }

    // ------------------------------------------------------------------ редактор

    public function create(array $params = []): string
    {
        $this->needDom();
        return $this->renderForm(200, null, $this->defaults(null), []);
    }

    public function store(array $params = []): string
    {
        $this->needDom();
        $user = $this->currentUser();
        $key = self::post('form_key');
        $existing = preg_match('/^[a-f0-9]{32}$/', $key) ? News::findByFormKey($key) : null; // повторная отправка
        $prep = News::prepare($_POST, $existing);
        $values = $this->postValues($prep['data'], null);
        if ($prep['errors']) {
            return $this->renderForm(422, null, $values, $prep['errors']);
        }
        try {
            $res = News::save(null, $prep['data'], (int)$user['id'], null);
        } catch (ValidationException $e) {
            return $this->renderForm(422, null, $values, $e->errors());
        }
        return $this->afterSave($res);
    }

    public function edit(array $params): string
    {
        $this->needDom();
        $news = $this->mustFind($params);
        return $this->renderForm(200, $news, $this->defaults($news), []);
    }

    public function update(array $params): string
    {
        $this->needDom();
        $news = $this->mustFind($params);
        $user = $this->currentUser();
        $prep = News::prepare($_POST, $news);
        $values = $this->postValues($prep['data'], $news);
        if ($prep['errors']) {
            return $this->renderForm(422, $news, $values, $prep['errors']);
        }
        try {
            $res = News::save((int)$news['id'], $prep['data'], (int)$user['id'], self::versionParam());
        } catch (NewsConflictException) {
            return $this->renderForm(409, $news, $values, [], true);
        } catch (ValidationException $e) {
            return $this->renderForm(422, $news, $values, $e->errors());
        } catch (\OutOfBoundsException) {
            abort(404);
        }
        return $this->afterSave($res);
    }

    public function autosave(array $params = []): string
    {
        if (!class_exists(\DOMDocument::class)) {
            return Json::respond(503, ['ok' => false, 'code' => 'extension', 'message' => t('error.extension_missing')]);
        }
        $user = $this->currentUser();
        $current = null;
        $idRaw = $_POST['id'] ?? '';
        if (is_string($idRaw) && $idRaw !== '') {
            $current = preg_match('/^\d{1,12}$/', $idRaw) ? News::find((int)$idRaw) : null;
            if ($current === null) {
                return Json::respond(404, ['ok' => false, 'code' => 'not_found']);
            }
        } else {
            $key = self::post('form_key');
            if (preg_match('/^[a-f0-9]{32}$/', $key)) {
                $current = News::findByFormKey($key);
            }
        }
        if ($current !== null && $current['state'] !== 'draft') {
            return Json::respond(409, ['ok' => false, 'code' => 'not_draft']);
        }
        $prep = News::prepare($_POST, $current, true, 'draft');
        if ($prep['errors']) {
            return Json::respond(422, ['ok' => false, 'code' => 'validation', 'errors' => $prep['errors']]);
        }
        $versionGiven = isset($_POST['id']) && $_POST['id'] !== '';
        try {
            $res = News::save(
                $current !== null ? (int)$current['id'] : null,
                $prep['data'],
                (int)$user['id'],
                $versionGiven ? self::versionParam() : null,
                false
            );
        } catch (NewsConflictException) {
            return Json::respond(409, ['ok' => false, 'code' => 'conflict']);
        } catch (ValidationException $e) {
            return Json::respond(422, ['ok' => false, 'code' => 'validation', 'errors' => $e->errors()]);
        } catch (\OutOfBoundsException) {
            return Json::respond(404, ['ok' => false, 'code' => 'not_found']);
        }
        if ($res['created']) {
            Audit::log((int)$user['id'], 'news.create', 'news', $res['id']);
        }
        return Json::respond(200, [
            'ok'         => true,
            'id'         => $res['id'],
            'version'    => $res['version'],
            'saved_at'   => Clock::nowUtc()->setTimezone(Clock::timezone())->format('H:i'),
            'slug'       => $res['slug'],
            'edit_url'   => admin_url('/news/' . $res['id'] . '/edit'),
            'update_url' => admin_url('/news/' . $res['id']),
        ]);
    }

    public function delete(array $params): string
    {
        $user = $this->currentUser();
        $id = (int)($params['id'] ?? 0);
        $row = News::delete($id, static fn(array $r) => Audit::log((int)$user['id'], 'news.delete', 'news', (int)$r['id']));
        if ($row === null) {
            abort(404);
        }
        Flash::set('success', 'news.flash.deleted');
        $q = News::cleanQuery($_POST['q'] ?? '');
        $status = News::normalizeState($_POST['status'] ?? 'all');
        $page = News::list($q, $status, self::intParam($_POST['page'] ?? 1))['page']; // пустая страница -> предыдущая
        $query = array_filter(['q' => $q, 'status' => $status !== 'all' ? $status : '', 'page' => $page > 1 ? (string)$page : ''], static fn($v) => $v !== '');
        redirect(admin_url('/news') . ($query ? '?' . http_build_query($query) : ''));
    }

    /** JSON: предложение адреса. ?text=&id=&manual=1 (ручной ввод: без суффикса, с признаком «занят»). */
    public function slug(array $params = []): string
    {
        $text = trim(is_string($_GET['text'] ?? null) ? mb_substr($_GET['text'], 0, 300) : '');
        if ($text === '') {
            return Json::respond(200, ['slug' => '', 'taken' => false]);
        }
        $id = is_string($_GET['id'] ?? null) && preg_match('/^\d{1,12}$/', $_GET['id']) ? (int)$_GET['id'] : null;
        $slug = Slug::make($text);
        if (($_GET['manual'] ?? '') === '1') {
            $owner = News::slugOwner($slug, $id);
            return Json::respond(200, ['slug' => $slug, 'taken' => $owner !== null, 'taken_by' => $owner['title'] ?? null]);
        }
        return Json::respond(200, ['slug' => News::uniqueSlug($slug, $id), 'taken' => false, 'taken_by' => null]);
    }

    // ------------------------------------------------------------------ предпросмотр

    public function previewDraft(array $params = []): string
    {
        $this->needDom();
        $this->currentUser();
        $current = null;
        $idRaw = $_POST['id'] ?? '';
        if (is_string($idRaw) && preg_match('/^\d{1,12}$/', $idRaw)) {
            $current = News::find((int)$idRaw);
        }
        return $this->renderPreview(News::previewRow($_POST, $current), true);
    }

    public function preview(array $params): string
    {
        $this->needDom();
        $this->currentUser();
        return $this->renderPreview($this->mustFind($params), false);
    }

    /**
     * Шаблон news/preview рендерится БЕЗ layout (полный HTML-документ, без меню админки).
     */
    private function renderPreview(array $row, bool $unsaved): string
    {
        header('X-Robots-Tag: noindex, nofollow');
        $ruTitle = (string)$row['title'] !== '' ? (string)$row['title'] : t('news.preview.untitled');
        $kkTitle = trim((string)$row['kk_title']);
        $kkExcerpt = trim((string)$row['kk_excerpt']);
        $kkBody = trim((string)$row['kk_body']);
        $missing = $kkTitle === '' || ($kkBody === '' && trim((string)$row['body']) !== '');
        $state = News::state($row);
        $date = match ($state) {
            'published' => Clock::toLocal((string)$row['published_at'])->format('d.m.Y'),
            'scheduled' => Clock::toLocal((string)$row['published_at'])->format('d.m.Y, H:i'),
            default => t('news.not_published'),
        };
        $langs = [
            'ru' => [
                'title' => $ruTitle, 'excerpt' => (string)$row['excerpt'],
                'body_html' => News::renderBody((string)$row['body'], 'large'), 'missing' => false,
            ],
            'kk' => [
                'title' => $kkTitle !== '' ? $kkTitle : $ruTitle,
                'excerpt' => $kkExcerpt !== '' ? $kkExcerpt : (string)$row['excerpt'],
                'body_html' => News::renderBody($kkBody !== '' ? $kkBody : (string)$row['body'], 'large'),
                'missing' => $missing,
            ],
        ];
        $photos = array_map(static fn(array $m): string => media_picture($m, 'medium'), (array)$row['photos']);
        http_response_code(200);
        return View::render('news/preview', [
            'title'        => $ruTitle . ' — ' . $this->siteName(),
            'banner'       => t($unsaved ? 'news.preview.banner_unsaved' : 'news.preview.banner'),
            'unsaved'      => $unsaved,
            'langs'        => $langs,
            'noTranslation' => t('news.preview.no_translation'),
            'date'         => $date,
            'state'        => $state,
            'coverHtml'    => media_picture($row['cover'] ?? null, 'large'),
            'photosHtml'   => $photos,
            'siteName'     => $this->siteName(),
            'backUrl'      => !empty($row['id']) ? admin_url('/news/' . (int)$row['id'] . '/edit') : admin_url('/news'),
        ], null);
    }

    // ------------------------------------------------------------------ форма

    /** Значения формы для существующей/новой новости. */
    private function defaults(?array $news): array
    {
        if ($news === null) {
            return [
                'id' => null, 'version' => null, 'form_key' => News::newFormKey(),
                'title_ru' => '', 'excerpt_ru' => '', 'body_ru' => '',
                'title_kk' => '', 'excerpt_kk' => '', 'body_kk' => '',
                'cover_media_id' => null, 'photos' => [],
                'publish_mode' => 'now', 'publish_date' => '', 'publish_time' => '09:00',
                'slug' => '', 'slug_auto' => '1', 'tab' => 'ru',
            ];
        }
        $state = (string)$news['state'];
        $local = $news['published_at'] !== null ? Clock::toLocal((string)$news['published_at']) : null;
        $auto = $state === 'draft' && preg_match('/^' . preg_quote(Slug::make((string)$news['title']), '/') . '(-\d+)?$/', (string)$news['slug']) === 1;
        return [
            'id' => (int)$news['id'], 'version' => (int)$news['version'], 'form_key' => (string)($news['form_key'] ?? ''),
            'title_ru' => (string)$news['title'], 'excerpt_ru' => (string)$news['excerpt'],
            'body_ru' => News::bodyForEditor((string)$news['body']),
            'title_kk' => (string)$news['kk_title'], 'excerpt_kk' => (string)$news['kk_excerpt'],
            'body_kk' => News::bodyForEditor((string)$news['kk_body']),
            'cover_media_id' => $news['cover_media_id'] !== null ? (int)$news['cover_media_id'] : null,
            'photos' => array_map(static fn(array $m): int => (int)$m['id'], (array)$news['photos']),
            'publish_mode' => match ($state) { 'published' => 'now', 'scheduled' => 'later', default => 'draft' },
            'publish_date' => $state === 'scheduled' && $local ? $local->format('Y-m-d') : '',
            'publish_time' => $state === 'scheduled' && $local ? $local->format('H:i') : '09:00',
            'slug' => (string)$news['slug'], 'slug_auto' => $auto ? '1' : '0', 'tab' => 'ru',
        ];
    }

    /** Значения формы из POST (после 422/409): введённое пользователем сохраняется. */
    private function postValues(array $data, ?array $news): array
    {
        $v = $this->defaults($news);
        foreach (self::TEXT_FIELDS as $f) {
            $v[$f] = self::post($f);
        }
        $v['body_ru'] = News::bodyForEditor((string)$data['body']);
        $v['body_kk'] = News::bodyForEditor((string)$data['body_kk']);
        $v['cover_media_id'] = Media::parseIds($_POST['cover_media_id'] ?? null)[0] ?? null;
        $v['photos'] = Media::parseIds($_POST['photos'] ?? []);
        $mode = self::post('publish_mode');
        $v['publish_mode'] = in_array($mode, ['draft', 'now', 'later'], true) ? $mode : $v['publish_mode'];
        $v['publish_date'] = self::post('publish_date');
        $v['publish_time'] = self::post('publish_time') !== '' ? self::post('publish_time') : '09:00';
        $v['slug'] = self::post('slug');
        $v['slug_auto'] = self::post('slug_auto') === '1' ? '1' : '0';
        if ($news === null) {
            $key = self::post('form_key');
            $v['form_key'] = preg_match('/^[a-f0-9]{32}$/', $key) ? $key : $v['form_key'];
        }
        return $v;
    }

    private function renderForm(int $status, ?array $news, array $v, array $errors, bool $conflict = false): string
    {
        $state = $news['state'] ?? 'new';
        $max = Media::maxUploadBytes();
        $upload = [
            'url' => admin_url('/media/upload'), 'maxBytes' => $max,
            'maxSide' => (int)config('media.client_max_side', 2560),
            'quality' => (float)config('media.client_quality', 0.85), 'csrf' => csrf_token(),
        ];
        $mfBase = ['upload' => $upload, 'pickerUrl' => admin_url('/media/picker'), 'maxLabel' => format_bytes($max)];
        $coverIds = $v['cover_media_id'] !== null ? [(int)$v['cover_media_id']] : [];

        // активная вкладка: ошибка в казахском поле при чистом русском -> «Қазақша»
        $kkErr = array_intersect_key($errors, array_flip(['title_kk', 'excerpt_kk', 'body_kk']));
        $ruErr = array_intersect_key($errors, array_flip(['title_ru', 'excerpt_ru', 'body_ru']));
        $tab = $kkErr && !$ruErr ? 'kk' : 'ru';

        $local = $news !== null && $news['published_at'] !== null ? Clock::toLocal((string)$news['published_at']) : null;
        $current = match ($state) {
            'draft' => t('news.pub.current.draft'),
            'published' => t('news.pub.current.published', ['date' => $local?->format('d.m.Y'), 'time' => $local?->format('H:i')]),
            'scheduled' => t('news.pub.current.scheduled', ['date' => $local?->format('d.m.Y'), 'time' => $local?->format('H:i')]),
            default => '',
        };
        $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $now = Clock::nowUtc();
        $crumbLabel = $news === null ? t('news.edit.new') : t('news.edit.crumb');

        return $this->page('news/form', $crumbLabel, [
            ['label' => t('news.title'), 'url' => admin_url('/news')],
            ['label' => $crumbLabel, 'url' => null],
        ], [
            'news'         => $news,
            'id'           => $v['id'],
            'values'       => $v,
            'errors'       => $errors,
            'state'        => $state,                     // new|draft|scheduled|published
            'conflict'     => $conflict,
            'conflictUrl'  => $news !== null ? admin_url('/news/' . (int)$news['id'] . '/edit') : admin_url('/news'),
            'activeTab'    => $tab,
            'formAction'   => $news !== null ? admin_url('/news/' . (int)$news['id']) : admin_url('/news'),
            'autosaveUrl'  => admin_url('/news/autosave'),
            'slugUrl'      => admin_url('/news/slug'),
            'previewUrl'   => admin_url('/news/preview'),
            'deleteUrl'    => $news !== null ? admin_url('/news/' . (int)$news['id'] . '/delete') : null,
            'listUrl'      => admin_url('/news'),
            'autosave'     => in_array($state, ['new', 'draft'], true),
            'showSaveDraft' => in_array($state, ['new', 'draft'], true),
            'submitLabels' => self::submitLabels((string)$state),
            'pubCurrent'   => $current,
            'tzName'       => Clock::timezone()->getName(),
            'tzHint'       => t('news.pub.tz', ['tz' => Clock::timezone()->getName(), 'offset' => Clock::offsetLabel($now)]),
            'minDate'      => $now->setTimezone(Clock::timezone())->format('Y-m-d'),
            'slugBase'     => $host . rtrim((string)config('base_path', ''), '/') . '/novosti/',
            'maxPhotos'    => (int)config('news.max_photos', 50),
            'mfCover'      => $mfBase + ['name' => 'cover_media_id', 'label' => t('news.cover.title'), 'multiple' => false,
                'values' => Media::pickerValues($coverIds)],
            'mfPhotos'     => $mfBase + ['name' => 'photos', 'label' => t('news.photos.title'), 'multiple' => true, 'sortable' => true,
                'values' => Media::pickerValues($v['photos'])],
        ], $status);
    }

    /** Тексты главной кнопки по режиму публикации для текущего состояния. */
    private static function submitLabels(string $state): array
    {
        return [
            'draft' => t(in_array($state, ['published', 'scheduled'], true) ? 'news.btn.unpublish' : 'news.btn.save_draft'),
            'now'   => t($state === 'published' ? 'news.btn.save_changes' : 'news.btn.publish'),
            'later' => t($state === 'scheduled' ? 'news.btn.save_changes' : 'news.btn.schedule'),
        ];
    }

    private function afterSave(array $res): string
    {
        $after = $res['after'];
        $before = $res['before'];
        $local = $res['published_at'] !== null ? Clock::toLocal((string)$res['published_at']) : null;
        $intentDraft = ($_POST['intent'] ?? '') === 'draft';
        if ($after === 'draft') {
            Flash::set('success', in_array($before, ['published', 'scheduled'], true) ? 'news.flash.unpublished' : 'news.flash.draft');
        } elseif ($after === 'published') {
            Flash::set('success', $before === 'published' ? 'news.flash.saved' : 'news.flash.published');
        } else {
            Flash::set('success', $before === 'scheduled' ? 'news.flash.saved' : 'news.flash.scheduled', [
                'date' => $local?->format('d.m.Y'), 'time' => $local?->format('H:i'),
            ]);
        }
        redirect($intentDraft ? admin_url('/news/' . $res['id'] . '/edit') : admin_url('/news'));
    }

    // ------------------------------------------------------------------ служебное

    private function mustFind(array $params): array
    {
        $row = News::find((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        return $row;
    }

    /** Без расширения DOM санитайзер не работает: 503 с понятным текстом вместо 500. */
    private function needDom(): void
    {
        if (!class_exists(\DOMDocument::class)) {
            abort(503, t('error.extension_missing'));
        }
    }

    private static function versionParam(): int
    {
        $v = self::post('version');
        return preg_match('/^\d{1,12}$/', $v) ? (int)$v : -1;
    }

    private static function intParam(mixed $v): int
    {
        return is_string($v) && preg_match('/^\d{1,9}$/', $v) ? max(1, (int)$v) : 1;
    }

    /** ≤ 7 страниц — все; иначе первая, последняя, текущая ±2 и '…'. @return array<int,int|string> */
    private static function pageList(int $page, int $pages): array
    {
        if ($pages <= 7) {
            return range(1, $pages);
        }
        $set = array_unique(array_merge([1, $pages], range(max(1, $page - 2), min($pages, $page + 2))));
        sort($set);
        $out = [];
        $prev = 0;
        foreach ($set as $p) {
            if ($p - $prev > 1) {
                $out[] = '…';
            }
            $out[] = $p;
            $prev = $p;
        }
        return $out;
    }
}
