<?php
declare(strict_types=1);

namespace App\Controllers\Testing;

use App\Core\Session;

/** Только APP_ENV=testing: состарить сессию для проверки таймаута. */
final class SessionController
{
    public function age(array $params = []): string
    {
        Session::start();
        $seconds = max(0, (int)($_GET['seconds'] ?? 0));
        if (isset($_SESSION['user_id'])) {
            $_SESSION['_last_activity'] = time() - $seconds;
        }
        http_response_code(204);
        return '';
    }
}
