<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\DocumentUploader;
use App\Core\ErrorHandler;
use App\Core\UploadException;

/** Раздел «Аттестация»: страницы kind='attestation' (только заголовок и файлы), их порядок, меню и загрузка файлов. */
final class Attestation
{
    public const MAX_FILES = 30;

    /** Страницы аттестации по порядку: число файлов, признак «есть в меню». */
    public static function pages(): array
    {
        $gid = MenuItem::attestationGroupId(false);
        $rows = Database::fetchAll(
            "SELECT p.*, (SELECT COUNT(*) FROM documents d WHERE d.page_id = p.id) AS docs_count,
                    (SELECT COUNT(*) FROM menu_items m WHERE m.page_id = p.id AND m.menu = 'main') AS menu_count
             FROM pages p WHERE p.kind = 'attestation' ORDER BY p.sort, p.id"
        );
        foreach ($rows as &$r) {
            $r['in_group'] = $gid !== null && Database::fetchValue('SELECT 1 FROM menu_items WHERE page_id = ? AND parent_id = ?', [$r['id'], $gid]) !== null;
        }
        return $rows;
    }

    public static function find(int $id): ?array
    {
        $row = Page::find($id);
        return Page::isAttestation($row) ? $row : null;
    }

    /** @return array{data:array,errors:array<string,string>} */
    public static function prepare(array $in, ?array $existing = null): array
    {
        $err = [];
        $str = static fn(string $k): string => trim(preg_replace('/[\x00-\x1f\x7f]/u', '', is_string($in[$k] ?? null) ? (string)$in[$k] : '') ?? '');
        $d = ['title' => $str('title'), 'title_kk' => $str('title_kk'), 'is_published' => !empty($in['is_published']) ? 1 : 0];
        if ($d['title'] === '') {
            $err['title'] = t('pages.error.title');
        }
        foreach (['title', 'title_kk'] as $k) {
            if (mb_strlen($d[$k]) > 150) {
                $err[$k] = t('pages.error.title_long');
            }
        }
        $d['slug'] = $existing !== null ? (string)$existing['slug'] : Page::uniqueSlug(Page::slugFromTitle($d['title']));
        return ['data' => $d, 'errors' => $err];
    }

    /** Создать страницу аттестации (без текста); при $inMenu — пункт в группе меню «Аттестация». */
    public static function create(array $data, bool $inMenu): int
    {
        // вложенные транзакции в Database::immediate не поддерживаются — два коротких шага подряд
        $id = Page::create($data + ['body' => '', 'body_kk' => '', 'kind' => 'attestation']);
        if ($inMenu) {
            MenuItem::addAttestationPage($id);
        }
        return $id;
    }

    public static function update(int $id, array $data): void
    {
        Database::query(
            "UPDATE pages SET title = ?, title_kk = ?, is_published = ?, updated_at = strftime('%Y-%m-%d %H:%M:%S','now') WHERE id = ?",
            [$data['title'], $data['title_kk'], $data['is_published'], $id]
        );
    }

    /** Сдвиг страницы на одну позицию; порядок переносится в группу меню «Аттестация». */
    public static function move(int $id, int $dir): bool
    {
        return (bool)Database::immediate(static function () use ($id, $dir): bool {
            $rows = Database::fetchAll("SELECT id, sort FROM pages WHERE kind = 'attestation' ORDER BY sort, id");
            $ids = array_map('intval', array_column($rows, 'id'));
            $values = array_map('intval', array_column($rows, 'sort'));
            $i = array_search($id, $ids, true);
            $j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
            if ($i === false || !isset($ids[$j])) {
                return false;
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            sort($values);
            foreach ($values as $n => $v) {
                $values[$n] = $n > 0 ? max($v, $values[$n - 1] + 1) : $v; // одинаковые sort разводятся
            }
            foreach ($ids as $n => $pid) {
                Database::query('UPDATE pages SET sort = ? WHERE id = ?', [$values[$n], $pid]);
            }
            MenuItem::syncAttestationOrder();
            return true;
        });
    }

    /** Название документа из имени файла: без расширения, подчёркивания -> пробелы. */
    public static function titleFromFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = trim((string)preg_replace('/\s+/u', ' ', str_replace('_', ' ', $base)));
        $base = trim((string)preg_replace('/[\x00-\x1f\x7f]/u', '', $base));
        return mb_substr($base !== '' ? $base : 'document', 0, Document::TITLE_MAX);
    }

    /**
     * Строки формы без JS: files[] + titles[] (+ titles_kk[], doc_dates[], valid_untils[], checklist_keys[], view_modes[]) и multi[] (файлы пачкой).
     * @return array<int,array{file:?array,meta:array<string,string>}>
     */
    public static function rowsFromRequest(array $post, array $files): array
    {
        $rows = [];
        $f = is_array($files['files'] ?? null) ? $files['files'] : null;
        $get = static fn(string $key, int $i): string => is_string($post[$key][$i] ?? null) ? (string)$post[$key][$i] : '';
        $n = 0;
        if ($f !== null && is_array($f['name'] ?? null)) {
            $n = count($f['name']);
        }
        foreach (['titles', 'titles_kk', 'doc_dates', 'valid_untils', 'checklist_keys', 'view_modes'] as $k) {
            if (is_array($post[$k] ?? null)) {
                $n = max($n, count($post[$k]));
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $file = null;
            if ($f !== null && isset($f['name'][$i]) && (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $file = ['name' => $f['name'][$i], 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i], 'size' => $f['size'][$i] ?? 0];
            }
            $meta = ['title' => $get('titles', $i), 'title_kk' => $get('titles_kk', $i), 'doc_date' => $get('doc_dates', $i),
                'valid_until' => $get('valid_untils', $i), 'checklist_key' => $get('checklist_keys', $i), 'view_mode' => $get('view_modes', $i)];
            if ($file === null && implode('', array_map('trim', [$meta['title'], $meta['title_kk'], $meta['doc_date'], $meta['valid_until']])) === '') {
                continue;
            }
            $rows[] = ['file' => $file, 'meta' => $meta];
        }
        $m = is_array($files['multi'] ?? null) ? $files['multi'] : null;
        if ($m !== null && is_array($m['name'] ?? null)) {
            foreach ($m['name'] as $i => $name) {
                if ((int)($m['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                $rows[] = ['file' => ['name' => $name, 'type' => $m['type'][$i] ?? '', 'tmp_name' => $m['tmp_name'][$i] ?? '', 'error' => $m['error'][$i], 'size' => $m['size'][$i] ?? 0],
                    'meta' => ['title' => '', 'title_kk' => '', 'doc_date' => '', 'valid_until' => '', 'checklist_key' => '', 'view_mode' => '']];
            }
        }
        return $rows;
    }

    /**
     * Добавить один файл как документ страницы. Пустое название = имя файла.
     * @return array{ok:bool,id?:int,title?:string,error?:string}
     */
    public static function addFile(int $pageId, ?array $file, array $meta, ?int $userId, ?DocumentUploader $uploader = null): array
    {
        if ($file === null) {
            return ['ok' => false, 'error' => t('documents.error.file_required')];
        }
        if (trim((string)($meta['title'] ?? '')) === '') {
            $meta['title'] = self::titleFromFilename((string)($file['name'] ?? ''));
        }
        $prep = Document::prepare($meta + ['page_id' => (string)$pageId], true);
        if ($prep['errors']) {
            return ['ok' => false, 'error' => (string)reset($prep['errors'])];
        }
        // «Открывать как» имеет смысл только для PDF; для остальных остаётся значение по умолчанию
        if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'pdf') {
            $prep['data']['view_mode'] = 'reader';
        }
        $media = null;
        try {
            $media = ($uploader ?? new DocumentUploader())->handle($file, $userId, DocumentUploader::PAGE_TYPES);
            $id = Document::create($prep['data'], (int)$media['id'], $userId);
            return ['ok' => true, 'id' => $id, 'title' => $prep['data']['title']];
        } catch (UploadException $e) {
            return ['ok' => false, 'error' => $e->userMessage()];
        } catch (\Throwable $e) {
            ErrorHandler::report($e);
            if ($media !== null) {
                Document::dropMedia((int)$media['id']);
            }
            return ['ok' => false, 'error' => t('documents.error.save')];
        }
    }

    /**
     * Загрузить несколько файлов подряд (форма без JS).
     * @return array{ok:int,total:int,errors:array<int,string>,ids:int[]}
     */
    public static function addFiles(int $pageId, array $rows, ?int $userId, ?DocumentUploader $uploader = null): array
    {
        $out = ['ok' => 0, 'total' => count($rows), 'errors' => [], 'ids' => []];
        foreach ($rows as $i => $row) {
            $res = self::addFile($pageId, $row['file'], $row['meta'], $userId, $uploader);
            if ($res['ok']) {
                $out['ok']++;
                $out['ids'][] = (int)$res['id'];
            } else {
                $name = (string)($row['file']['name'] ?? ($row['meta']['title'] ?? ''));
                $out['errors'][$i] = ($name !== '' ? $name . ': ' : '') . (string)$res['error'];
            }
        }
        return $out;
    }
}
