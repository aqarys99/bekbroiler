<?php
declare(strict_types=1);

// 1. Встроенный сервер php -S: статику отдаёт сам сервер
if (PHP_SAPI === 'cli-server') {
    $reqPath = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    if (str_contains($reqPath, "\0")) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    if (preg_match('~^/uploads/.*\.(php|phtml|phar)$~i', $reqPath)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }
    $real = realpath(__DIR__ . $reqPath);
    if ($real !== false && is_file($real) && str_starts_with($real, __DIR__ . DIRECTORY_SEPARATOR)
        && $real !== __FILE__) {
        return false;
    }
}

// 2. Ядро
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Installer;
use App\Core\Migrator;
use App\Core\Router;

ob_start();

// 3. Не установлено — на мастер установки
if (!Installer::isInstalled()) {
    redirect(url('/install.php'), 302);
}

// 4. Установлено: БД обязана существовать (пустую не создаём)
if (!is_file(\App\Core\Config::dbPath())) {
    throw new RuntimeException('Файл базы данных не найден, хотя установка отмечена как выполненная');
}

(new Migrator())->migrate();

$router = new Router();
(require ROOT . '/app/routes.php')($router);
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
ob_end_flush();
