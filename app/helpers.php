<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Session;

if (!function_exists('e')) {
    function e(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return \App\Models\Settings::get($key, $default);
    }
}

if (!function_exists('t')) {
    function t(string $key, array $replace = []): string
    {
        static $cache = [];
        $locale = preg_replace('/[^a-z]/', '', (string)config('locale', 'ru')) ?: 'ru';
        if (!isset($cache[$locale])) {
            $file = dirname(__DIR__) . '/app/lang/' . $locale . '.php';
            $cache[$locale] = is_file($file) ? (array)require $file : [];
        }
        if (!array_key_exists($key, $cache[$locale])) {
            if (config('debug', false)) {
                Logger::warning('Нет перевода', ['key' => $key]);
            }
            return $key;
        }
        $text = (string)$cache[$locale][$key];
        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':' . $name] = (string)$value;
        }
        // один проход, самое длинное совпадение первым: :page не затрагивает :pages
        return $pairs ? strtr($text, $pairs) : $text;
    }
}

if (!function_exists('url')) {
    function url(string $path = '/', array $query = []): string
    {
        $base = rtrim((string)config('base_path', ''), '/');
        $path = '/' . ltrim($path, '/');
        $qs = $query ? '?' . http_build_query($query) : '';
        return $base . $path . $qs;
    }
}

if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = dirname(__DIR__) . '/public/assets/' . $path;
        $v = is_file($file) ? '?v=' . filemtime($file) : '';
        return url('/assets/' . $path) . $v;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return url('/' . config('admin_path', 'admin') . $path);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        Session::start();
        if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    function csrf_verify(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }
        return hash_equals(csrf_token(), $token);
    }
}

if (!function_exists('abort')) {
    function abort(int $code, string $message = ''): never
    {
        throw new HttpException($code, $message);
    }
}

if (!function_exists('redirect')) {
    function redirect(string $url, int $code = 302): never
    {
        if (PHP_SAPI === 'cli') {
            // консоль (тесты): не завершаем процесс
            throw new \App\Core\RedirectException($url, $code);
        }
        if (!headers_sent()) {
            header('Location: ' . str_replace(["\r", "\n"], '', $url), true, $code);
        }
        exit;
    }
}

if (!function_exists('once')) {
    /** true только при первом вызове с этим ключом за запрос (например, подключить скрипт один раз). */
    function once(string $key): bool
    {
        static $seen = [];
        if (isset($seen[$key])) {
            return false;
        }
        $seen[$key] = true;
        return true;
    }
}

if (!function_exists('format_bytes')) {
    /** 0 Б, 312 КБ, 9,8 МБ, 1,2 ГБ (основание 1024). */
    function format_bytes(int $b): string
    {
        if ($b < 1024) {
            return max(0, $b) . ' Б';
        }
        $kb = (int)round($b / 1024);
        if ($kb < 1024) {
            return $kb . ' КБ';
        }
        $mb = $b / 1048576;
        if (round($mb, 1) < 1024) {
            return str_replace('.', ',', number_format($mb, 1, '.', '')) . ' МБ';
        }
        return str_replace('.', ',', number_format($b / 1073741824, 1, '.', '')) . ' ГБ';
    }
}

if (!function_exists('media_variants')) {
    /** @return array<string,array<string,mixed>> */
    function media_variants(array $media): array
    {
        $v = $media['variants'] ?? [];
        if (is_string($v)) {
            $v = json_decode($v, true);
        }
        return is_array($v) ? $v : [];
    }
}

if (!function_exists('media_url')) {
    /** URL варианта фото (size: thumb|medium|large, format: webp|jpg); '' если варианта нет. */
    function media_url(array $media, string $size = 'medium', string $format = 'webp'): string
    {
        $rel = media_variants($media)[$size][$format] ?? null;
        return is_string($rel) && $rel !== '' ? url('/uploads/' . ltrim($rel, '/')) : '';
    }
}

if (!function_exists('media_picture')) {
    /** <picture> с WebP-srcset и JPEG-фолбэком; null (файл удалён) — пустая строка. */
    function media_picture(?array $media, string $size = 'medium', array $attrs = []): string
    {
        if ($media === null) {
            return '';
        }
        $variants = media_variants($media);
        $main = $variants[$size] ?? null;
        if (!is_array($main) || empty($main['jpg'])) {
            return '';
        }
        $set = [];
        foreach (['thumb', 'medium', 'large'] as $s) {
            if (isset($variants[$s]['webp'], $variants[$s]['w'])) {
                $set[(int)$variants[$s]['w']] = media_url($media, $s, 'webp') . ' ' . (int)$variants[$s]['w'] . 'w';
            }
        }
        ksort($set);
        $alt = array_key_exists('alt', $attrs) ? (string)$attrs['alt'] : (string)($media['alt'] ?? '');
        unset($attrs['alt']);
        $img = ['src' => media_url($media, $size, 'jpg'), 'loading' => 'lazy'];
        if (isset($main['w'], $main['h'])) {
            $img['width'] = (int)$main['w'];
            $img['height'] = (int)$main['h'];
        }
        $sizes = null;
        if (isset($attrs['sizes'])) {
            $sizes = (string)$attrs['sizes'];
            unset($attrs['sizes']);
        }
        foreach ($attrs as $k => $v) {
            $k = (string)$k;
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_:-]*$/', $k) && !preg_match('/^(src|srcset|on)/i', $k)) {
                $img[$k] = (string)$v;
            }
        }
        $img['alt'] = $alt;
        $html = '<picture>';
        if ($set) {
            $html .= '<source type="image/webp" srcset="' . e(implode(', ', $set)) . '"'
                . ($sizes !== null ? ' sizes="' . e($sizes) . '"' : '') . '>';
        }
        $html .= '<img';
        foreach ($img as $k => $v) {
            $html .= ' ' . $k . '="' . e($v) . '"';
        }
        return $html . '></picture>';
    }
}

if (!function_exists('video_embed')) {
    /**
     * HTML плеера видео (из модели Video): YouTube — обложка и iframe youtube-nocookie только по клику;
     * файл — <video preload="none" controls>. Пустая строка, если данных для показа нет.
     */
    function video_embed(array $video): string
    {
        $title = (string)($video['title'] ?? '');
        $poster = (string)($video['poster_url'] ?? \App\Models\Video::posterUrl($video));
        $wrap = 'position:relative;aspect-ratio:16/9;background:#000;border-radius:12px;overflow:hidden;max-width:100%';
        if (($video['source'] ?? '') === 'youtube' && \App\Core\YouTube::validId((string)($video['youtube_id'] ?? ''))) {
            $id = (string)$video['youtube_id'];
            $html = '<div class="video-embed video-embed--yt" style="' . $wrap . '">'
                . '<button type="button" class="video-embed__play" data-video-yt="' . e($id) . '" data-title="' . e($title) . '" aria-label="' . e(t('videos.play', ['title' => $title])) . '"'
                . ' style="all:unset;cursor:pointer;display:block;width:100%;height:100%;position:relative">'
                . ($poster !== '' ? '<img src="' . e($poster) . '" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block">' : '')
                . '<span aria-hidden="true" style="position:absolute;inset:0;margin:auto;width:68px;height:48px;border-radius:12px;background:rgba(0,0,0,.7);color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px">&#9654;</span>'
                . '</button></div>';
            if (once('video-embed-script')) {
                $html .= '<script>document.addEventListener("click",function(e){var b=e.target.closest("[data-video-yt]");if(!b){return;}'
                    . 'var f=document.createElement("iframe");f.src="https://www.youtube-nocookie.com/embed/"+b.getAttribute("data-video-yt")+"?autoplay=1&rel=0";'
                    . 'f.title=b.getAttribute("data-title")||"";f.allow="autoplay; encrypted-media; picture-in-picture";f.allowFullscreen=true;'
                    . 'f.style.cssText="position:absolute;inset:0;width:100%;height:100%;border:0";b.replaceWith(f);});</script>';
            }
            return $html;
        }
        $src = (string)($video['file_url'] ?? '');
        if (($video['source'] ?? '') === 'upload' && $src !== '') {
            return '<video class="video-embed video-embed--file" preload="none" controls playsinline src="' . e($src) . '"'
                . ($poster !== '' ? ' poster="' . e($poster) . '"' : '') . ' aria-label="' . e($title) . '" style="width:100%;max-width:100%;border-radius:12px;background:#000"></video>';
        }
        return '';
    }
}
