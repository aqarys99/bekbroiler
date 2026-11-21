<?php
declare(strict_types=1);

use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ProfileController;
use App\Controllers\Admin\UsersController;
use App\Controllers\StubController;
use App\Controllers\Testing\ErrorController;
use App\Controllers\Testing\SessionController;
use App\Core\Guard;
use App\Core\Router;

return static function (Router $r): void {
    $a = '/' . trim((string)config('admin_path', 'admin'), '/');

    $r->get('/', [StubController::class, 'index']);

    // Вход: единственные маршруты админки без Guard
    $r->get($a . '/login', [AuthController::class, 'showLogin']);
    $r->post($a . '/login', [AuthController::class, 'login']);

    $r->post($a . '/logout', Guard::wrap([AuthController::class, 'logout']));
    $r->get($a, Guard::wrap([DashboardController::class, 'index']));
    $r->get($a . '/profile', Guard::wrap([ProfileController::class, 'show']));
    $r->post($a . '/profile/password', Guard::wrap([ProfileController::class, 'changePassword']));

    $r->get($a . '/users', Guard::wrap([UsersController::class, 'index'], 'owner'));
    $r->get($a . '/users/new', Guard::wrap([UsersController::class, 'create'], 'owner'));
    $r->post($a . '/users', Guard::wrap([UsersController::class, 'store'], 'owner'));
    $r->get($a . '/users/{id:\d+}/edit', Guard::wrap([UsersController::class, 'edit'], 'owner'));
    $r->post($a . '/users/{id:\d+}', Guard::wrap([UsersController::class, 'update'], 'owner'));
    $r->post($a . '/users/{id:\d+}/block', Guard::wrap([UsersController::class, 'block'], 'owner'));
    $r->post($a . '/users/{id:\d+}/unblock', Guard::wrap([UsersController::class, 'unblock'], 'owner'));
    $r->get($a . '/users/{id:\d+}/password', Guard::wrap([UsersController::class, 'passwordForm'], 'owner'));
    $r->post($a . '/users/{id:\d+}/password', Guard::wrap([UsersController::class, 'resetPassword'], 'owner'));

    if (getenv('APP_ENV') === 'testing') {
        $r->get('/__test/error', [ErrorController::class, 'throw']);
        $r->get('/__test/session-age', [SessionController::class, 'age']);
    }
};
