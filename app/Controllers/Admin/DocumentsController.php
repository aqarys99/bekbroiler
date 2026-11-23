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
use App\Models\Media;

final class DocumentsController extends BaseController
{
    public function index(array $params = []): string
    {
        return $this->render(200, $this->prefill(), []);
    }

    public function store(array $params = []): string
    {
        $user = $this->currentUser();
        $prep = Document::prepare($_POST, true);
        $values = $this->values();
        $errors = $prep['errors'];
        $file = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : null;
        if ($file === null || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $errors['file'] = t('documents.error.file_required');
        }
        if ($errors) {
            return $this->render(422, $values, $errors);
        }
        $media = null;
        try {
            $media = (new DocumentUploader())->handle($file, (int)$user['id']);
            $id = Document::create($prep['data'], (int)$media['id'], (int)$user['id']);
            Audit::log((int)$user['id'], 'documents.create', 'documents', $id);
        } catch (UploadException $e) {
            return $this->render($e->status() >= 500 ? 500 : 422, $values, ['file' => $e->userMessage()]);
        } catch (\Throwable $e) {
            ErrorHandler::report($e);
            if ($media !== null) {
                Document::dropMedia((int)$media['id']);
            }
            return $this->render(500, $values, ['file' => t('documents.error.save')]);
        }
        Flash::set('success', 'documents.flash.created');
        redirect(admin_url('/documents') . '?cat=' . (int)$prep['data']['category_id']);
    }

    public function replace(array $params): string
    {
        $user = $this->currentUser();
        $doc = $this->mustFind($params);
        $file = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : null;
        $media = null;
        try {
            $media = (new DocumentUploader())->handle($file, (int)$user['id']);
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

    private function render(int $status, array $values, array $errors): string
    {
        $cats = Document::categories();
        $catId = $this->catParam($cats);
        $docs = Document::all($catId);
        $check = Checklist::status();
        $today = Document::today();

        // позиция внутри категории: кнопки вверх/вниз
        $byCat = [];
        foreach ($docs as $d) {
            $byCat[(int)$d['category_id']][] = (int)$d['id'];
        }
        foreach ($docs as &$d) {
            $list = $byCat[(int)$d['category_id']];
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
        unset($d);

        $missingRows = [];
        if ($catId === 0 || $catId === Document::ATTESTATION_CATEGORY) {
            foreach ($check['items'] as $it) {
                if ($it['state'] === 'missing') {
                    $missingRows[] = $it + ['upload_url' => admin_url('/documents') . '?' . http_build_query(['cat' => $catId, 'checklist' => $it['key']]) . '#documents-form'];
                }
            }
        }
        $counts = Document::counts();
        $tabs = [['id' => 0, 'label' => t('documents.tab.all'), 'n' => array_sum($counts)]];
        foreach ($cats as $c) {
            $tabs[] = ['id' => (int)$c['id'], 'label' => (string)$c['title'], 'n' => $counts[(int)$c['id']] ?? 0];
        }
        $max = Media::maxUploadBytes();

        return $this->page('documents/index', t('documents.title'), [
            $this->home(),
            ['label' => t('documents.title'), 'url' => null],
        ], [
            'docs'        => $docs,
            'missingRows' => $missingRows,
            'check'       => $check,
            'tabs'        => $tabs,
            'catId'       => $catId,
            'cats'        => $cats,
            'checkItems'  => Document::checklistItems(),
            'values'      => $values,
            'errors'      => $errors,
            'today'       => $today,
            'maxLabel'    => format_bytes($max),
            'accept'      => '.' . implode(',.', array_keys(DocumentUploader::TYPES)),
            'formAction'  => admin_url('/documents'),
            'base'        => admin_url('/documents'),
            'backCat'     => $catId,
        ], $status);
    }

    /** Значения формы по умолчанию (в т.ч. «Загрузить» у пустого пункта чек-листа). */
    private function prefill(): array
    {
        $v = ['title' => '', 'title_kk' => '', 'category_id' => 0, 'checklist_key' => '', 'doc_date' => '', 'valid_until' => ''];
        $key = is_string($_GET['checklist'] ?? null) ? $_GET['checklist'] : '';
        foreach (Document::checklistItems() as $c) {
            if ($c['key'] === $key) {
                $v['checklist_key'] = $key;
                $v['title'] = (string)$c['title'];
                $v['title_kk'] = (string)$c['title_kk'];
                $v['category_id'] = Document::ATTESTATION_CATEGORY;
            }
        }
        if ($v['category_id'] === 0) {
            $c = $this->catParam(Document::categories());
            $v['category_id'] = $c > 0 ? $c : Document::ATTESTATION_CATEGORY;
        }
        return $v;
    }

    private function values(): array
    {
        $v = [];
        foreach (['title', 'title_kk', 'checklist_key', 'doc_date', 'valid_until'] as $k) {
            $v[$k] = self::post($k);
        }
        $v['category_id'] = (int)self::post('category_id');
        return $v;
    }

    private function catParam(array $cats): int
    {
        $raw = $_GET['cat'] ?? $_POST['back_cat'] ?? '';
        $id = is_string($raw) && preg_match('/^\d{1,9}$/', $raw) ? (int)$raw : 0;
        foreach ($cats as $c) {
            if ((int)$c['id'] === $id) {
                return $id;
            }
        }
        return 0;
    }

    private function back(): string
    {
        $raw = $_POST['back_cat'] ?? '';
        $id = is_string($raw) && preg_match('/^\d{1,9}$/', $raw) ? (int)$raw : 0;
        return admin_url('/documents') . ($id > 0 ? '?cat=' . $id : '');
    }

    private function mustFind(array $params): array
    {
        $row = Document::find((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        return $row;
    }

    private static function fmt(string $ymd): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
        return $d ? $d->format('d.m.Y') : $ymd;
    }
}
