<?php
declare(strict_types=1);

namespace App\Site;

use App\Models\MenuItem;

/** Меню сайта из menu_items: скрытые страницы и пустые разделы не показываются, подписи по языку, активный пункт. */
final class SiteMenu
{
    /**
     * @param array<string,bool> $flags Sections::flags()
     * @return array<int,array{label:string,url:string,key:string,blank:bool,group:bool,active:bool,children:array}>
     */
    public static function build(string $menu, array $flags, string $activeKey): array
    {
        $out = [];
        foreach (MenuItem::tree($menu) as $top) {
            $children = [];
            foreach ($top['children'] as $sub) {
                if (($x = self::resolve($sub, $flags)) !== null) {
                    $children[] = $x;
                }
            }
            $x = self::resolve($top, $flags);
            if ($x === null || ($top['type'] === 'group' && $children === [])) {
                continue;
            }
            $x['children'] = $children;
            $x['group'] = $top['type'] === 'group' || ($children !== [] && $x['url'] === '');
            $x['active'] = self::isActive($x, $activeKey);
            foreach ($x['children'] as &$c) {
                $c['active'] = self::isActive($c, $activeKey);
            }
            unset($c);
            $x['active'] = $x['active'] || in_array(true, array_column($x['children'], 'active'), true);
            $out[] = $x;
        }
        return $out;
    }

    private static function isActive(array $x, string $activeKey): bool
    {
        return $x['key'] !== '' && $x['key'] === $activeKey;
    }

    private static function resolve(array $r, array $flags): ?array
    {
        $item = ['label' => '', 'url' => '', 'key' => '', 'blank' => (int)$r['target_blank'] === 1, 'group' => false, 'active' => false, 'children' => []];
        $ru = trim((string)$r['title_ru']);
        $kk = trim((string)$r['title_kk']);
        switch ($r['type']) {
            case 'page':
                if ($r['page_id'] === null || (int)($r['page_published'] ?? 0) !== 1) {
                    return null;
                }
                $item['label'] = self::label($ru, $kk, (string)$r['page_title'], (string)$r['page_title_kk']);
                $item['url'] = surl('/' . $r['page_slug']);
                $item['key'] = (string)$r['page_slug'];
                return $item;
            case 'section':
                $def = MenuItem::SECTIONS[(string)$r['section_key']] ?? null;
                if ($def === null || ($def[3] !== null && empty($flags[$def[3]]))) {
                    return null;
                }
                $name = tt('nav.' . $def[2]);
                $item['label'] = self::label($ru, $kk, $name, $name);
                $item['url'] = surl($def[0]);
                $item['key'] = $def[1];
                return $item;
            case 'link':
                $url = (string)$r['url'];
                $item['label'] = self::label($ru, $kk, '', '');
                $item['url'] = str_starts_with($url, '/') ? url($url) : $url;
                return $item;
            default:
                $item['label'] = self::label($ru, $kk, '', '');
                return $item;
        }
    }

    /** Подпись: своя (рус./каз.) или название по умолчанию; казахский с откатом на русский без пометки «нет перевода». */
    private static function label(string $ru, string $kk, string $defRu, string $defKk): string
    {
        if (!Ctx::isKk()) {
            return $ru !== '' ? $ru : $defRu;
        }
        foreach ([$kk, $defKk, $defRu, $ru] as $c) {
            if ($c !== '') {
                return $c;
            }
        }
        return '';
    }
}
