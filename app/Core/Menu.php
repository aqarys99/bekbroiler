<?php
declare(strict_types=1);

namespace App\Core;

final class Menu
{
    /**
     * Готовые группы меню для роли: подписи переведены, url полный, active посчитан.
     * @return array<int,array{label:string,items:array<int,array{key:string,label:string,url:string,icon:string,testid:string,active:bool}>}>
     */
    public static function build(array $user, ?string $path = null, ?array $definition = null): array
    {
        $definition ??= (array)require ROOT . '/app/admin_menu.php';
        $rel = self::relativePath($path);
        $out = [];
        foreach ($definition as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if ($item['role'] === 'owner' && ($user['role'] ?? '') !== 'owner') {
                    continue;
                }
                $p = (string)$item['url'];
                $active = $p === '' ? ($rel === '' || $rel === '/') : ($rel === $p || str_starts_with($rel, $p . '/'));
                $items[] = [
                    'key'    => $item['key'],
                    'label'  => t($item['label']),
                    'url'    => admin_url($p),
                    'icon'   => $item['icon'],
                    'testid' => $item['testid'],
                    'active' => $active,
                ];
            }
            if ($items) {
                $out[] = ['label' => t($group['label']), 'items' => $items];
            }
        }
        return $out;
    }

    /** Путь запроса относительно админки: '' для главной, '/users/5/edit' и т. д. */
    private static function relativePath(?string $path): string
    {
        $path ??= (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $prefix = admin_url();
        if ($path === $prefix) {
            return '';
        }
        return str_starts_with($path, $prefix . '/') ? rtrim(substr($path, strlen($prefix)), '/') : '';
    }
}
