<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\DocumentUploader;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Core\UploadException;
use App\Models\Checklist;
use App\Models\Document;
use App\Models\Page;

final class DocumentsController extends BaseController
{
    public function index(array $params = []): string
    {
        $pages = Page::options();
        $pageId = $this->pageParam($pages);
        $docs = self::decorate(Document::all($pageId));
        $check = Checklist::status();
        $missingRows = [];
        foreach ($check['items'] as $it) {
            if ($it['state'] === 'missing' && $pageId === 0) {
                $missingRows[] = $it + ['upload_url' => self::uploadUrl($it['key'])];
            }
        }
        return $this->page('documents/index', t('documents.title'), [
            $this->home(),
            ['label' => t('documents.title'), 'url' => null],
        ], [
            'docs' => $docs, 'missingRows' => $missingRows, 'check' => $check, 'pages' => $pages, 'pageId' => $pageId,
            'base' => admin_url('/documents'),
        ]);
    }

    /** «Загрузить» у пункта чек-листа: сначала выбрать страницу, затем форма добавления на её экране. */
    public function choose(array $params = []): string
    {
        $key = is_string($_GET['checklist'] ?? null) ? $_GET['checklist'] : '';
        $item = null;
        foreach (Document::checklistItems() as $c) {
            if ($c['key'] === $key) {
                $item = $c;
            }
        }
        $pages = Page::options();
        $pid = is_string($_GET['page'] ?? null) && preg_match('/^\d{1,9}$/', $_GET['page']) ? (int)$_GET['page'] : 0;
        foreach ($pages as $p) {
            if ((int)$p['id'] === $pid) {
                redirect(self::addUrl($pid, $key));
            }
        }
        return $this->page('documents/choose', t('documents.choose.title'), [
            $this->home(), ['label' => t('documents.title'), 'url' => admin_url('/documents')], ['label' => t('documents.choose.title'), 'url' => null],
        ], ['item' => $item, 'key' => $item['key'] ?? '', 'pages' => $pages]);
    }

    /** Ссылка на форму добавления документа на экране страницы (с предвыбранным пунктом чек-листа). */
    public static function addUrl(int $pageId, string $checklist = ''): string
    {
        return admin_url('/pages/' . $pageId) . ($checklist !== '' ? '?' . http_build_query(['checklist' => $checklist]) : '') . '#page-docs-form';
    }

    public static function uploadUrl(string $checklist): string
    {
        return admin_url('/documents/new') . '?' . http_build_query(['checklist' => $checklist]);
    }

    /** Подписи и ссылки строк списка документов (для экрана документов и экрана страницы). */
    public static function decorate(array $docs): array
    {
        $byPage = [];
        foreach ($docs as $d) {
            $byPage[(int)$d['page_id']][] = (int)$d['id'];
        }
        foreach ($docs as &$d) {
            $list = $byPage[(int)$d['page_id']];
            $d['first'] = $list[0] === (int)$d['id'];
            $d['last'] = end($list) === (int)$d['id'];
            $d['ext'] = Document::ext($d);
            $d['url'] = Document::fileUrl($d);
            $d['required'] = $d['checklist_key'] !== null;
            $meta = [];
            $meta[] = $d['doc_date'] ? t('documents.meta.date', ['date' => self::fmt((string)$d['doc_date'])]) : t('documents.meta.uploaded', ['date' => self::fmt(substr((string)$d['created_at'], 0, 10))]);
            if ($d['valid_until']) {
                $meta[] = t('documents.meta.until', ['date' => self::fmt((string)$d['valid_until'])]);
            }
            $meta[] = format_bytes((int)$d['file_size']);
            $d['meta'] = implode(' · ', $meta);
            $d['status_label'] = match ($d['state']) {
                'warn' => t('documents.status.warn', ['n' => (int)$d['days_left']]),
                'expired' => t('documents.status.expired'),
                default => t('documents.status.ok'),
            };
        }
        return $docs;
    }

    public function edit(array $params): string
    {
        $doc = $this->mustFind($params);
        return $this->renderEdit($doc, [
            'title' => (string)$doc['title'], 'title_kk' => (string)$doc['title_kk'], 'page_id' => (int)$doc['page_id'],
            'checklist_key' => (string)($doc['checklist_key'] ?? ''), 'doc_date' => (string)($doc['doc_date'] ?? ''),
            'valid_until' => (string)($doc['valid_until'] ?? ''), 'is_published' => (int)$doc['is_published'] === 1,
            'view_mode' => (string)$doc['view_mode'],
        ], []);
    }

    public function update(array $params): string
    {
        $user = $this->currentUser();
        $doc = $this->mustFind($params);
        $prep = Document::prepare($_POST, false);
        $published = self::post('is_published') === '1';
        if ($prep['errors']) {
            $values = $this->values() + ['is_published' => $published];
            return $this->renderEdit($doc, $values, $prep['errors'], 422);
        }
        $data = $prep['data'];
        if (!in_array(Document::ext($doc), ['PDF'], true)) {
            unset($data['view_mode']);
        }
        Document::update((int)$doc['id'], $data, $published);
        Audit::log((int)$user['id'], 'documents.update', 'documents', (int)$doc['id']);
        Flash::set('success', 'documents.flash.updated');
        redirect(admin_url('/pages/' . (int)$data['page_id']) . '#page-docs');
    }

    private function renderEdit(array $doc, array $values, array $errors, int $status = 200): string
    {
        $back = $doc['page_id'] !== null ? admin_url('/pages/' . (int)$doc['page_id']) . '#page-docs' : admin_url('/documents');
        return $this->page('documents/edit', t('documents.edit.title'), [
            $this->home(),
            ['label' => t('documents.title'), 'url' => admin_url('/documents')],
            ['label' => t('documents.edit.title'), 'url' => null],
        ], [
            'doc' => $doc, 'isPdf' => Document::ext($doc) === 'PDF', 'values' => $values, 'errors' => $errors,
            'pages' => Page::options(), 'checkItems' => Document::checklistItems(),
            'action' => admin_url('/documents/' . (int)$doc['id']), 'back' => $back,
        ], $status);
    }

    public function replace(array $params): string
    {
        $user = $this->currentUser();
        $doc = $this->mustFind($params);
        $file = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : null;
        $media = null;
        try {
            $media = (new DocumentUploader())->handle($file, (int)$user['id'], DocumentUploader::PAGE_TYPES);
            $old = Document::replaceFile((int)$doc['id'], (int)$media['id']);
            Audit::log((int)$user['id'], 'documents.replace', 'documents', (int)$doc['id']);
        } catch (UploadException $e) {
            Flash::set('error', 'documents.flash.replace_failed', ['reason' => $e->userMessage()]);
            redirect($this->back());
        } catch (\Throwable $e) {
            ErrorHandler::report($e);
            if ($media !== null) {
                Document::dropMedia((int)$media['id']);
            }
            Flash::set('error', 'documents.flash.replace_failed', ['reason' => t('documents.error.save')]);
            redirect($this->back());
        }
        if ($old !== null) {
            Document::dropMedia($old);
        }
        Flash::set('success', 'documents.flash.replaced');
        redirect($this->back());
    }

    public function delete(array $params): string
    {
        $user = $this->currentUser();
        $row = Document::delete((int)($params['id'] ?? 0), static fn(array $r) => Audit::log((int)$user['id'], 'documents.delete', 'documents', (int)$r['id']));
        if ($row === null) {
            abort(404);
        }
        Flash::set('success', 'documents.flash.deleted');
        redirect($this->back());
    }

    public function move(array $params): string
    {
        $user = $this->currentUser();
        $doc = $this->mustFind($params);
        $dir = self::post('dir') === 'up' ? -1 : 1;
        if (Document::move((int)$doc['id'], $dir)) {
            Audit::log((int)$user['id'], 'documents.move', 'documents', (int)$doc['id']);
            Flash::set('success', 'documents.flash.moved');
        }
        redirect($this->back());
    }

    // ------------------------------------------------------------------ служебное

    private function values(): array
    {
        $v = [];
        foreach (['title', 'title_kk', 'checklist_key', 'doc_date', 'valid_until', 'view_mode'] as $k) {
            $v[$k] = self::post($k);
        }
        $v['page_id'] = (int)self::post('page_id');
        return $v;
    }

    private function pageParam(array $pages): int
    {
        $raw = $_GET['page'] ?? '';
        $id = is_string($raw) && preg_match('/^\d{1,9}$/', $raw) ? (int)$raw : 0;
        foreach ($pages as $p) {
            if ((int)$p['id'] === $id) {
                return $id;
            }
        }
        return 0;
    }

    /** Куда вернуться после действия: на экран страницы (back_page) или в общий список. */
    private function back(): string
    {
        $raw = $_POST['back_page'] ?? '';
        $id = is_string($raw) && preg_match('/^\d{1,9}$/', $raw) ? (int)$raw : 0;
        if ($id > 0 && Page::find($id) !== null) {
            return admin_url('/pages/' . $id) . '#page-docs';
        }
        return admin_url('/documents');
    }

    private function mustFind(array $params): array
    {
        $row = Document::find((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        return $row;
    }

    public static function fmt(string $ymd): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
        return $d ? $d->format('d.m.Y') : $ymd;
    }
}
