<?php
declare(strict_types=1);

// Образец конфигурации. Рабочий config/config.php создаёт install.php.
return [
    'env'       => 'production',   // production | development | testing
    'debug'     => false,
    'base_path' => '',             // для сайта в подпапке: '/sadik' (без слеша в конце)
    'admin_path' => 'admin',
    'timezone'  => 'Asia/Almaty',
    'locale'    => 'ru',
    'db'        => [
        'driver' => 'sqlite',
        'path'   => 'storage/database.sqlite',
    ],
];
