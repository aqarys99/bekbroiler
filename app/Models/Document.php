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

    /** @return array<int,array> документы категории (0 — все) с данными файла */
    public static function all(int $categoryId = 0): array
    {
        $rows = $categoryId > 0
            ? Database::fetchAll(self::baseSql() . ' WHERE d.category_id = ? ORDER BY c.sort, d.sort, d.id', [$categoryId])
            : Database::fetchAll(self::baseSql() . ' ORDER BY c.sort, d.sort, d.id');
        $today = self::today();
        foreach ($rows as &$r) {
            $r += self::statusOf($r, $today);
        }
        return $rows;
    }

    /** @return array<int,int> category_id => число документов */
    public static function counts(): array
    {
        $out = [];
        foreach (Database::fetchAll('SELECT category_id, COUNT(*) AS n FROM documents GROUP BY category_id') as $r) {
            $out[(int)$r['category_id']] = (int)$r['n'];
        }
        return $out;
    }

    private static function baseSql(): string
    {
        return 'SELECT d.*, c.title AS category_title, c.title_kk AS category_title_kk, m.path AS file_path, m.original_name AS file_name, m.size AS file_size, m.mime AS file_mime
                FROM documents d JOIN document_categories c ON c.id = d.category_id JOIN media m ON m.id = d.media_id';
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
        $cat = preg_match('/^\d{1,9}$/', $s('category_id')) ? (int)$s('category_id') : 0;
        if (self::category($cat) === null) {
            $errors['category_id'] = t('documents.error.category');
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
            'title' => $title, 'title_kk' => $titleKk, 'category_id' => $cat,
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
            $sort = (int)Database::fetchValue('SELECT COALESCE(MAX(sort), 0) FROM documents WHERE category_id = ?', [$data['category_id']]) + 1;
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

    /** Опубликованный PDF для страницы просмотра (иначе null). */
    public static function findPublishedPdf(int $id): ?array
    {
        $row = self::find($id);
        if ($row === null || (int)$row['is_published'] !== 1 || !self::isPdf($row)) {
            return null;
        }
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

    /** Порядок: сдвиг на одну позицию внутри категории. dir = -1 (вверх) | 1 (вниз). */
    public static function move(int $id, int $dir): bool
    {
        return (bool)Database::immediate(static function () use ($id, $dir): bool {
            $row = Database::fetch('SELECT id, category_id FROM documents WHERE id = ?', [$id]);
            if ($row === null) {
                return false;
            }
            $ids = array_map('intval', array_column(
                Database::fetchAll('SELECT id FROM documents WHERE category_id = ? ORDER BY sort, id', [$row['category_id']]),
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

    /**
     * Для публичного сайта (I-06, фича 11): опубликованные документы по категориям.
     * @return array<int,array{category:array,documents:array<int,array>}> пустые категории пропускаются
     */
    public static function publishedByCategory(): array
    {
        $groups = [];
        foreach (self::categories() as $c) {
            $groups[(int)$c['id']] = ['category' => $c, 'documents' => []];
        }
        $rows = Database::fetchAll(self::baseSql() . ' WHERE d.is_published = 1 ORDER BY c.sort, d.sort, d.id');
        foreach ($rows as $r) {
            $r['url'] = self::fileUrl($r);
            $r['ext'] = self::ext($r);
            $groups[(int)$r['category_id']]['documents'][] = $r;
        }
        return array_values(array_filter($groups, static fn(array $g): bool => $g['documents'] !== []));
    }
}
