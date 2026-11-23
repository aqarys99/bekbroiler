<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Controllers\InstallController;

ob_start();
\App\Core\SecurityHeaders::send(false);
$controller = new InstallController();
echo ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $controller->store() : $controller->show();
ob_end_flush();
