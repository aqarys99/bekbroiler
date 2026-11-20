<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\View;

final class StubController
{
    public function index(array $params = []): string
    {
        $raw = Database::fetchValue("SELECT value FROM settings WHERE key = 'site_name'");
        $name = $raw !== null ? json_decode((string)$raw, true) : null;
        return View::render('stub', [
            'title'    => t('stub.title'),
            'siteName' => is_string($name) ? $name : t('app.name'),
        ]);
    }
}
