<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Menu;
use App\Core\View;

abstract class BaseController
{
    protected function siteName(): string
    {
        try {
            $raw = Database::fetchValue("SELECT value FROM settings WHERE key = 'site_name'");
        } catch (\Throwable) {
            $raw = null;
        }
        $name = $raw !== null ? json_decode((string)$raw, true) : null;
        return is_string($name) && $name !== '' ? $name : t('app.name');
    }

    /** @return array<string,mixed> */
    protected function currentUser(): array
    {
        $u = Auth::user();
        if ($u === null) {
            abort(403);
        }
        return $u;
    }

    /**
     * Страница в макете админки.
     * @param array<int,array{label:string,url:?string}> $breadcrumbs
     */
    protected function page(string $template, string $sectionTitle, array $breadcrumbs, array $data = [], int $status = 200): string
    {
        $user = $this->currentUser();
        $siteName = $this->siteName();
        http_response_code($status);
        return View::render($template, $data + [
            'title'       => $sectionTitle . ' — ' . $siteName,
            'user'        => $user,
            'menu'        => Menu::build($user),
            'breadcrumbs' => $breadcrumbs,
            'flash'       => Flash::pull(),
            'siteName'    => $siteName,
            'errors'      => [],
            'old'         => [],
            'generalError' => null,
        ], 'layouts/admin');
    }

    protected function home(): array
    {
        return ['label' => t('menu.dashboard'), 'url' => admin_url()];
    }

    protected static function post(string $key): string
    {
        return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    }
}
