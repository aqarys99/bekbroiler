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
            ['key' => 'documents', 'label' => 'menu.documents', 'url' => '/documents', 'icon' => 'doc', 'role' => 'editor', 'testid' => 'nav-documents'],
            ['key' => 'gallery', 'label' => 'menu.gallery', 'url' => '/gallery', 'icon' => 'image', 'role' => 'editor', 'testid' => 'nav-gallery'],
            ['key' => 'videos', 'label' => 'menu.videos', 'url' => '/videos', 'icon' => 'video', 'role' => 'editor', 'testid' => 'nav-videos'],
            ['key' => 'media', 'label' => 'menu.media', 'url' => '/media', 'icon' => 'image', 'role' => 'editor', 'testid' => 'nav-media'],
        ],
    ],
    [
        'label' => 'menu.group.sections',
        'items' => [
            ['key' => 'pages', 'label' => 'menu.pages', 'url' => '/pages', 'icon' => 'page', 'role' => 'editor', 'testid' => 'nav-pages'],
            ['key' => 'staff', 'label' => 'menu.staff', 'url' => '/staff', 'icon' => 'people', 'role' => 'editor', 'testid' => 'nav-staff'],
            ['key' => 'groups', 'label' => 'menu.groups', 'url' => '/groups', 'icon' => 'people', 'role' => 'editor', 'testid' => 'nav-groups'],
            ['key' => 'achievements', 'label' => 'menu.achievements', 'url' => '/achievements', 'icon' => 'star', 'role' => 'editor', 'testid' => 'nav-achievements'],
            ['key' => 'reviews', 'label' => 'menu.reviews', 'url' => '/reviews', 'icon' => 'chat', 'role' => 'editor', 'testid' => 'nav-reviews'],
        ],
    ],
    [
        'label' => 'menu.group.system',
        'items' => [
            ['key' => 'settings', 'label' => 'menu.settings', 'url' => '/settings', 'icon' => 'gear', 'role' => 'owner', 'testid' => 'nav-settings'],
            ['key' => 'users', 'label' => 'menu.users', 'url' => '/users', 'icon' => 'key', 'role' => 'owner', 'testid' => 'nav-users'],
            ['key' => 'backups', 'label' => 'menu.backups', 'url' => '/backups', 'icon' => 'save', 'role' => 'owner', 'testid' => 'nav-backups'],
            ['key' => 'audit', 'label' => 'menu.audit', 'url' => '/audit', 'icon' => 'log', 'role' => 'owner', 'testid' => 'nav-audit'],
        ],
    ],
];
