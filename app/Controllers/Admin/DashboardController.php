<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

final class DashboardController extends BaseController
{
    public function index(array $params = []): string
    {
        return $this->page('dashboard/index', t('menu.dashboard'), [['label' => t('menu.dashboard'), 'url' => null]]);
    }
}
