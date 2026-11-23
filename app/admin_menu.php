<?php
declare(strict_types=1);

// Меню админки. Каждая фича добавляет свой пункт сюда (I-23).
// url — путь относительно админки ('' — главная); role — минимальная роль (editor | owner).
return [
    [
        'label' => 'menu.group.content',
        'items' => [
            ['key' => 'dashboard', 'label' => 'menu.dashboard', 'url' => '', 'icon' => 'home', 'role' => 'editor', 'testid' => 'nav-dashboard'],
            ['key' => 'news', 'label' => 'menu.news', 'url' => '/news', 'icon' => 'news', 'role' => 'editor', 'testid' => 'nav-news'],
            ['key' => 'media', 'label' => 'menu.media', 'url' => '/media', 'icon' => 'image', 'role' => 'editor', 'testid' => 'nav-media'],
        ],
    ],
    [
        'label' => 'menu.group.system',
        'items' => [
            ['key' => 'users', 'label' => 'menu.users', 'url' => '/users', 'icon' => 'key', 'role' => 'owner', 'testid' => 'nav-users'],
        ],
    ],
];
