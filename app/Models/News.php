<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Audit;
use App\Core\Clock;
use App\Core\Database;
use App\Core\HtmlSanitizer;
use App\Core\Slug;
use App\Core\ValidationException;
use DateTimeImmutable;

final class News
{
    public const TITLE_MAX = 120;
    public const EXCERPT_MAX = 300;
    public const BODY_MAX = 200000;

    /**
     * SQL-вариант isTranslated() (для списка, счётчиков, статистики): алиасы n (news) и k (news_translations).
     * Любое изменение правила — вместе с isTranslated().
     */
    private const TRANSLATED_SQL = "(COALESCE(k.title, '') <> '' AND (COALESCE(k.body, '') <> '' OR n.body = '')"
        . " AND NOT (k.title = n.title AND COALESCE(k.body, '') = n.body))";

    public static function newFormKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    // ---------------------------------------------------------------- состояние и перевод

    /** Единственное вычисление состояния (I-29): draft | scheduled | published. */
    public static function state(array $row, ?DateTimeImmutable $now = null): string
    {
        if (($row['status'] ?? 'draft') !== 'published' || empty($row['published_at'])) {
            return 'draft';
        }
        $now ??= Clock::nowUtc();
        return (string)$row['published_at'] > $now->format(Clock::FORMAT) ? 'scheduled' : 'published';
    }

    /**
     * Есть ли казахский перевод (I-10). Ключи строки: title, body, kk_title, kk_body.
     * Правило: казахский заголовок непуст; текст непуст (если русский текст есть);
     * и версия не является копией русской без изменений (одинаковые И заголовок, И текст = «нет перевода»).
     */
    public static function isTranslated(array $row): bool
    {
        $kkTitle = trim((string)($row['kk_title'] ?? ''));
        $kkBody = trim((string)($row['kk_body'] ?? ''));
        $ruTitle = trim((string)($row['title'] ?? ''));
        $ruBody = trim((string)($row['body'] ?? ''));
        if ($kkTitle === '' || ($kkBody === '' && $ruBody !== '')) {
            return false;
        }
        return !($kkTitle === $ruTitle && $kkBody === $ruBody);
    }

    /** @return array{total:int,translated:int} */
    public static function translationStats(): array
    {
        $r = Database::fetch(
            "SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN " . self::TRANSLATED_SQL . " THEN 1 ELSE 0 END), 0) AS translated
             FROM news n LEFT JOIN news_translations k ON k.news_id = n.id AND k.lang = 'kk'"
        );
        return ['total' => (int)($r['total'] ?? 0), 'translated' => (int)($r['translated'] ?? 0)];
    }

    // ---------------------------------------------------------------- выборки

    public static function cleanQuery(mixed $q): string
    {
        return Media::cleanQuery($q);
    }

    public static function normalizeState(mixed $s): string
    {
        return is_string($s) && in_array($s, ['all', 'published', 'draft', 'scheduled'], true) ? $s : 'all';
    }

    /** @return array{0:string,1:array} where-часть и параметры для поиска/состояния */
    private static function filter(string $q, string $state, string $now, bool $kkMissing = false): array
    {
        $where = [];
        $params = [];
        if ($q !== '') {
            Database::registerLower();
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q, 'UTF-8')) . '%';
            $where[] = "(php_lower(n.title) LIKE ? ESCAPE '\\' OR php_lower(COALESCE(k.title, '')) LIKE ? ESCAPE '\\')";
            $params[] = $like;
            $params[] = $like;
        }
        switch ($state) {
            case 'published':
                $where[] = "n.status = 'published' AND n.published_at <= ?";
                $params[] = $now;
                break;
            case 'scheduled':
                $where[] = "n.status = 'published' AND n.published_at > ?";
                $params[] = $now;
                break;
            case 'draft':
                $where[] = "n.status = 'draft'";
                break;
        }
        if ($kkMissing) {
            $where[] = 'NOT ' . self::TRANSLATED_SQL;
        }
        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    private const FROM = "FROM news n
        LEFT JOIN news_translations k ON k.news_id = n.id AND k.lang = 'kk'
        LEFT JOIN users u ON u.id = n.author_id";

    /** @return array{items:array<int,array>,total:int,page:int,pages:int,per_page:int} */
    public static function list(string $q = '', string $state = 'all', int $page = 1, ?int $perPage = null, bool $kkMissing = false): array
    {
        $perPage ??= max(1, (int)config('news.per_page', 20));
        $q = self::cleanQuery($q);
        $now = Clock::nowUtc();
        [$w, $params] = self::filter($q, self::normalizeState($state), $now->format(Clock::FORMAT), $kkMissing);
        $total = (int)Database::fetchValue("SELECT COUNT(*) " . self::FROM . " $w", $params);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $rows = Database::fetchAll(
            "SELECT n.id, n.slug, n.title, n.status, n.published_at, n.updated_at, n.cover_media_id, n.author_id,
                    u.name AS author_name, " . self::TRANSLATED_SQL . " AS kk_ready "
            . self::FROM . " $w ORDER BY COALESCE(n.published_at, n.updated_at) DESC, n.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
            $params
        );
        $covers = Media::findMany(array_filter(array_map(static fn($r) => $r['cover_media_id'], $rows), static fn($v) => $v !== null));
        foreach ($rows as &$r) {
            $r['state'] = self::state($r, $now);
            $r['kk_ready'] = (bool)$r['kk_ready'];
            $r['cover'] = $r['cover_media_id'] !== null ? ($covers[(int)$r['cover_media_id']] ?? null) : null;
        }
        unset($r);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $perPage];
    }

    /** @return array{all:int,published:int,draft:int,scheduled:int} с тем же q */
    public static function counts(string $q = ''): array
    {
        $now = Clock::nowUtc()->format(Clock::FORMAT);
        [$w, $params] = self::filter(self::cleanQuery($q), 'all', $now);
        $r = Database::fetch(
            "SELECT COUNT(*) AS c_all,
                COALESCE(SUM(CASE WHEN n.status = 'published' AND n.published_at <= ? THEN 1 ELSE 0 END), 0) AS c_pub,
                COALESCE(SUM(CASE WHEN n.status = 'draft' THEN 1 ELSE 0 END), 0) AS c_draft,
                COALESCE(SUM(CASE WHEN n.status = 'published' AND n.published_at > ? THEN 1 ELSE 0 END), 0) AS c_sched "
            . self::FROM . " $w",
            array_merge([$now, $now], $params)
        );
        return [
            'all' => (int)$r['c_all'], 'published' => (int)$r['c_pub'],
            'draft' => (int)$r['c_draft'], 'scheduled' => (int)$r['c_sched'],
        ];
    }

    /** Полная запись: kk_*, author_name, cover, photos (по sort), state. */
    public static function find(int $id): ?array
    {
        $row = Database::fetch(
            "SELECT n.*, COALESCE(k.title, '') AS kk_title, COALESCE(k.excerpt, '') AS kk_excerpt, COALESCE(k.body, '') AS kk_body,
                    u.name AS author_name " . self::FROM . " WHERE n.id = ?",
            [$id]
        );
        return $row === null ? null : self::hydrate($row);
    }

    private static function hydrate(array $row): array
    {
        $row['state'] = self::state($row);
        $row['cover'] = $row['cover_media_id'] !== null ? Media::find((int)$row['cover_media_id']) : null;
        $row['photos'] = Database::fetchAll(
            "SELECT m.* FROM news_media nm JOIN media m ON m.id = nm.media_id
             WHERE nm.news_id = ? AND nm.place = 'gallery' ORDER BY nm.sort, nm.media_id",
            [(int)$row['id']]
        );
        return $row;
    }

    /**
     * Для публичного сайта (I-03): только опубликованные и наступившие.
     * @return array{items:array<int,array>,total:int,page:int,pages:int}
     */
    public static function published(int $page = 1, int $perPage = 10): array
    {
        $perPage = max(1, $perPage);
        $now = Clock::nowUtc()->format(Clock::FORMAT);
        $total = (int)Database::fetchValue("SELECT COUNT(*) FROM news WHERE status = 'published' AND published_at <= ?", [$now]);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $rows = Database::fetchAll(
            "SELECT n.id, n.slug, n.title, n.excerpt, n.published_at, n.cover_media_id,
                    COALESCE(k.title, '') AS kk_title, COALESCE(k.excerpt, '') AS kk_excerpt,
                    " . self::TRANSLATED_SQL . " AS kk_ready
             FROM news n LEFT JOIN news_translations k ON k.news_id = n.id AND k.lang = 'kk'
             WHERE n.status = 'published' AND n.published_at <= ?
             ORDER BY n.published_at DESC, n.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
            [$now]
        );
        $covers = Media::findMany(array_filter(array_map(static fn($r) => $r['cover_media_id'], $rows), static fn($v) => $v !== null));
        foreach ($rows as &$r) {
            $r['kk_ready'] = (bool)$r['kk_ready'];
            $r['cover'] = $r['cover_media_id'] !== null ? ($covers[(int)$r['cover_media_id']] ?? null) : null;
        }
        unset($r);
        return ['items' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public static function findPublishedBySlug(string $slug): ?array
    {
        $row = Database::fetch(
            "SELECT n.*, COALESCE(k.title, '') AS kk_title, COALESCE(k.excerpt, '') AS kk_excerpt, COALESCE(k.body, '') AS kk_body,
                    u.name AS author_name " . self::FROM . "
             WHERE n.slug = ? AND n.status = 'published' AND n.published_at <= ?",
            [$slug, Clock::nowUtc()->format(Clock::FORMAT)]
        );
        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Для главной админки (I-04): последние новости во всех состояниях.
     * @return array<int,array{id:int,title:string,state:string,edit_url:string,date:string,published_at:?string}>
     */
    public static function latest(int $limit = 3): array
    {
        $limit = max(1, $limit);
        $now = Clock::nowUtc();
        $rows = Database::fetchAll(
            "SELECT id, title, status, published_at, updated_at FROM news
             ORDER BY COALESCE(published_at, updated_at) DESC, id DESC LIMIT $limit"
        );
        $out = [];
        foreach ($rows as $r) {
            $when = (string)($r['published_at'] ?? $r['updated_at']);
            $out[] = [
                'id'           => (int)$r['id'],
                'title'        => (string)$r['title'],
                'state'        => self::state($r, $now),
                'edit_url'     => admin_url('/news/' . (int)$r['id'] . '/edit'),
                'date'         => Clock::toLocal($when)->format('d.m.Y'),
                'published_at' => $r['published_at'],
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------- адрес

    public static function uniqueSlug(string $base, ?int $exceptId): string
    {
        $base = $base === '' ? 'novost' : $base;
        $rows = Database::fetchAll(
            "SELECT slug FROM news WHERE (slug = ? OR slug LIKE ?) AND id IS NOT ?",
            [$base, $base . '-%', $exceptId]
        );
        $taken = array_flip(array_column($rows, 'slug'));
        if (!isset($taken[$base])) {
            return $base;
        }
        for ($i = 2; ; $i++) {
            $suffix = '-' . $i;
            $cand = rtrim(substr($base, 0, Slug::MAX - strlen($suffix)), '-') . $suffix;
            if (!isset($taken[$cand])) {
                return $cand;
            }
        }
    }

    /** @return array{id:int,title:string}|null другая новость с этим адресом */
    public static function slugOwner(string $slug, ?int $exceptId): ?array
    {
        $r = Database::fetch('SELECT id, title FROM news WHERE slug = ? AND id IS NOT ?', [$slug, $exceptId]);
        return $r === null ? null : ['id' => (int)$r['id'], 'title' => (string)$r['title']];
    }

    // ---------------------------------------------------------------- тело

    /** id фото из <img data-media-id> в порядке появления. @return int[] */
    public static function bodyMediaIds(string ...$bodies): array
    {
        $ids = [];
        foreach ($bodies as $html) {
            if (preg_match_all('/<img\b[^>]*\bdata-media-id="(\d+)"/i', $html, $m)) {
                foreach ($m[1] as $id) {
                    $ids[(int)$id] = (int)$id;
                }
            }
        }
        return array_values($ids);
    }

    /** Санитизированный HTML -> HTML страницы: img -> media_picture (I-30). */
    public static function renderBody(string $html, string $size = 'medium'): string
    {
        $ids = self::bodyMediaIds($html);
        if (!$ids) {
            return $html;
        }
        $rows = Media::findMany($ids);
        return (string)preg_replace_callback(
            '/<img\b[^>]*\bdata-media-id="(\d+)"[^>]*>/i',
            static fn(array $m): string => media_picture($rows[(int)$m[1]] ?? null, $size),
            $html
        );
    }

    /** HTML для редактора: у img добавляются src (medium JPEG) и alt. */
    public static function bodyForEditor(string $html): string
    {
        $ids = self::bodyMediaIds($html);
        if (!$ids) {
            return $html;
        }
        $rows = Media::findMany($ids);
        return (string)preg_replace_callback(
            '/<img\b[^>]*\bdata-media-id="(\d+)"[^>]*>/i',
            static function (array $m) use ($rows): string {
                $r = $rows[(int)$m[1]] ?? null;
                if ($r === null) {
                    return '';
                }
                $src = media_url($r, 'medium', 'jpg') ?: media_url($r, 'thumb', 'jpg');
                return '<img data-media-id="' . (int)$m[1] . '" src="' . e($src) . '" alt="' . e((string)$r['alt']) . '">';
            },
            $html
        );
    }

    // ---------------------------------------------------------------- разбор и проверка формы

    private static function str(array $post, string $key): string
    {
        $v = $post[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    private static function line(string $s): string
    {
        return trim((string)preg_replace('/[\x00-\x08\x0b-\x1f\x7f]+/', ' ', $s));
    }

    private static function text(string $s): string
    {
        return trim((string)preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', str_replace("\r\n", "\n", $s)));
    }

    /**
     * Разбор формы. strict=false (предпросмотр): длинное обрезается, неверные id пропускаются, ошибок нет.
     * Ключи ошибок = имена полей формы; тексты готовые.
     * @param ?array $current текущая запись (find) или null
     * @param string|null $forceIntent 'draft' для автосохранения
     * @return array{data:array<string,mixed>,errors:array<string,string>}
     */
    public static function prepare(array $post, ?array $current, bool $strict = true, ?string $forceIntent = null): array
    {
        $errors = [];
        $now = Clock::nowUtc();

        $title = self::line(self::str($post, 'title_ru'));
        $titleKk = self::line(self::str($post, 'title_kk'));
        $excerpt = self::text(self::str($post, 'excerpt_ru'));
        $excerptKk = self::text(self::str($post, 'excerpt_kk'));
        $limit = static function (string $v, int $max, string $field) use ($strict, &$errors): string {
            if (mb_strlen($v) <= $max) {
                return $v;
            }
            if ($strict) {
                $errors[$field] = t('news.error.too_long', ['max' => $max]);
                return $v;
            }
            return mb_substr($v, 0, $max);
        };
        $title = $limit($title, self::TITLE_MAX, 'title_ru');
        $titleKk = $limit($titleKk, self::TITLE_MAX, 'title_kk');
        $excerpt = $limit($excerpt, self::EXCERPT_MAX, 'excerpt_ru');
        $excerptKk = $limit($excerptKk, self::EXCERPT_MAX, 'excerpt_kk');
        if ($strict && $title === '') {
            $errors['title_ru'] = t('news.error.title_required');
        }

        $bodies = [];
        foreach (['body_ru', 'body_kk'] as $f) {
            $raw = self::str($post, $f);
            if (strlen($raw) > self::BODY_MAX * 5) {
                $errors[$f] = t('news.error.body_too_long');
                $bodies[$f] = '';
                continue;
            }
            $clean = HtmlSanitizer::clean($raw);
            if (strlen($clean) > self::BODY_MAX) {
                if ($strict) {
                    $errors[$f] = t('news.error.body_too_long');
                }
                $clean = $strict ? $clean : '';
            }
            $bodies[$f] = $clean;
        }

        // фото
        $cover = null;
        $coverIds = Media::parseIds($post['cover_media_id'] ?? null);
        $photoIds = Media::parseIds($post['photos'] ?? []);
        $existing = Media::findMany(array_merge($coverIds, $photoIds));
        $isImage = static fn(int $id): bool => isset($existing[$id]) && $existing[$id]['type'] === 'image';
        if ($coverIds) {
            if ($isImage($coverIds[0])) {
                $cover = $coverIds[0];
            } elseif ($strict) {
                $errors['cover_media_id'] = t('news.error.photo_missing');
            }
        }
        $photos = [];
        foreach ($photoIds as $id) {
            if ($isImage($id)) {
                $photos[] = $id;
            } elseif ($strict) {
                $errors['photos'] = t('news.error.photo_missing');
            }
        }
        $maxPhotos = max(1, (int)config('news.max_photos', 50));
        if (count($photos) > $maxPhotos) {
            if ($strict) {
                $errors['photos'] = t('news.error.too_many_photos', ['max' => $maxPhotos]);
            } else {
                $photos = array_slice($photos, 0, $maxPhotos);
            }
        }

        // статус
        $intent = $forceIntent ?? (self::str($post, 'intent') === 'draft' ? 'draft' : 'submit');
        $mode = self::str($post, 'publish_mode');
        $mode = in_array($mode, ['draft', 'now', 'later'], true) ? $mode : 'draft';
        $status = 'draft';
        $publishedAt = null;
        if ($intent === 'submit' && $mode === 'now') {
            $status = 'published';
            $publishedAt = $current !== null && self::state($current, $now) === 'published'
                ? (string)$current['published_at'] : $now->format(Clock::FORMAT);
        } elseif ($intent === 'submit' && $mode === 'later') {
            $at = Clock::fromLocal(self::str($post, 'publish_date'), self::str($post, 'publish_time'));
            if ($at === null || $at <= $now) {
                if ($strict) {
                    $errors['publish_date'] = t('news.error.schedule');
                }
            } else {
                $status = 'published';
                $publishedAt = $at->format(Clock::FORMAT);
            }
        }

        // адрес
        $slugRaw = trim(self::str($post, 'slug'));
        $auto = self::str($post, 'slug_auto') === '1' || $slugRaw === '';
        $slug = null; // null = сгенерировать из заголовка внутри транзакции
        if (!$auto) {
            $slug = Slug::make($slugRaw);
            $exceptId = $current !== null ? (int)$current['id'] : null;
            $owner = self::slugOwner($slug, $exceptId);
            if ($owner !== null && $strict) {
                $errors['slug'] = t('news.error.slug_taken', ['title' => $owner['title']]);
            }
        }

        $formKey = self::str($post, 'form_key');
        return [
            'data' => [
                'title' => $title, 'excerpt' => $excerpt, 'body' => $bodies['body_ru'] ?? '',
                'title_kk' => $titleKk, 'excerpt_kk' => $excerptKk, 'body_kk' => $bodies['body_kk'] ?? '',
                'cover_media_id' => $cover, 'photos' => $photos,
                'status' => $status, 'published_at' => $publishedAt,
                'slug' => $slug, 'form_key' => preg_match('/^[a-f0-9]{32}$/', $formKey) ? $formKey : null,
            ],
            'errors' => $errors,
        ];
    }

    // ---------------------------------------------------------------- запись

    public static function findByFormKey(string $key): ?array
    {
        $id = Database::fetchValue('SELECT id FROM news WHERE form_key = ?', [$key]);
        return $id === null ? null : self::find((int)$id);
    }

    /**
     * Создать/обновить. В одной транзакции: проверка версии, запись, пересборка news_media, аудит.
     * При $id = null и известном form_key обновляется уже созданная запись (идемпотентность).
     * @param array<string,mixed> $data результат prepare()['data']
     * @return array{id:int,version:int,created:bool,before:?string,after:string,slug:string,published_at:?string}
     * @throws NewsConflictException
     * @throws ValidationException (slug занят, фото удалено между проверкой и записью)
     */
    public static function save(?int $id, array $data, int $userId, ?int $expectedVersion, bool $audit = true): array
    {
        try {
            return (array)Database::immediate(function () use ($id, $data, $userId, $expectedVersion, $audit): array {
                $current = null;
                if ($id !== null) {
                    $current = Database::fetch('SELECT * FROM news WHERE id = ?', [$id]);
                    if ($current === null) {
                        throw new \OutOfBoundsException('Новость не найдена');
                    }
                } elseif (!empty($data['form_key'])) {
                    $current = Database::fetch('SELECT * FROM news WHERE form_key = ?', [$data['form_key']]);
                    $expectedVersion = null;
                }
                if ($current !== null && $expectedVersion !== null && (int)$current['version'] !== $expectedVersion) {
                    throw new NewsConflictException();
                }
                $exceptId = $current !== null ? (int)$current['id'] : null;

                if ($data['slug'] === null) {
                    $slug = self::uniqueSlug(Slug::make((string)$data['title']), $exceptId);
                } else {
                    $slug = (string)$data['slug'];
                    $owner = self::slugOwner($slug, $exceptId);
                    if ($owner !== null) {
                        throw new ValidationException(['slug' => t('news.error.slug_taken', ['title' => $owner['title']])]);
                    }
                }

                $nowStr = Clock::nowUtc()->format(Clock::FORMAT);
                $before = $current !== null ? self::state($current) : null;
                $cols = [
                    'slug' => $slug, 'title' => $data['title'], 'excerpt' => $data['excerpt'], 'body' => $data['body'],
                    'cover_media_id' => $data['cover_media_id'], 'status' => $data['status'],
                    'published_at' => $data['published_at'], 'updated_by' => $userId, 'updated_at' => $nowStr,
                ];
                if ($current === null) {
                    $newId = Database::insert('news', $cols + [
                        'author_id' => $userId, 'version' => 1, 'form_key' => $data['form_key'], 'created_at' => $nowStr,
                    ]);
                    $version = 1;
                } else {
                    $newId = (int)$current['id'];
                    $version = (int)$current['version'] + 1;
                    $set = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($cols)));
                    Database::query(
                        "UPDATE news SET $set, version = ? WHERE id = ?",
                        [...array_values($cols), $version, $newId]
                    );
                }
                Database::query(
                    "INSERT INTO news_translations (news_id, lang, title, excerpt, body, updated_at) VALUES (?, 'kk', ?, ?, ?, ?)
                     ON CONFLICT(news_id, lang) DO UPDATE SET title = excluded.title, excerpt = excluded.excerpt,
                       body = excluded.body, updated_at = excluded.updated_at",
                    [$newId, $data['title_kk'], $data['excerpt_kk'], $data['body_kk'], $nowStr]
                );
                Database::query('DELETE FROM news_media WHERE news_id = ?', [$newId]);
                foreach (array_values($data['photos']) as $i => $mid) {
                    Database::query("INSERT INTO news_media (news_id, media_id, place, sort) VALUES (?, ?, 'gallery', ?)", [$newId, $mid, $i]);
                }
                foreach (self::bodyMediaIds((string)$data['body'], (string)$data['body_kk']) as $i => $mid) {
                    Database::query("INSERT INTO news_media (news_id, media_id, place, sort) VALUES (?, ?, 'body', ?)", [$newId, $mid, $i]);
                }

                $after = self::state(['status' => $data['status'], 'published_at' => $data['published_at']]);
                if ($audit) {
                    Audit::log($userId, $current === null ? 'news.create' : 'news.update', 'news', $newId);
                    $change = match (true) {
                        $after === 'draft' && in_array($before, ['published', 'scheduled'], true) => 'news.unpublish',
                        $after === 'published' && $before !== 'published' => 'news.publish',
                        $after === 'scheduled' && $before !== 'scheduled' => 'news.schedule',
                        default => null,
                    };
                    if ($change !== null) {
                        Audit::log($userId, $change, 'news', $newId);
                    }
                }
                return [
                    'id' => $newId, 'version' => $version, 'created' => $current === null, 'before' => $before,
                    'after' => $after, 'slug' => $slug, 'published_at' => $data['published_at'],
                ];
            });
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? $e->getCode()) == '23000' && stripos($e->getMessage(), 'FOREIGN KEY') !== false) {
                // фото удалено из медиатеки между проверкой и записью
                throw new ValidationException(['photos' => t('news.error.photo_missing')]);
            }
            throw $e;
        }
    }

    /**
     * Удалить с переводом и news_media (каскад). Фото освобождаются (I-28).
     * @param callable|null $inTransaction вызывается внутри транзакции (аудит)
     */
    public static function delete(int $id, ?callable $inTransaction = null): ?array
    {
        return Database::immediate(function () use ($id, $inTransaction): ?array {
            $row = Database::fetch('SELECT id, title FROM news WHERE id = ?', [$id]);
            if ($row === null) {
                return null;
            }
            Database::query('DELETE FROM news WHERE id = ?', [$id]);
            if ($inTransaction !== null) {
                $inTransaction($row);
            }
            return $row;
        });
    }

    /**
     * Данные для предпросмотра несохранённой формы (в БД не пишет).
     * @return array<string,mixed> строка вида find(): title, excerpt, body, kk_*, cover, photos, status, published_at, state
     */
    public static function previewRow(array $post, ?array $current): array
    {
        $d = self::prepare($post, $current, false)['data'];
        $ids = array_values(array_unique(array_merge($d['cover_media_id'] !== null ? [$d['cover_media_id']] : [], $d['photos'])));
        $media = Media::findMany($ids);
        // предпросмотр показывает выбранный режим публикации так, как будет после сохранения; несохранённая «later» в прошлом -> черновик
        $row = [
            'id' => $current['id'] ?? null, 'title' => $d['title'], 'excerpt' => $d['excerpt'], 'body' => $d['body'],
            'kk_title' => $d['title_kk'], 'kk_excerpt' => $d['excerpt_kk'], 'kk_body' => $d['body_kk'],
            'cover' => $d['cover_media_id'] !== null ? ($media[$d['cover_media_id']] ?? null) : null,
            'photos' => array_values(array_filter(array_map(static fn($i) => $media[$i] ?? null, $d['photos']))),
            'status' => $d['status'], 'published_at' => $d['published_at'],
        ];
        $row['state'] = self::state($row);
        return $row;
    }
}
