<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\DocumentUploader;
use App\Core\Flash;
use App\Core\Json;
use App\Models\Attestation;
use App\Models\Document;
use App\Models\Media;
use App\Models\Page;

/** Раздел «Аттестация»: страницы аттестации (заголовок + файлы), порядок, создание с загрузкой файлов по одному. */
final class AttestationController extends BaseController
{
    private const META = ['title', 'title_kk', 'doc_date', 'valid_until', 'checklist_key', 'view_mode'];

    public function index(array $params = []): string
    {
        return $this->page('attestation/index', t('attestation.title'), [
            $this->home(), ['label' => t('attestation.title'), 'url' => null],
        ], ['rows' => Attestation::pages()]);
    }

    public function create(array $params = []): string
    {
        return $this->form(null, ['title' => '', 'title_kk' => '', 'is_published' => 1, 'in_menu' => 1], []);
    }

    public function choose(array $params = []): string
    {
        $key = is_string($_GET['checklist'] ?? null) ? $_GET['checklist'] : '';
        $item = null;
        foreach (Document::checklistItems() as $c) {
            if ($c['key'] === $key) {
                $item = $c;
            }
        }
        return $this->page('attestation/choose', t('attestation.choose.title'), [
            $this->home(), ['label' => t('attestation.title'), 'url' => admin_url('/attestation')], ['label' => t('attestation.choose.title'), 'url' => null],
        ], ['item' => $item, 'key' => $item['key'] ?? '', 'rows' => Attestation::pages()]);
    }

    public function store(array $params = []): string
    {
        $user = $this->currentUser();
        $json = Json::wanted();
        $prep = Attestation::prepare($_POST);
        if ($prep['errors']) {
            if ($json) {
                return Json::respond(422, ['ok' => false, 'errors' => $prep['errors'], 'message' => (string)reset($prep['errors'])]);
            }
            return $this->form(null, $this->posted($prep['data']), $prep['errors'], 422);
        }
        $inMenu = !empty($_POST['in_menu']);
        $id = Attestation::create($prep['data'], $inMenu);
        Audit::log((int)$user['id'], 'attestation.create', 'pages', $id);
        if ($json) {
            return Json::respond(200, ['ok' => true, 'id' => $id, 'files_url' => admin_url('/attestation/' . $id . '/files'),
                'done_url' => admin_url('/attestation/' . $id . '/uploaded'), 'edit_url' => admin_url('/attestation/' . $id)]);
        }
        // без JS: файлы пришли вместе с формой
        $rows = Attestation::rowsFromRequest($_POST, $_FILES);
        $res = Attestation::addFiles($id, array_slice($rows, 0, Attestation::MAX_FILES), (int)$user['id']);
        foreach ($res['ids'] as $docId) {
            Audit::log((int)$user['id'], 'documents.create', 'documents', $docId);
        }
        $this->flashUploaded('attestation.flash.created', $res['ok'], $res['total'], $res['errors']);
        redirect(admin_url('/attestation/' . $id));
    }

    public function edit(array $params): string
    {
        $row = $this->mustFind($params);
        return $this->form($row, $row, []);
    }

    public function update(array $params): string
    {
        $user = $this->currentUser();
        $row = $this->mustFind($params);
        $prep = Attestation::prepare($_POST, $row);
        if ($prep['errors']) {
            return $this->form($row, $this->posted($prep['data']), $prep['errors'], 422);
        }
        Attestation::update((int)$row['id'], $prep['data']);
        Audit::log((int)$user['id'], 'attestation.update', 'pages', (int)$row['id']);
        Flash::set('success', 'attestation.flash.saved');
        redirect(admin_url('/attestation/' . (int)$row['id']));
    }

    public function publish(array $params): string
    {
        $row = $this->mustFind($params);
        $on = !empty($_POST['is_published']);
        Page::setPublished((int)$row['id'], $on);
        Audit::log((int)$this->currentUser()['id'], $on ? 'pages.publish' : 'pages.unpublish', 'pages', (int)$row['id']);
        Flash::set('success', $on ? 'pages.flash.shown' : 'pages.flash.hidden');
        redirect(admin_url('/attestation'));
    }

    public function move(array $params): string
    {
        $row = $this->mustFind($params);
        if (Attestation::move((int)$row['id'], self::post('dir') === 'up' ? -1 : 1)) {
            Audit::log((int)$this->currentUser()['id'], 'attestation.move', 'pages', (int)$row['id']);
            Flash::set('success', 'attestation.flash.moved');
        }
        redirect(admin_url('/attestation'));
    }

    public function delete(array $params): string
    {
        $user = $this->currentUser();
        $this->mustFind($params);
        Page::delete((int)$params['id'], static fn(array $r) => Audit::log((int)$user['id'], 'attestation.delete', 'pages', (int)$r['id']));
        Flash::set('success', 'attestation.flash.deleted');
        redirect(admin_url('/attestation'));
    }

    /** Файлы страницы: JSON — один файл (XHR с прогрессом), без JS — несколько (files[] + titles[]). */
    public function addFiles(array $params): string
    {
        $user = $this->currentUser();
        $row = $this->mustFind($params);
        if (Json::wanted()) {
            $file = is_array($_FILES['file'] ?? null) && !is_array($_FILES['file']['error'] ?? null) ? $_FILES['file'] : null;
            if ($file === null || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                return Json::respond(422, ['ok' => false, 'message' => t('documents.error.file_required')]);
            }
            $meta = [];
            foreach (self::META as $k) {
                $meta[$k] = self::post($k);
            }
            $res = Attestation::addFile((int)$row['id'], $file, $meta, (int)$user['id']);
            if (!$res['ok']) {
                return Json::respond(422, ['ok' => false, 'message' => (string)$res['error']]);
            }
            Audit::log((int)$user['id'], 'documents.create', 'documents', (int)$res['id']);
            return Json::respond(200, ['ok' => true, 'id' => (int)$res['id'], 'title' => $res['title']]);
        }
        $rows = Attestation::rowsFromRequest($_POST, $_FILES);
        $res = Attestation::addFiles((int)$row['id'], array_slice($rows, 0, Attestation::MAX_FILES), (int)$user['id']);
        foreach ($res['ids'] as $docId) {
            Audit::log((int)$user['id'], 'documents.create', 'documents', $docId);
        }
        $this->flashUploaded('attestation.flash.files_added', $res['ok'], $res['total'], $res['errors']);
        redirect(admin_url('/attestation/' . (int)$row['id']) . '#att-files');
    }

    /** JSON: JS закончил загрузку файлов — запомнить итог для флеш-сообщения на экране страницы. */
    public function uploaded(array $params): string
    {
        $row = $this->mustFind($params);
        $total = max(0, min(1000, (int)self::post('total')));
        $ok = max(0, min($total, (int)self::post('ok')));
        $created = self::post('created') === '1';
        $failed = self::post('failed');
        $errors = $failed !== '' ? [mb_substr($failed, 0, 400)] : [];
        $this->flashUploaded($created ? 'attestation.flash.created' : 'attestation.flash.files_added', $ok, $total, $errors);
        return Json::respond(200, ['ok' => true, 'url' => admin_url('/attestation/' . (int)$row['id']) . ($created ? '' : '#att-files')]);
    }

    // ------------------------------------------------------------------ служебное

    private function flashUploaded(string $key, int $ok, int $total, array $errors): void
    {
        if ($total === 0) {
            Flash::set('success', $key . '_none');
            return;
        }
        $reasons = $errors ? ' ' . t('attestation.flash.failed', ['list' => implode('; ', array_slice($errors, 0, 5))]) : '';
        Flash::set($ok === $total ? 'success' : 'error', $key, ['ok' => (string)$ok, 'total' => (string)$total, 'reasons' => $reasons]);
    }

    private function posted(array $data): array
    {
        return ['title' => self::post('title'), 'title_kk' => self::post('title_kk'), 'is_published' => $data['is_published'], 'in_menu' => !empty($_POST['in_menu']) ? 1 : 0];
    }

    private function form(?array $row, array $old, array $errors, int $status = 200): string
    {
        $isNew = $row === null;
        $label = $isNew ? t('attestation.new') : (string)$row['title'];
        $checklist = is_string($_GET['checklist'] ?? null) ? $_GET['checklist'] : '';
        $prefill = null;
        foreach (Document::checklistItems() as $c) {
            if ($c['key'] === $checklist && !$isNew) {
                $prefill = ['title' => (string)$c['title'], 'title_kk' => (string)$c['title_kk'], 'checklist_key' => $checklist];
            }
        }
        return $this->page('attestation/form', $label, [
            $this->home(), ['label' => t('attestation.title'), 'url' => admin_url('/attestation')], ['label' => $label, 'url' => null],
        ], [
            'row' => $row, 'old' => $old, 'errors' => $errors, 'isNew' => $isNew,
            'docs' => $isNew ? [] : DocumentsController::decorate(Document::all((int)$row['id'])),
            'checkItems' => Document::checklistItems(), 'prefill' => $prefill,
            'maxLabel' => format_bytes(min(Media::maxUploadBytes(), DocumentUploader::MAX_BYTES)),
            'maxBytes' => min(Media::maxUploadBytes(), DocumentUploader::MAX_BYTES),
            'accept' => '.' . implode(',.', DocumentUploader::PAGE_TYPES),
        ], $status);
    }

    private function mustFind(array $params): array
    {
        $row = Attestation::find((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        return $row;
    }
}
