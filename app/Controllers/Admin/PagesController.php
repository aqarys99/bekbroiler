<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\DocumentUploader;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Core\Json;
use App\Core\UploadException;
use App\Models\Document;
use App\Models\Media;
use App\Models\Page;

final class PagesController extends BaseController
{
    public function index(array $params = []): string
    {
        $q = trim(is_string($_GET['q'] ?? null) ? mb_substr($_GET['q'], 0, 100) : '');
        $status = in_array($_GET['status'] ?? '', ['shown', 'hidden'], true) ? (string)$_GET['status'] : 'all';
        return $this->page('pages/index', t('pages.title'), [
            $this->home(), ['label' => t('pages.title'), 'url' => null],
        ], ['rows' => Page::search($q, $status), 'q' => $q, 'status' => $status]);
    }

    public function create(array $params = []): string
    {
        $old = ['title' => '', 'title_kk' => '', 'slug' => '', 'body' => '', 'body_kk' => '', 'is_published' => 1];
        return $this->form(null, $old, null, [], [], 200);
    }

    public function store(array $params = []): string
    {
        $prep = Page::prepare($_POST, false);
        if ($prep['errors']) {
            return $this->form(null, $this->posted($prep['data']), null, $prep['errors'], [], 422);
        }
        $id = Page::create($prep['data']);
        Audit::log((int)$this->currentUser()['id'], 'pages.create', 'pages', $id);
        Flash::set('success', 'pages.flash.created');
        redirect(admin_url('/pages/' . $id));
    }

    public function edit(array $params): string
    {
        $row = $this->mustFind($params);
        if (Page::isAttestation($row)) {
            redirect(Page::adminUrl($row));
        }
        return $this->form($row, $row, null, [], [], 200);
    }

    public function update(array $params): string
    {
        $row = $this->mustFind($params);
        $hasSchedule = !empty($row['has_schedule']);
        $prep = Page::prepare($_POST, $hasSchedule, (int)$row['id']);
        if ($prep['errors'] || $prep['schedule_errors']) {
            return $this->form($row, $this->posted($prep['data']), $hasSchedule ? $this->postedSchedule() : null, $prep['errors'], $prep['schedule_errors'], 422);
        }
        Page::update((int)$row['id'], $prep['data'], $hasSchedule ? $prep['schedule'] : null);
        Audit::log((int)$this->currentUser()['id'], 'pages.update', 'pages', (int)$row['id']);
        Flash::set('success', 'pages.flash.saved');
        redirect(admin_url('/pages/' . (int)$row['id']));
    }

    public function publish(array $params): string
    {
        $row = $this->mustFind($params);
        $on = !empty($_POST['is_published']);
        Page::setPublished((int)$row['id'], $on);
        Audit::log((int)$this->currentUser()['id'], $on ? 'pages.publish' : 'pages.unpublish', 'pages', (int)$row['id']);
        Flash::set('success', $on ? 'pages.flash.shown' : 'pages.flash.hidden');
        redirect(admin_url('/pages'));
    }

    public function delete(array $params): string
    {
        $user = $this->currentUser();
        $row = Page::delete((int)($params['id'] ?? 0), static fn(array $r) => Audit::log((int)$user['id'], 'pages.delete', 'pages', (int)$r['id']));
        if ($row === null) {
            abort(404);
        }
        Flash::set('success', 'pages.flash.deleted');
        redirect(admin_url('/pages'));
    }

    /** JSON: адрес по названию (GET ?title=&id=). */
    public function slug(array $params = []): string
    {
        $title = trim(is_string($_GET['title'] ?? null) ? mb_substr($_GET['title'], 0, 300) : '');
        $id = is_string($_GET['id'] ?? null) && preg_match('/^\d{1,12}$/', $_GET['id']) ? (int)$_GET['id'] : null;
        return Json::respond(200, ['slug' => $title === '' ? '' : Page::uniqueSlug(Page::slugFromTitle($title), $id)]);
    }

    // ------------------------------------------------------------------ документы страницы

    public function addDocument(array $params): string
    {
        $user = $this->currentUser();
        $row = $this->mustFind($params);
        $_POST['page_id'] = (string)$row['id'];
        $prep = Document::prepare($_POST, true);
        $values = [];
        foreach (['title', 'title_kk', 'checklist_key', 'doc_date', 'valid_until', 'view_mode'] as $k) {
            $values[$k] = self::post($k);
        }
        $errors = $prep['errors'];
        unset($errors['page_id']);
        $file = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : null;
        if ($file === null || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors['file'] = t('documents.error.file_required');
        }
        if ($errors) {
            return $this->form($row, $row, null, [], [], 422, $values, $errors);
        }
        $media = null;
        try {
            $media = (new DocumentUploader())->handle($file, (int)$user['id'], DocumentUploader::PAGE_TYPES);
            $id = Document::create($prep['data'], (int)$media['id'], (int)$user['id']);
            Audit::log((int)$user['id'], 'documents.create', 'documents', $id);
        } catch (UploadException $e) {
            return $this->form($row, $row, null, [], [], $e->status() >= 500 ? 500 : 422, $values, ['file' => $e->userMessage()]);
        } catch (\Throwable $e) {
            ErrorHandler::report($e);
            if ($media !== null) {
                Document::dropMedia((int)$media['id']);
            }
            return $this->form($row, $row, null, [], [], 500, $values, ['file' => t('documents.error.save')]);
        }
        Flash::set('success', 'documents.flash.created');
        redirect(admin_url('/pages/' . (int)$row['id']) . '#page-docs');
    }

    /** JSON (fetch): новый порядок документов страницы, order[] = id по порядку. */
    public function orderDocuments(array $params): string
    {
        $user = $this->currentUser();
        $row = $this->mustFind($params);
        $ok = Document::reorder((int)$row['id'], Media::parseIds($_POST['order'] ?? []));
        if ($ok) {
            Audit::log((int)$user['id'], 'documents.move', 'pages', (int)$row['id']);
        }
        return Json::respond($ok ? 200 : 422, ['ok' => $ok]);
    }

    // ------------------------------------------------------------------ служебное

    private function posted(array $data): array
    {
        $old = $data;
        foreach (['title', 'title_kk', 'slug'] as $k) {
            $old[$k] = self::post($k);
        }
        $old['body'] = self::post('body');
        $old['body_kk'] = self::post('body_kk');
        return $old;
    }

    private function postedSchedule(): array
    {
        $out = [];
        $times = (array)($_POST['sched_time'] ?? []);
        foreach ($times as $i => $t) {
            $out[] = [
                'time_range' => is_string($t) ? $t : '',
                'text' => is_string($_POST['sched_text'][$i] ?? null) ? $_POST['sched_text'][$i] : '',
                'text_kk' => is_string($_POST['sched_text_kk'][$i] ?? null) ? $_POST['sched_text_kk'][$i] : '',
            ];
        }
        return $out;
    }

    private function form(?array $row, array $old, ?array $schedule, array $errors, array $rowErrors, int $status, ?array $docValues = null, array $docErrors = []): string
    {
        $isNew = $row === null;
        $label = $isNew ? t('pages.new') : (string)$row['title'];
        $checklist = is_string($_GET['checklist'] ?? null) ? $_GET['checklist'] : '';
        $docs = [];
        if (!$isNew) {
            $docs = DocumentsController::decorate(Document::all((int)$row['id']));
            if ($docValues === null) {
                $docValues = ['title' => '', 'title_kk' => '', 'checklist_key' => '', 'doc_date' => '', 'valid_until' => '', 'view_mode' => 'reader'];
                foreach (Document::checklistItems() as $c) {
                    if ($c['key'] === $checklist) {
                        $docValues['checklist_key'] = $checklist;
                        $docValues['title'] = (string)$c['title'];
                        $docValues['title_kk'] = (string)$c['title_kk'];
                    }
                }
            }
        }
        return $this->page('pages/form', $label, [
            $this->home(), ['label' => t('pages.title'), 'url' => admin_url('/pages')], ['label' => $label, 'url' => null],
        ], [
            'row' => $row, 'old' => $old, 'errors' => $errors, 'rowErrors' => $rowErrors, 'isNew' => $isNew,
            'schedule' => !$isNew && !empty($row['has_schedule']) ? ($schedule ?? Page::schedule()) : null,
            'docs' => $docs, 'docValues' => $docValues ?? [], 'docErrors' => $docErrors,
            'docOpen' => $docErrors !== [] || $checklist !== '',
            'checkItems' => Document::checklistItems(),
            'maxLabel' => format_bytes(min(Media::maxUploadBytes(), DocumentUploader::MAX_BYTES)),
            'accept' => '.' . implode(',.', DocumentUploader::PAGE_TYPES),
        ], $status);
    }

    private function mustFind(array $params): array
    {
        $row = Page::find((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        return $row;
    }
}
