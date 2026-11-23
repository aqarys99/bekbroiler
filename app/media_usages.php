<?php
declare(strict_types=1);

// Реестр потребителей медиа (I-25). Каждая фича (04, 05, 06, 09, 10) добавляет сюда свой класс,
// реализующий App\Media\UsageProvider.
$providers = [
    \App\Media\NewsUsageProvider::class,
];

if (getenv('APP_ENV') === 'testing') {
    $providers[] = \App\Media\TestUsageProvider::class;
}

return $providers;
