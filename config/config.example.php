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
    'media' => [
        'max_upload_mb'   => 20,       // действующий лимит = min(это, upload_max_filesize, post_max_size)
        'max_pixels'      => 40000000, // ширина × высота до декодирования
        'client_max_side' => 2560,     // длинная сторона после сжатия в браузере
        'client_quality'  => 0.85,     // качество JPEG в браузере
        'webp_quality'    => 80,
        'jpeg_quality'    => 82,
        'per_page'        => 48,
    ],
    'news' => [
        'per_page'   => 20,
        'max_photos' => 50,
    ],
    'disk_quota_mb' => null,       // объём тарифа; null — disk_total_space()
];
