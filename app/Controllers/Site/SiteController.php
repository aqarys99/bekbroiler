<?php
declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Clock;
use App\Core\HttpException;
use App\Core\SiteCache;
use App\Models\Achievement;
use App\Models\Album;
use App\Models\Document;
use App\Models\Group;
use App\Models\Media;
use App\Models\News;
use App\Models\Page;
use App\Models\Review;
use App\Models\Staff;
use App\Models\Video;
use App\Site\Ctx;
use App\Site\Sections;
use App\Site\Theme;

/** Страницы публичного сайта. Один экземпляр на язык (маршруты регистрируются для / и /kk). */
final class SiteController
{
    public const NEWS_PER_PAGE = 9;
    public const PAGES = ['o-nas', 'programma', 'rezhim', 'pitanie', 'usloviya'];

    public function __construct(private readonly string $lang)
    {
    }

    /** Общий вход: язык, кэш (I-13), заголовки. */
    public function dispatch(string $action, array $params = []): string
    {
        Ctx::init($this->lang);
        $path = $this->requestPath();
        if ($this->lang === 'kk' && !Ctx::kkEnabled()) {
            // казахская версия выключена в настройках (I-11) — на русскую страницу
            redirect(Ctx::url($path, [], 'ru'), 302);
        }
        $pageNo = $action === 'newsList' ? max(1, min(100000, (int)($_GET['page'] ?? 1))) : 1;
        $key = implode('|', [Ctx::origin(), $this->lang, $path, $pageNo, Ctx::$a11y ? 'a11y' : '-', Theme::name()]);
        $this->headers();
        $cached = SiteCache::get($key);
        if ($cached !== null) {
            return $cached;
        }
        $html = $this->$action($params);
        SiteCache::put($key, $html);
        return $html;
    }

    /** Страница 404 в оформлении темы (вызывает ErrorHandler для адресов вне админки). */
    public static function notFound(): string
    {
        [$lang, $path] = Ctx::pathOf((string)($_SERVER['REQUEST_URI'] ?? '/'));
        $self = new self($lang === 'kk' && \App\Site\Ctx::kkEnabled() ? 'kk' : 'ru');
        Ctx::init($self->lang);
        return $self->page('404', [], ['key' => '404', 'path' => '/', 'title' => tt('404.title'), 'description' => '', 'noindex' => true]);
    }

    private function headers(): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Content-Language: ' . $this->lang);
        }
    }

    private function requestPath(): string
    {
        return Ctx::pathOf((string)($_SERVER['REQUEST_URI'] ?? '/'))[1];
    }

    // ---------------------------------------------------------------- страницы

    protected function home(array $p): string
    {
        $news = News::published(1, 3)['items'];
        $staff = Staff::published();
        $groups = Group::published();
        $hero = ($id = (int)setting('hero_media_id', 0)) > 0 ? Media::find($id) : null;
        $founded = (int)setting('founded_year', 0);
        $children = 0;
        $groupCount = 0;
        foreach ($groups as $g) {
            $children += (int)($g['children_count'] ?? 0);
            $groupCount += (int)($g['groups_count'] ?? 1) ?: 1;
        }
        if ($children === 0) {
            $children = (int)setting('capacity', 0);
        }
        $years = $founded > 0 ? max(0, (int)Clock::nowUtc()->format('Y') - $founded) : 0;
        $stats = array_filter([
            ['n' => $years, 'label' => tt('stats.years')],
            ['n' => $children, 'label' => tt('stats.children')],
            ['n' => $groupCount, 'label' => tt('stats.groups')],
            ['n' => count($staff), 'label' => tt('stats.staff')],
        ], static fn(array $s): bool => $s['n'] > 0);
        $about = Page::published('o-nas');
        $ogImage = $hero !== null ? media_url($hero, 'large', 'jpg') : '';
        return $this->page('home', [
            'hero' => $hero, 'stats' => $stats, 'news' => $news,
            'staff' => array_slice($staff, 0, 4), 'groups' => array_slice($groups, 0, 4),
            'about' => $about, 'aboutBody' => $about !== null ? News::renderBody(loc($about, 'body'), 'large', tt('news.video_missing')) : '',
            'info' => $this->info(),
        ], [
            'key' => 'home', 'path' => '/', 'title' => '',
            'description' => tt('home.description', ['name' => $this->siteName()]),
            'og_image' => $ogImage,
        ]);
    }

    protected function staticPage(array $p): string
    {
        $slug = (string)($p['slug'] ?? '');
        $row = in_array($slug, self::PAGES, true) ? Page::published($slug) : null;
        if ($row === null) {
            throw new HttpException(404);
        }
        $title = loc($row, 'title');
        $body = News::renderBody(loc($row, 'body'), 'large', tt('news.video_missing'));
        return $this->page('page', ['row' => $row, 'heading' => $title, 'body' => $body], [
            'key' => $slug, 'path' => '/' . $slug, 'title' => $title,
            'description' => $this->excerpt($body) ?: tt('home.description', ['name' => $this->siteName()]),
        ]);
    }

    protected function staff(array $p): string
    {
        return $this->page('staff', ['staff' => Staff::published()], [
            'key' => 'pedagogi', 'path' => '/pedagogi', 'title' => tt('nav.staff'), 'description' => tt('staff.lead'),
        ]);
    }

    protected function groups(array $p): string
    {
        return $this->page('groups', ['groups' => Group::published()], [
            'key' => 'gruppy', 'path' => '/gruppy', 'title' => tt('nav.groups'), 'description' => tt('groups.lead'),
        ]);
    }

    protected function newsList(array $p): string
    {
        $pageNo = max(1, min(100000, (int)($_GET['page'] ?? 1)));
        $res = News::published($pageNo, self::NEWS_PER_PAGE);
        return $this->page('news-list', ['news' => $res], [
            'key' => 'novosti', 'path' => '/novosti', 'query' => $res['page'] > 1 ? ['page' => $res['page']] : [],
            'title' => tt('nav.news') . ($res['page'] > 1 ? ' — ' . tt('news.page', ['n' => $res['page']]) : ''),
            'description' => tt('news.lead'),
        ]);
    }

    protected function newsItem(array $p): string
    {
        $row = News::findPublishedBySlug((string)($p['slug'] ?? ''));
        if ($row === null) {
            throw new HttpException(404);
        }
        $kk = Ctx::isKk() && News::isTranslated($row);
        if (Ctx::isKk() && !$kk) {
            Ctx::$missing = true;
        }
        $title = $kk ? (string)$row['kk_title'] : (string)$row['title'];
        $excerpt = $kk && trim((string)$row['kk_excerpt']) !== '' ? (string)$row['kk_excerpt'] : (string)$row['excerpt'];
        $bodyHtml = $kk ? (string)$row['kk_body'] : (string)$row['body'];
        $body = News::renderBody($bodyHtml, 'large', tt('news.video_missing'));
        $cover = is_array($row['cover']) ? $row['cover'] : null;
        return $this->page('news-item', [
            'row' => $row, 'heading' => $title, 'body' => $body, 'cover' => $cover, 'photos' => $row['photos'],
            'date' => $this->date((string)$row['published_at']),
        ], [
            'key' => 'novosti', 'path' => '/novosti/' . $row['slug'], 'title' => $title, 'og_type' => 'article',
            'description' => $excerpt !== '' ? $this->cut($excerpt) : $this->excerpt($body),
            'og_image' => $cover !== null ? media_url($cover, 'large', 'jpg') : '',
        ]);
    }

    protected function gallery(array $p): string
    {
        return $this->page('gallery', ['albums' => Album::published()], [
            'key' => 'fotogalereya', 'path' => '/fotogalereya', 'title' => tt('nav.gallery'), 'description' => tt('gallery.lead'),
        ]);
    }

    protected function album(array $p): string
    {
        $a = Album::publishedBySlug((string)($p['slug'] ?? ''));
        if ($a === null) {
            throw new HttpException(404);
        }
        $title = loc($a, 'title');
        return $this->page('album', ['album' => $a, 'heading' => $title, 'text' => loc($a, 'description')], [
            'key' => 'fotogalereya', 'path' => '/fotogalereya/' . $a['slug'], 'title' => $title,
            'description' => $this->cut(loc($a, 'description')) ?: tt('gallery.lead'),
            'og_image' => is_array($a['cover']) ? media_url($a['cover'], 'large', 'jpg') : '',
        ]);
    }

    protected function videos(array $p): string
    {
        $items = [];
        foreach (Video::published() as $v) {
            $title = loc($v, 'title');
            $items[] = ['title' => $title, 'html' => video_embed(['title' => $title] + $v)];
        }
        return $this->page('videos', ['items' => $items], [
            'key' => 'video', 'path' => '/video', 'title' => tt('nav.videos'), 'description' => tt('videos.lead'),
        ]);
    }

    protected function documents(array $p): string
    {
        return $this->page('documents', ['groups' => Document::publishedByCategory()], [
            'key' => 'dokumenty', 'path' => '/dokumenty', 'title' => tt('nav.documents'), 'description' => tt('documents.lead'),
        ]);
    }

    protected function achievements(array $p): string
    {
        return $this->page('achievements', ['items' => Achievement::published()], [
            'key' => 'dostizheniya', 'path' => '/dostizheniya', 'title' => tt('nav.achievements'), 'description' => tt('achievements.lead'),
        ]);
    }

    protected function reviews(array $p): string
    {
        $items = Review::published();
        if ($items === []) {
            throw new HttpException(404);
        }
        return $this->page('reviews', ['items' => $items], [
            'key' => 'otzyvy', 'path' => '/otzyvy', 'title' => tt('nav.reviews'), 'description' => tt('reviews.lead'),
        ]);
    }

    protected function contacts(array $p): string
    {
        $map = (string)setting('map_src', '');
        return $this->page('contacts', [
            'address' => $this->settingLoc('address'),
            'phones' => array_values(array_filter((array)setting('phones', []), 'is_string')),
            'email' => (string)setting('email', ''),
            'whatsapp' => (string)setting('whatsapp', ''),
            'hours' => (string)setting('work_hours', ''),
            'socials' => $this->socials(),
            'map' => preg_match('~^https://~i', $map) ? $map : '',
        ], [
            'key' => 'kontakty', 'path' => '/kontakty', 'title' => tt('nav.contacts'),
            'description' => tt('contacts.lead', ['name' => $this->siteName()]),
        ]);
    }

    // ---------------------------------------------------------------- сборка страницы

    /** Рендерит шаблон темы с общими данными и мета-тегами. */
    private function page(string $tpl, array $data, array $meta): string
    {
        $name = $this->siteName();
        $title = (string)($meta['title'] ?? '');
        $path = (string)($meta['path'] ?? '/');
        $query = (array)($meta['query'] ?? []);
        $origin = Ctx::origin();
        $alt = [];
        if (Ctx::kkEnabled()) {
            foreach (['ru', 'kk'] as $l) {
                $alt[$l] = $origin . Ctx::url($path, $query, $l);
            }
        }
        $logoId = (int)setting('logo_media_id', 0);
        $site = [
            'name' => $name,
            'logo' => $logoId > 0 ? Media::find($logoId) : null,
            'flags' => Sections::flags(),
            'pages' => Sections::pages(),
            'contacts' => ['phones' => (array)setting('phones', []), 'email' => (string)setting('email', ''), 'address' => $this->settingLoc('address')],
            'kk' => Ctx::kkEnabled(),
            'a11y_enabled' => (bool)setting('a11y_enabled', true),
            'switch' => [
                'ru' => Ctx::url($path, $query, 'ru'),
                'kk' => Ctx::url($path, $query, 'kk'),
            ],
            'year' => Clock::nowUtc()->format('Y'),
        ];
        $m = [
            'title' => $title === '' ? $name : $title . ' — ' . $name,
            'description' => (string)($meta['description'] ?? ''),
            'canonical' => $origin . Ctx::url($path, $query),
            'alternates' => $alt,
            'og_type' => (string)($meta['og_type'] ?? 'website'),
            'og_image' => ($img = (string)($meta['og_image'] ?? '')) !== '' ? $origin . $img : '',
            'active' => (string)($meta['key'] ?? ''),
            'noindex' => !empty($meta['noindex']),
        ];
        // Ctx::$missing выставляется при разборе шаблона страницы и читается layout'ом (он рендерится после)
        return Theme::render($tpl, $data + ['site' => $site, 'meta' => $m]);
    }

    // ---------------------------------------------------------------- вспомогательное

    private function siteName(): string
    {
        $n = trim((string)setting('site_name', ''));
        return $n !== '' ? $n : (string)t('app.name');
    }

    private function settingLoc(string $key): string
    {
        $row = [$key => (string)setting($key, ''), $key . '_kk' => (string)setting($key . '_kk', '')];
        return Ctx::pick($row, $key);
    }

    /** @return array<int,array{label:string,value:string}> строки блока «Общая информация» */
    private function info(): array
    {
        $full = Ctx::pick(['a' => (string)setting('full_name', ''), 'a_kk' => (string)setting('full_name_kk', '')], 'a', null, false);
        $dir = Ctx::pick(['a' => (string)setting('director', ''), 'a_kk' => (string)setting('director_kk', '')], 'a', null, false);
        $langs = array_map(static fn($l): string => tt('lang.' . $l), array_filter((array)setting('teach_langs', []), 'is_string'));
        $num = trim((string)setting('license_number', ''));
        $ld = (string)setting('license_date', '');
        $license = $num === '' ? '' : ($ld !== '' && ($ts = strtotime($ld)) !== false
            ? tt('info.license_fmt', ['n' => $num, 'd' => date('d.m.Y', $ts)])
            : '№ ' . $num);
        $rows = [
            [tt('info.full_name'), $full],
            [tt('info.director'), $dir],
            [tt('info.founded'), (string)setting('founded_year', '')],
            [tt('info.capacity'), (int)setting('capacity', 0) > 0 ? tt('info.places', ['n' => (int)setting('capacity')]) : ''],
            [tt('info.languages'), implode(', ', $langs)],
            [tt('info.hours'), (string)setting('work_hours', '')],
            [tt('info.license'), $license],
        ];
        $out = [];
        foreach ($rows as [$l, $v]) {
            if (trim($v) !== '') {
                $out[] = ['label' => $l, 'value' => $v];
            }
        }
        return $out;
    }

    /** @return array<int,array{key:string,url:string}> только http(s)-адреса */
    private function socials(): array
    {
        $out = [];
        foreach (['instagram', 'facebook', 'telegram', 'twogis'] as $k) {
            $u = (string)setting($k, '');
            if (preg_match('~^https?://~i', $u)) {
                $out[] = ['key' => $k, 'url' => $u];
            }
        }
        return $out;
    }

    private function date(string $utc): string
    {
        return $utc === '' ? '' : Clock::toLocal($utc)->format('d.m.Y');
    }

    private function excerpt(string $html): string
    {
        return $this->cut(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function cut(string $text): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > 160 ? rtrim(mb_substr($text, 0, 157)) . '…' : $text;
    }
}
