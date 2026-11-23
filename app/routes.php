<?php
declare(strict_types=1);

use App\Controllers\Admin\AchievementsController;
use App\Controllers\Admin\AuditController;
use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\BackupsController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\DocumentsController;
use App\Controllers\Admin\GalleryController;
use App\Controllers\Admin\GroupsController;
use App\Controllers\Admin\MediaController;
use App\Controllers\Admin\NewsController;
use App\Controllers\Admin\PagesController;
use App\Controllers\Admin\ProfileController;
use App\Controllers\Admin\ReviewsController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\StaffController;
use App\Controllers\Admin\UsersController;
use App\Controllers\Admin\VideosController;
use App\Controllers\Site\SeoController;
use App\Controllers\Site\SiteController;
use App\Controllers\Testing\ClockController;
use App\Controllers\Testing\ErrorController;
use App\Controllers\Testing\MediaPickerController;
use App\Controllers\Testing\MediaUsageController;
use App\Controllers\Testing\SessionController;
use App\Core\Guard;
use App\Core\Router;

return static function (Router $r): void {
    $a = '/' . trim((string)config('admin_path', 'admin'), '/');

    // Публичный сайт: каждая страница на / и на /kk (казахская версия, если включена в настройках)
    foreach (['ru', 'kk'] as $lang) {
        $pre = $lang === 'kk' ? '/kk' : '';
        $site = static fn(string $action, array $fixed = []): \Closure
            => static fn(array $p = []) => (new SiteController($lang))->dispatch($action, $fixed + $p);
        $r->get($lang === 'kk' ? '/kk' : '/', $site('home'));
        foreach (SiteController::PAGES as $slug) {
            $r->get($pre . '/' . $slug, $site('staticPage', ['slug' => $slug]));
        }
        $r->get($pre . '/pedagogi', $site('staff'));
        $r->get($pre . '/gruppy', $site('groups'));
        $r->get($pre . '/novosti', $site('newsList'));
        $r->get($pre . '/novosti/{slug:[A-Za-z0-9_-]+}', $site('newsItem'));
        $r->get($pre . '/fotogalereya', $site('gallery'));
        $r->get($pre . '/fotogalereya/{slug:[A-Za-z0-9_-]+}', $site('album'));
        $r->get($pre . '/video', $site('videos'));
        $r->get($pre . '/dokumenty', $site('documents'));
        $r->get($pre . '/dokumenty/{id:\d+}', $site('documentView'));
        $r->get($pre . '/dostizheniya', $site('achievements'));
        $r->get($pre . '/otzyvy', $site('reviews'));
        $r->get($pre . '/kontakty', $site('contacts'));
    }
    $r->get('/sitemap.xml', [SeoController::class, 'sitemap']);
    $r->get('/robots.txt', [SeoController::class, 'robots']);
    $r->get('/theme/{theme:[a-z0-9_-]+}/{file:[A-Za-z0-9_./-]+}', [SeoController::class, 'asset']);

    // Вход: единственные маршруты админки без Guard
    $r->get($a . '/login', [AuthController::class, 'showLogin']);
    $r->post($a . '/login', [AuthController::class, 'login']);

    $r->post($a . '/lang', [AuthController::class, 'setLang']);
    $r->post($a . '/logout', Guard::wrap([AuthController::class, 'logout']));
    $r->get($a, Guard::wrap([DashboardController::class, 'index']));
    $r->get($a . '/profile', Guard::wrap([ProfileController::class, 'show']));
    $r->post($a . '/profile/password', Guard::wrap([ProfileController::class, 'changePassword']));

    $r->get($a . '/media', Guard::wrap([MediaController::class, 'index']));
    $r->post($a . '/media/upload', Guard::wrap([MediaController::class, 'upload']));
    $r->get($a . '/media/picker', Guard::wrap([MediaController::class, 'picker']));
    $r->get($a . '/media/{id:\d+}', Guard::wrap([MediaController::class, 'show']));
    $r->post($a . '/media/{id:\d+}', Guard::wrap([MediaController::class, 'update']));
    $r->post($a . '/media/{id:\d+}/delete', Guard::wrap([MediaController::class, 'delete']));

    $r->get($a . '/news', Guard::wrap([NewsController::class, 'index']));
    $r->get($a . '/news/new', Guard::wrap([NewsController::class, 'create']));
    $r->post($a . '/news', Guard::wrap([NewsController::class, 'store']));
    $r->post($a . '/news/autosave', Guard::wrap([NewsController::class, 'autosave']));
    $r->post($a . '/news/preview', Guard::wrap([NewsController::class, 'previewDraft']));
    $r->get($a . '/news/slug', Guard::wrap([NewsController::class, 'slug']));
    $r->get($a . '/news/{id:\d+}/edit', Guard::wrap([NewsController::class, 'edit']));
    $r->get($a . '/news/{id:\d+}/preview', Guard::wrap([NewsController::class, 'preview']));
    $r->post($a . '/news/{id:\d+}', Guard::wrap([NewsController::class, 'update']));
    $r->post($a . '/news/{id:\d+}/delete', Guard::wrap([NewsController::class, 'delete']));

    $r->get($a . '/gallery', Guard::wrap([GalleryController::class, 'index']));
    $r->get($a . '/gallery/new', Guard::wrap([GalleryController::class, 'create']));
    $r->post($a . '/gallery', Guard::wrap([GalleryController::class, 'store']));
    $r->get($a . '/gallery/{id:\d+}', Guard::wrap([GalleryController::class, 'show']));
    $r->post($a . '/gallery/{id:\d+}', Guard::wrap([GalleryController::class, 'update']));
    $r->post($a . '/gallery/{id:\d+}/publish', Guard::wrap([GalleryController::class, 'publish']));
    $r->post($a . '/gallery/{id:\d+}/delete', Guard::wrap([GalleryController::class, 'delete']));
    $r->post($a . '/gallery/{id:\d+}/photos', Guard::wrap([GalleryController::class, 'addPhotos']));
    $r->post($a . '/gallery/{id:\d+}/order', Guard::wrap([GalleryController::class, 'order']));
    $r->post($a . '/gallery/{id:\d+}/photo/{mid:\d+}/{act:move|cover|remove|caption}', Guard::wrap([GalleryController::class, 'photoAction']));

    $r->get($a . '/documents', Guard::wrap([DocumentsController::class, 'index']));
    $r->post($a . '/documents', Guard::wrap([DocumentsController::class, 'store']));
    $r->get($a . '/documents/{id:\d+}/edit', Guard::wrap([DocumentsController::class, 'edit']));
    $r->post($a . '/documents/{id:\d+}', Guard::wrap([DocumentsController::class, 'update']));
    $r->post($a . '/documents/{id:\d+}/replace', Guard::wrap([DocumentsController::class, 'replace']));
    $r->post($a . '/documents/{id:\d+}/delete', Guard::wrap([DocumentsController::class, 'delete']));
    $r->post($a . '/documents/{id:\d+}/move', Guard::wrap([DocumentsController::class, 'move']));

    $r->get($a . '/videos', Guard::wrap([VideosController::class, 'index']));
    $r->post($a . '/videos/youtube', Guard::wrap([VideosController::class, 'youtube']));
    $r->post($a . '/videos/upload/init', Guard::wrap([VideosController::class, 'uploadInit']));
    $r->post($a . '/videos/upload/status', Guard::wrap([VideosController::class, 'uploadStatus']));
    $r->post($a . '/videos/upload/chunk', Guard::wrap([VideosController::class, 'uploadChunk']));
    $r->post($a . '/videos/upload/finish', Guard::wrap([VideosController::class, 'uploadFinish']));
    $r->post($a . '/videos/{id:\d+}', Guard::wrap([VideosController::class, 'update']));
    $r->post($a . '/videos/{id:\d+}/publish', Guard::wrap([VideosController::class, 'publish']));
    $r->post($a . '/videos/{id:\d+}/move', Guard::wrap([VideosController::class, 'move']));
    $r->post($a . '/videos/{id:\d+}/delete', Guard::wrap([VideosController::class, 'delete']));

    $r->get($a . '/pages', Guard::wrap([PagesController::class, 'index']));
    $r->get($a . '/pages/{id:\d+}', Guard::wrap([PagesController::class, 'edit']));
    $r->post($a . '/pages/{id:\d+}', Guard::wrap([PagesController::class, 'update']));
    $r->post($a . '/pages/{id:\d+}/publish', Guard::wrap([PagesController::class, 'publish']));

    $r->get($a . '/staff', Guard::wrap([StaffController::class, 'index']));
    $r->get($a . '/staff/new', Guard::wrap([StaffController::class, 'create']));
    $r->post($a . '/staff', Guard::wrap([StaffController::class, 'store']));
    $r->get($a . '/staff/{id:\d+}', Guard::wrap([StaffController::class, 'edit']));
    $r->post($a . '/staff/{id:\d+}', Guard::wrap([StaffController::class, 'update']));
    $r->post($a . '/staff/{id:\d+}/publish', Guard::wrap([StaffController::class, 'publish']));
    $r->post($a . '/staff/{id:\d+}/move', Guard::wrap([StaffController::class, 'move']));
    $r->post($a . '/staff/{id:\d+}/delete', Guard::wrap([StaffController::class, 'delete']));

    $r->get($a . '/groups', Guard::wrap([GroupsController::class, 'index']));
    $r->get($a . '/groups/new', Guard::wrap([GroupsController::class, 'create']));
    $r->post($a . '/groups', Guard::wrap([GroupsController::class, 'store']));
    $r->get($a . '/groups/{id:\d+}', Guard::wrap([GroupsController::class, 'edit']));
    $r->post($a . '/groups/{id:\d+}', Guard::wrap([GroupsController::class, 'update']));
    $r->post($a . '/groups/{id:\d+}/publish', Guard::wrap([GroupsController::class, 'publish']));
    $r->post($a . '/groups/{id:\d+}/move', Guard::wrap([GroupsController::class, 'move']));
    $r->post($a . '/groups/{id:\d+}/delete', Guard::wrap([GroupsController::class, 'delete']));

    $r->get($a . '/achievements', Guard::wrap([AchievementsController::class, 'index']));
    $r->get($a . '/achievements/new', Guard::wrap([AchievementsController::class, 'create']));
    $r->post($a . '/achievements', Guard::wrap([AchievementsController::class, 'store']));
    $r->get($a . '/achievements/{id:\d+}', Guard::wrap([AchievementsController::class, 'edit']));
    $r->post($a . '/achievements/{id:\d+}', Guard::wrap([AchievementsController::class, 'update']));
    $r->post($a . '/achievements/{id:\d+}/publish', Guard::wrap([AchievementsController::class, 'publish']));
    $r->post($a . '/achievements/{id:\d+}/move', Guard::wrap([AchievementsController::class, 'move']));
    $r->post($a . '/achievements/{id:\d+}/delete', Guard::wrap([AchievementsController::class, 'delete']));

    $r->get($a . '/reviews', Guard::wrap([ReviewsController::class, 'index']));
    $r->get($a . '/reviews/new', Guard::wrap([ReviewsController::class, 'create']));
    $r->post($a . '/reviews', Guard::wrap([ReviewsController::class, 'store']));
    $r->get($a . '/reviews/{id:\d+}', Guard::wrap([ReviewsController::class, 'edit']));
    $r->post($a . '/reviews/{id:\d+}', Guard::wrap([ReviewsController::class, 'update']));
    $r->post($a . '/reviews/{id:\d+}/publish', Guard::wrap([ReviewsController::class, 'publish']));
    $r->post($a . '/reviews/{id:\d+}/move', Guard::wrap([ReviewsController::class, 'move']));
    $r->post($a . '/reviews/{id:\d+}/delete', Guard::wrap([ReviewsController::class, 'delete']));

    $r->get($a . '/settings', Guard::wrap([SettingsController::class, 'show'], 'owner'));
    $r->post($a . '/settings', Guard::wrap([SettingsController::class, 'save'], 'owner'));
    $r->get($a . '/users', Guard::wrap([UsersController::class, 'index'], 'owner'));
    $r->get($a . '/users/new', Guard::wrap([UsersController::class, 'create'], 'owner'));
    $r->post($a . '/users', Guard::wrap([UsersController::class, 'store'], 'owner'));
    $r->get($a . '/users/{id:\d+}/edit', Guard::wrap([UsersController::class, 'edit'], 'owner'));
    $r->post($a . '/users/{id:\d+}', Guard::wrap([UsersController::class, 'update'], 'owner'));
    $r->post($a . '/users/{id:\d+}/block', Guard::wrap([UsersController::class, 'block'], 'owner'));
    $r->post($a . '/users/{id:\d+}/unblock', Guard::wrap([UsersController::class, 'unblock'], 'owner'));
    $r->get($a . '/users/{id:\d+}/password', Guard::wrap([UsersController::class, 'passwordForm'], 'owner'));
    $r->post($a . '/users/{id:\d+}/password', Guard::wrap([UsersController::class, 'resetPassword'], 'owner'));

    $r->get($a . '/backups', Guard::wrap([BackupsController::class, 'index'], 'owner'));
    $r->post($a . '/backups/archive', Guard::wrap([BackupsController::class, 'archive'], 'owner'));
    $r->post($a . '/backups/create', Guard::wrap([BackupsController::class, 'create'], 'owner'));
    $r->get($a . '/backups/download/{name:[A-Za-z0-9._-]+}', Guard::wrap([BackupsController::class, 'download'], 'owner'));
    $r->post($a . '/backups/delete/{name:[A-Za-z0-9._-]+}', Guard::wrap([BackupsController::class, 'delete'], 'owner'));
    $r->get($a . '/audit', Guard::wrap([AuditController::class, 'index'], 'owner'));

    if (getenv('APP_ENV') === 'testing') {
        $r->get('/__test/error', [ErrorController::class, 'throw']);
        $r->get('/__test/session-age', [SessionController::class, 'age']);
        $r->get('/__test/media-picker', Guard::wrap([MediaPickerController::class, 'show']));
        $r->post('/__test/media-picker', Guard::wrap([MediaPickerController::class, 'echo']));
        $r->post('/__test/clock', [ClockController::class, 'set']);
        $r->post('/__test/media-usage', [MediaUsageController::class, 'set']);
    }
};
