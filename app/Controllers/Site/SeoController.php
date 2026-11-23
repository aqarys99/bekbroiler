<?php
declare(strict_types=1);

namespace App\Controllers\Site;

use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use App\Site\Ctx;
use App\Site\Sections;
use App\Site\Theme;

/** sitemap.xml, robots.txt и ресурсы темы (CSS/JS/картинки) с заголовками кэша. */
final class SeoController
{
    public function robots(array $p = []): string
    {
        header('Content-Type: text/plain; charset=utf-8');
        $a = '/' . trim((string)config('admin_path', 'admin'), '/');
        return "User-agent: *\n"
            . 'Disallow: ' . url($a) . "/\n"
            . 'Disallow: ' . url($a) . "\n"
            . 'Disallow: ' . url('/install.php') . "\n"
            . "Allow: /\n\n"
            . 'Sitemap: ' . Ctx::origin() . url('/sitemap.xml') . "\n";
    }

    public function sitemap(array $p = []): string
    {
        header('Content-Type: application/xml; charset=utf-8');
        $origin = Ctx::origin();
        $kk = Ctx::kkEnabled();
        $flags = Sections::flags();
        $items = [['/', null]];
        foreach (Sections::pages() as $slug => $row) {
            $items[] = ['/' . $slug, substr((string)$row['updated_at'], 0, 10)];
        }
        $map = ['staff' => '/pedagogi', 'groups' => '/gruppy', 'news' => '/novosti', 'gallery' => '/fotogalereya',
            'videos' => '/video', 'documents' => '/dokumenty', 'achievements' => '/dostizheniya', 'reviews' => '/otzyvy'];
        foreach ($map as $k => $path) {
            if ($flags[$k]) {
                $items[] = [$path, null];
            }
        }
        $items[] = ['/kontakty', null];
        $now = Clock::nowUtc()->format(Clock::FORMAT);
        foreach (Database::fetchAll("SELECT slug, COALESCE(updated_at, published_at) AS d FROM news WHERE status = 'published' AND published_at <= ? ORDER BY published_at DESC LIMIT 5000", [$now]) as $r) {
            $items[] = ['/novosti/' . $r['slug'], substr((string)$r['d'], 0, 10)];
        }
        foreach (Database::fetchAll('SELECT slug, updated_at FROM albums WHERE is_published = 1 ORDER BY id DESC LIMIT 5000') as $r) {
            $items[] = ['/fotogalereya/' . $r['slug'], substr((string)$r['updated_at'], 0, 10)];
        }
        $x = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        foreach ($items as [$path, $mod]) {
            foreach ($kk ? ['ru', 'kk'] : ['ru'] as $lang) {
                $out .= '<url><loc>' . $x($origin . Ctx::url($path, [], $lang)) . '</loc>';
                if ($mod !== null && $mod !== '') {
                    $out .= '<lastmod>' . $x($mod) . '</lastmod>';
                }
                if ($kk) {
                    foreach (['ru', 'kk'] as $l) {
                        $out .= '<xhtml:link rel="alternate" hreflang="' . $l . '" href="' . $x($origin . Ctx::url($path, [], $l)) . '"/>';
                    }
                }
                $out .= "</url>\n";
            }
        }
        return $out . '</urlset>' . "\n";
    }

    /** /theme/{тема}/{файл}: только файлы из themes/<тема>/assets, белый список расширений. */
    public function asset(array $p = []): string
    {
        $a = Theme::assetPath((string)($p['theme'] ?? ''), (string)($p['file'] ?? ''));
        if ($a === null) {
            throw new HttpException(404);
        }
        [$path, $mime] = $a;
        $mtime = (int)filemtime($path);
        $etag = '"' . dechex($mtime) . '-' . dechex((int)filesize($path)) . '"';
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            return '';
        }
        return (string)file_get_contents($path);
    }
}
