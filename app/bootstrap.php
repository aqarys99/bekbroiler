<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\ErrorHandler;
use App\Core\Installer;

defined('ROOT') || define('ROOT', dirname(__DIR__));

date_default_timezone_set('UTC');

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/helpers.php';

ErrorHandler::register();
Config::load();

if (!Installer::isInstalled()) {
    // Сайт в подпапке: определяем base_path по адресу скрипта
    Config::set(['base_path' => Config::detectBasePath()]);
}
