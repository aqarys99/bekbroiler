<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\View;

final class StubController
{
    public function index(array $params = []): string
    {
        $name = setting('site_name');
        return View::render('stub', [
            'title'    => t('stub.title'),
            'siteName' => is_string($name) && $name !== '' ? $name : t('app.name'),
        ]);
    }
}
