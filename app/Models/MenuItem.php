<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Меню сайта (главное и подвал): дерево до двух уровней. Страницы, разделы, свои ссылки, группы-заголовки. */
final class MenuItem
{
    public const MENUS = ['main', 'footer'];
    public const TYPES = ['page', 'section', 'link', 'group'];
    public const MAX_ITEMS = 100;
    public const TITLE_MAX = 100;
    /** раздел => [путь на сайте, ключ подсветки (meta.active), ключ строки темы nav.*, ключ флага Sections::flags] */
    public const SECTIONS = [
        'home'         => ['/', 'home', 'home', null],
        'news'         => ['/novosti', 'novosti', 'news', 'news'],
        'gallery'      => ['/fotogalereya', 'fotogalereya', 'gallery', 'gallery'],
        'videos'       => ['/video', 'video', 'videos', 'videos'],
        'staff'        => ['/pedagogi', 'pedagogi', 'staff', 'staff'],
        'groups'       => ['/gruppy', 'gruppy', 'groups', 'groups'],
        'achievements' => ['/dostizheniya', 'dostizheniya', 'achievements', 'achievements'],
        'reviews'      => ['/otzyvy', 'otzyvy', 'reviews', 'reviews'],
        'contacts'     => ['/kontakty', 'kontakty', 'contacts', null],
        'documents'    => ['/dokumenty', 'dokumenty', 'documents', 'documents'],
    ];

    /** Пункты меню плоским списком в порядке обхода дерева, с данными страницы. */
    public static function flat(string $menu): array
    {
        $rows = Database::fetchAll(
            'SELECT m.*, p.title AS page_title, p.title_kk AS page_title_kk, p.slug AS page_slug, p.is_published AS page_published
             FROM menu_items m LEFT JOIN pages p ON p.id = m.page_id WHERE m.menu = ? ORDER BY m.sort, m.id',
            [$menu]
        );
        $kids = [];
        foreach ($rows as $r) {
            $kids[(int)($r['parent_id'] ?? 0)][] = $r;
        }
        $out = [];
        foreach ($kids[0] ?? [] as $top) {
            $top['depth'] = 0;
            $out[] = $top;
            foreach ($kids[(int)$top['id']] ?? [] as $sub) {
                $sub['depth'] = 1;
                $out[] = $sub;
            }
        }
        return $out;
    }

    /** Дерево: у каждого верхнего пункта поле children. */
    public static function tree(string $menu): array
    {
        $tree = [];
        foreach (self::flat($menu) as $it) {
            if ($it['depth'] === 0) {
                $it['children'] = [];
                $tree[] = $it;
            } else {
                $tree[count($tree) - 1]['children'][] = $it;
            }
        }
        return $tree;
    }

    /**
     * Проверка дерева из формы (JSON: [{type,page_id,section_key,url,title_ru,title_kk,target_blank,children:[...]}]).
     * @return array{items:array,errors:array<int,string>}
     */
    public static function normalize(mixed $tree): array
    {
        $errors = [];
        $items = [];
        $count = 0;
        if (!is_array($tree)) {
            return ['items' => [], 'errors' => [t('menus.error.format')]];
        }
        $pageIds = array_map('intval', array_column(Database::fetchAll('SELECT id FROM pages'), 'id'));
        $one = static function (mixed $n, int $depth) use (&$one, &$errors, &$count, $pageIds): ?array {
            if (!is_array($n)) {
                $errors[] = t('menus.error.format');
                return null;
            }
            $count++;
            $type = is_string($n['type'] ?? null) ? $n['type'] : '';
            $s = static fn(string $k): string => trim(preg_replace('/[\x00-\x1f\x7f]/u', '', is_string($n[$k] ?? null) ? $n[$k] : '') ?? '');
            $it = ['type' => $type, 'page_id' => null, 'section_key' => null, 'url' => null,
                'title_ru' => $s('title_ru'), 'title_kk' => $s('title_kk'), 'target_blank' => !empty($n['target_blank']) ? 1 : 0, 'children' => []];
            $label = $it['title_ru'] !== '' ? $it['title_ru'] : $type;
            if (!in_array($type, self::TYPES, true)) {
                $errors[] = t('menus.error.type');
                return null;
            }
            if (mb_strlen($it['title_ru']) > self::TITLE_MAX || mb_strlen($it['title_kk']) > self::TITLE_MAX) {
                $errors[] = t('menus.error.title_long', ['title' => $label, 'max' => self::TITLE_MAX]);
            }
            if ($type === 'page') {
                $pid = is_numeric($n['page_id'] ?? null) ? (int)$n['page_id'] : 0;
                if (!in_array($pid, $pageIds, true)) {
                    $errors[] = t('menus.error.page');
                } else {
                    $it['page_id'] = $pid;
                }
            } elseif ($type === 'section') {
                $key = is_string($n['section_key'] ?? null) ? $n['section_key'] : '';
                if (!isset(self::SECTIONS[$key])) {
                    $errors[] = t('menus.error.section');
                } else {
                    $it['section_key'] = $key;
                }
            } elseif ($type === 'link') {
                $url = $s('url');
                if (!self::validUrl($url)) {
                    $errors[] = t('menus.error.url', ['title' => $label]);
                } else {
                    $it['url'] = $url;
                }
                if ($it['title_ru'] === '') {
                    $errors[] = t('menus.error.title_required');
                }
            } elseif ($it['title_ru'] === '') {
                $errors[] = t('menus.error.title_required');
            }
            $children = $n['children'] ?? [];
            if (is_array($children) && $children) {
                if ($depth >= 1) {
                    $errors[] = t('menus.error.depth');
                } else {
                    foreach ($children as $c) {
                        if (($x = $one($c, $depth + 1)) !== null) {
                            $it['children'][] = $x;
                        }
                    }
                }
            }
            return $it;
        };
        foreach ($tree as $n) {
            if (($x = $one($n, 0)) !== null) {
                $items[] = $x;
            }
        }
        if ($count > self::MAX_ITEMS) {
            $errors[] = t('menus.error.many', ['max' => self::MAX_ITEMS]);
        }
        return ['items' => $items, 'errors' => array_values(array_unique($errors))];
    }

    /** Только http(s) или путь от корня сайта (/...), но не //host. */
    public static function validUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 500 || preg_match('/\s/', $url)) {
            return false;
        }
        if (preg_match('~^https?://[^/\s]+~i', $url)) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        }
        return $url[0] === '/' && !str_starts_with($url, '//') && !str_contains($url, '\\');
    }

    /** Заменить меню целиком (дерево уже проверено normalize()). */
    public static function replace(string $menu, array $items): void
    {
        Database::immediate(static function () use ($menu, $items): void {
            Database::query('DELETE FROM menu_items WHERE menu = ?', [$menu]);
            $put = static function (array $it, ?int $parent, int $sort) use ($menu): int {
                return Database::insert('menu_items', [
                    'menu' => $menu, 'parent_id' => $parent, 'sort' => $sort, 'type' => $it['type'], 'page_id' => $it['page_id'],
                    'section_key' => $it['section_key'], 'url' => $it['url'], 'title_ru' => $it['title_ru'],
                    'title_kk' => $it['title_kk'], 'target_blank' => $it['target_blank'],
                ]);
            };
            foreach ($items as $i => $top) {
                $id = $put($top, null, $i + 1);
                foreach ($top['children'] as $j => $sub) {
                    $put($sub, $id, $j + 1);
                }
            }
        });
    }

    /** Перед удалением страницы: подпункты её пунктов выносятся на верхний уровень, пункты удаляются. */
    public static function detachPage(int $pageId): void
    {
        $ids = array_map('intval', array_column(Database::fetchAll('SELECT id FROM menu_items WHERE page_id = ?', [$pageId]), 'id'));
        foreach ($ids as $id) {
            $max = (int)Database::fetchValue('SELECT COALESCE(MAX(sort), 0) FROM menu_items WHERE menu = (SELECT menu FROM menu_items WHERE id = ?) AND parent_id IS NULL', [$id]);
            foreach (Database::fetchAll('SELECT id FROM menu_items WHERE parent_id = ? ORDER BY sort, id', [$id]) as $k => $c) {
                Database::query('UPDATE menu_items SET parent_id = NULL, sort = ? WHERE id = ?', [$max + $k + 1, $c['id']]);
            }
        }
        Database::query('DELETE FROM menu_items WHERE page_id = ?', [$pageId]);
    }
}
