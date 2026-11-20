<?php
declare(strict_types=1);

use App\Controllers\StubController;
use App\Controllers\Testing\ErrorController;
use App\Core\Router;

return static function (Router $r): void {
    $r->get('/', [StubController::class, 'index']);
    $r->get('/' . trim((string)config('admin_path', 'admin'), '/'), [StubController::class, 'index']);

    if (getenv('APP_ENV') === 'testing') {
        $r->get('/__test/error', [ErrorController::class, 'throw']);
    }
};
