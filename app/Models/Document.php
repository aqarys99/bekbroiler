<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Clock;
use App\Core\Database;

final class Document
{
    public const TITLE_MAX = 200;
    public const WARN_DAYS = 30;
    public const ATTESTATION_CATEGORY = 1;
    public const VIEW_MODES = ['reader', 'book'];

    public static function today(): string
    {
        return Clock::nowUtc()->setTimezone(Clock::timezone())->format('Y-m-d');
    }

    /** @return array<int,array> */
    public static function categories(): array
    {
        return Database::fetchAll('SELECT * FROM document_categories ORDER BY sort, id');
    }

    public static function category(int $id): ?array
    {
        return Database::fetch('SELECT * FROM document_categories WHERE id = ?', [$id]);
    }

    /** @return array<int,array> */
    public static function checklistItems(): array
    {
        return Database::fetchAll('SELECT * FROM attestation_checklist ORDER BY sort, key');
    }

    public static function find(int $id): ?array
    {
        return Database::fetch(self::baseSql() . ' WHERE d.id = ?', [$id]);
    }

    /** @return array<int,array> документы страницы (0 — все) с данными файла и статусом срока */
    public static function all(int $pageId = 0): array
    {
        $rows = $pageId > 0
            ? Database::fetchAll(self::baseSql() . ' WHERE d.page_id = ? ORDER BY d.sort, d.id', [$pageId])
            : Database::fetchAll(self::baseSql() . ' ORDER BY p.sort, d.page_id, d.sort, d.id');
        $today = self::today();
        foreach ($rows as &$r) {
            $r += self::statusOf($r, $today);
        }
        return $rows;
    }

    /** @return array<int,int> page_id => число документов */
    public static function counts(): array
    {
        $out = [];
        foreach (Database::fetchAll('SELECT page_id, COUNT(*) AS n FROM documents WHERE page_id IS NOT NULL GROUP BY page_id') as $r) {
            $out[(int)$r['page_id']] = (int)$r['n'];
        }
        return $out;
    }

    private static function baseSql(): string
    {
        return 'SELECT d.*, p.title AS page_title, p.title_kk AS page_title_kk, p.slug AS page_slug, p.is_published AS page_published,
                       m.path AS file_path, m.original_name AS file_name, m.size AS file_size, m.mime AS file_mime
                FROM documents d LEFT JOIN pages p ON p.id = d.page_id JOIN media m ON m.id = d.media_id';
    }

    /**
     * Статус по сроку (I-05): ok | warn (<30 дней) | expired. days_left — до даты «действует до» (null без срока).
     * @return array{state:string,days_left:?int}
     */
    public static function statusOf(array $row, ?string $today = null): array
    {
        $until = (string)($row['valid_until'] ?? '');
        if ($until === '') {
            return ['state' => 'ok', 'days_left' => null];
        }
        $today ??= self::today();
        $days = (int)((strtotime($until . ' UTC') - strtotime($today . ' UTC')) / 86400);
        $state = $days < 0 ? 'expired' : ($days < self::WARN_DAYS ? 'warn' : 'ok');
        return ['state' => $state, 'days_left' => $days];
    }

    public static function fileUrl(array $row): string
    {
        return url('/uploads/' . ltrim((string)($row['file_path'] ?? ''), '/'));
    }

    public static function ext(array $row): string
    {
        return strtoupper(pathinfo((string)($row['file_path'] ?? ''), PATHINFO_EXTENSION));
    }

    /**
     * Проверка полей формы.
     * @return array{data:array<string,mixed>,errors:array<string,string>}
     */
    public static function prepare(array $post, bool $needFile): array
    {
        $errors = [];
        $s = static fn(string $k): string => is_string($post[$k] ?? null) ? trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $post[$k]) ?? '') : '';
        $title = $s('title');
        $titleKk = $s('title_kk');
        if ($title === '') {
            $errors['title'] = t('documents.error.title_required');
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = t('documents.error.title_long', ['max' => self::TITLE_MAX]);
        }
        if (mb_strlen($titleKk) > self::TITLE_MAX) {
            $errors['title_kk'] = t('documents.error.title_long', ['max' => self::TITLE_MAX]);
        }
        $pageId = preg_match('/^\d{1,9}$/', $s('page_id')) ? (int)$s('page_id') : 0;
        if (Page::find($pageId) === null) {
            $errors['page_id'] = t('documents.error.page');
        }
        $key = $s('checklist_key');
        if ($key !== '' && Database::fetchValue('SELECT 1 FROM attestation_checklist WHERE key = ?', [$key]) === null) {
            $errors['checklist_key'] = t('documents.error.checklist');
        }
        $dates = [];
        foreach (['doc_date', 'valid_until'] as $f) {
            $v = $s($f);
            if ($v !== '') {
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
                if ($d === false || $d->format('Y-m-d') !== $v) {
                    $errors[$f] = t('documents.error.date');
                }
            }
            $dates[$f] = $v;
        }
        if (!isset($errors['doc_date']) && !isset($errors['valid_until']) && $dates['doc_date'] !== '' && $dates['valid_until'] !== '' && $dates['valid_until'] < $dates['doc_date']) {
            $errors['valid_until'] = t('documents.error.valid_before');
        }
        $mode = $s('view_mode');
        return ['errors' => $errors, 'data' => [
            'view_mode' => in_array($mode, self::VIEW_MODES, true) ? $mode : 'reader',
            'title' => $title, 'title_kk' => $titleKk, 'page_id' => $pageId,
            'checklist_key' => $key !== '' ? $key : null,
            'doc_date' => $dates['doc_date'] !== '' ? $dates['doc_date'] : null,
            'valid_until' => $dates['valid_until'] !== '' ? $dates['valid_until'] : null,
        ]];
    }

    /** Создать документ (файл уже загружен в media). */
    public static function create(array $data, int $mediaId, ?int $userId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        return (int)Database::immediate(static function () use ($data, $mediaId, $userId, $now): int {
            $sort = (int)Database::fetchValue('SELECT COALESCE(MAX(sort), 0) FROM documents WHERE page_id = ?', [$data['page_id']]) + 1;
            return Database::insert('documents', $data + [
                'media_id' => $mediaId, 'sort' => $sort, 'is_published' => 1, 'created_by' => $userId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });
    }

    /** Изменить поля документа (без файла и порядка). */
    public static function update(int $id, array $data, bool $published): bool
    {
        $data['is_published'] = $published ? 1 : 0;
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        $sets = implode(', ', array_map(static fn(string $k): string => $k . ' = ?', array_keys($data)));
        return Database::query('UPDATE documents SET ' . $sets . ' WHERE id = ?', array_merge(array_values($data), [$id]))->rowCount() > 0;
    }

    public static function isPdf(array $row): bool
    {
        return self::ext($row) === 'PDF';
    }

    /** Для страницы просмотра: опубликованный PDF или DOCX на показанной странице (иначе null). kind = pdf | docx. */
    public static function findPublishedViewable(int $id): ?array
    {
        $row = self::find($id);
        if ($row === null || (int)$row['is_published'] !== 1 || ($row['page_id'] !== null && (int)$row['page_published'] !== 1)) {
            return null;
        }
        $ext = self::ext($row);
        if ($ext !== 'PDF' && $ext !== 'DOCX') {
            return null;
        }
        $row['kind'] = strtolower($ext);
        $row['url'] = self::fileUrl($row);
        return $row;
    }

    /** Подменить файл; возвращает id старого media (его удаляет вызывающий). */
    public static function replaceFile(int $id, int $mediaId): ?int
    {
        return Database::immediate(static function () use ($id, $mediaId): ?int {
            $old = Database::fetchValue('SELECT media_id FROM documents WHERE id = ?', [$id]);
            if ($old === null) {
                return null;
            }
            Database::query('UPDATE documents SET media_id = ?, updated_at = ? WHERE id = ?', [$mediaId, gmdate('Y-m-d H:i:s'), $id]);
            return (int)$old;
        });
    }

    /**
     * Удалить документ и его файл (если файл больше нигде не используется).
     * @param callable|null $audit вызывается внутри транзакции со строкой документа
     */
    public static function delete(int $id, ?callable $audit = null): ?array
    {
        $row = Database::immediate(static function () use ($id, $audit): ?array {
            $row = self::find($id);
            if ($row === null) {
                return null;
            }
            Database::query('DELETE FROM documents WHERE id = ?', [$id]);
            if ($audit !== null) {
                $audit($row);
            }
            return $row;
        });
        if ($row !== null) {
            self::dropMedia((int)$row['media_id']);
        }
        return $row;
    }

    /** Удалить файл, если он уже не нужен; сбой не критичен (останется в медиатеке). */
    public static function dropMedia(int $mediaId): void
    {
        try {
            Media::delete($mediaId);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Файл документа не удалён', ['media_id' => $mediaId, 'error' => $e->getMessage()]);
        }
    }

    /** Порядок: сдвиг на одну позицию внутри страницы. dir = -1 (вверх) | 1 (вниз). */
    public static function move(int $id, int $dir): bool
    {
        return (bool)Database::immediate(static function () use ($id, $dir): bool {
            $row = Database::fetch('SELECT id, page_id FROM documents WHERE id = ?', [$id]);
            if ($row === null) {
                return false;
            }
            $ids = array_map('intval', array_column(
                Database::fetchAll('SELECT id FROM documents WHERE page_id IS ? ORDER BY sort, id', [$row['page_id']]),
                'id'
            ));
            $i = array_search($id, $ids, true);
            $j = $i + ($dir < 0 ? -1 : 1);
            if ($i === false || !isset($ids[$j])) {
                return false;
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $n => $docId) {
                Database::query('UPDATE documents SET sort = ? WHERE id = ?', [$n + 1, $docId]);
            }
            return true;
        });
    }

    /** Новый порядок документов страницы: $ids — id документов этой страницы в нужном порядке. */
    public static function reorder(int $pageId, array $ids): bool
    {
        return (bool)Database::immediate(static function () use ($pageId, $ids): bool {
            $own = array_map('intval', array_column(Database::fetchAll('SELECT id FROM documents WHERE page_id = ?', [$pageId]), 'id'));
            $ids = array_values(array_unique(array_map('intval', $ids)));
            if (!$ids || array_diff($ids, $own)) {
                return false;
            }
            $rest = array_values(array_diff($own, $ids));
            foreach (array_merge($ids, $rest) as $n => $docId) {
                Database::query('UPDATE documents SET sort = ? WHERE id = ?', [$n + 1, $docId]);
            }
            return true;
        });
    }

    /** Опубликованные документы страницы для сайта (url, ext, can_view = есть просмотрщик). */
    public static function publishedForPage(int $pageId): array
    {
        $out = [];
        foreach (Database::fetchAll(self::baseSql() . ' WHERE d.page_id = ? AND d.is_published = 1 ORDER BY d.sort, d.id', [$pageId]) as $r) {
            $out[] = self::forSite($r);
        }
        return $out;
    }

    private static function forSite(array $r): array
    {
        $r['url'] = self::fileUrl($r);
        $r['ext'] = self::ext($r);
        $r['can_view'] = in_array($r['ext'], ['PDF', 'DOCX'], true);
        return $r;
    }

    /**
     * Для общего списка /dokumenty (I-06): опубликованные документы показанных страниц, по страницам.
     * @return array<int,array{page:array,documents:array<int,array>}>
     */
    public static function publishedByPage(): array
    {
        $groups = [];
        $rows = Database::fetchAll(self::baseSql() . ' WHERE d.is_published = 1 AND p.is_published = 1 ORDER BY p.sort, p.id, d.sort, d.id');
        foreach ($rows as $r) {
            $pid = (int)$r['page_id'];
            $groups[$pid]['page'] ??= ['id' => $pid, 'slug' => $r['page_slug'], 'title' => $r['page_title'], 'title_kk' => $r['page_title_kk']];
            $groups[$pid]['documents'][] = self::forSite($r);
        }
        return array_values($groups);
    }
}
