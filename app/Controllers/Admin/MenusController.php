<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Flash;
use App\Models\MenuItem;
use App\Models\Page;

/** Внешний вид -> Меню (только owner): главное меню и подвал сайта. */
final class MenusController extends BaseController
{
    public function index(array $params = []): string
    {
        $menu = $this->menuParam($_GET['menu'] ?? '');
        $tree = [];
        foreach (MenuItem::tree($menu) as $top) {
            $item = $this->exportRow($top);
            foreach ($top['children'] as $sub) {
                $item['children'][] = $this->exportRow($sub);
            }
            $tree[] = $item;
        }
        return $this->render($menu, $tree, 200, []);
    }

    public function save(array $params = []): string
    {
        $user = $this->currentUser();
        $menu = $this->menuParam($_POST['menu'] ?? '');
        $raw = self::post('tree');
        $decoded = strlen($raw) <= 200000 ? json_decode($raw, true) : null;
        $res = MenuItem::normalize($decoded);
        if ($res['errors']) {
            return $this->render($menu, array_map(fn(array $i): array => $this->exportItem($i), is_array($decoded) ? $res['items'] : []), 422, $res['errors']);
        }
        MenuItem::replace($menu, $res['items']);
        Audit::log((int)$user['id'], 'menus.save', 'menu_items', null);
        Flash::set('success', 'menus.flash.saved');
        redirect(admin_url('/menus') . '?menu=' . $menu);
    }

    private function render(string $menu, array $tree, int $status, array $errors): string
    {
        $pages = [];
        foreach (Page::options() as $p) {
            $pages[] = ['id' => (int)$p['id'], 'title' => (string)$p['title'], 'slug' => (string)$p['slug'], 'hidden' => (int)$p['is_published'] !== 1];
        }
        $sections = [];
        foreach (MenuItem::SECTIONS as $key => $def) {
            $sections[] = ['key' => $key, 'label' => t('menus.section.' . $key)];
        }
        return $this->page('menus/index', t('menus.title'), [
            $this->home(), ['label' => t('menus.group'), 'url' => null], ['label' => t('menus.title'), 'url' => null],
        ], [
            'menuKey' => $menu, 'tree' => $tree, 'pagesJson' => $pages, 'sections' => $sections, 'errors' => $errors,
        ], $status);
    }

    private function menuParam(mixed $v): string
    {
        return is_string($v) && in_array($v, MenuItem::MENUS, true) ? $v : 'main';
    }

    /** Строка БД -> узел для JS. */
    private function exportRow(array $r): array
    {
        return [
            'type' => (string)$r['type'], 'page_id' => $r['page_id'] !== null ? (int)$r['page_id'] : null,
            'section_key' => $r['section_key'], 'url' => $r['url'], 'title_ru' => (string)$r['title_ru'], 'title_kk' => (string)$r['title_kk'],
            'target_blank' => (int)$r['target_blank'] === 1, 'label' => $this->defaultLabel($r['type'], $r['page_title'] ?? null, $r['section_key']),
            'hidden' => $r['type'] === 'page' && (int)($r['page_published'] ?? 1) !== 1, 'children' => [],
        ];
    }

    /** Проверенный узел -> узел для JS (при ошибке сохранения). */
    private function exportItem(array $it): array
    {
        $title = null;
        if ($it['page_id'] !== null) {
            $title = Page::find((int)$it['page_id'])['title'] ?? null;
        }
        $out = [
            'type' => $it['type'], 'page_id' => $it['page_id'], 'section_key' => $it['section_key'], 'url' => $it['url'],
            'title_ru' => $it['title_ru'], 'title_kk' => $it['title_kk'], 'target_blank' => $it['target_blank'] === 1,
            'label' => $this->defaultLabel($it['type'], $title, $it['section_key']), 'hidden' => false, 'children' => [],
        ];
        foreach ($it['children'] as $c) {
            $out['children'][] = $this->exportItem($c);
        }
        return $out;
    }

    private function defaultLabel(string $type, ?string $pageTitle, ?string $section): string
    {
        if ($type === 'page') {
            return (string)$pageTitle;
        }
        return $type === 'section' && $section !== null ? t('menus.section.' . $section) : '';
    }
}
