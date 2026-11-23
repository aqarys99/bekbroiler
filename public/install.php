<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\InstallController;

ob_start();
if (is_string($_GET['lang'] ?? null) && \App\Core\Locale::valid($_GET['lang']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    \App\Core\Locale::remember($_GET['lang']);
    header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? 'install.php'), '?'), true, 302);
    exit;
}
\App\Core\SecurityHeaders::send(false);
$controller = new InstallController();
echo ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $controller->store() : $controller->show();
ob_end_flush();
