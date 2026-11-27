<?php
declare(strict_types=1);

namespace App\Site;

/**
 * Темы сайта: themes/<имя>/{theme.json, layout.php, *.php, assets/, lang/}.
 * Тема может наследовать другую ("extends" в theme.json): шаблоны, строки и CSS берутся по цепочке.
 */
final class Theme
{
    public const DEFAULT = 'solnyshko';
    /** значение настройки «theme» -> папка */
    public const MAP = ['sun' => 'solnyshko'];
    private const ASSET_EXT = [
        'css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'woff2' => 'font/woff2', 'ico' => 'image/x-icon',
    ];

    private static ?string $root = null;

    public static function setRoot(?string $root): void
    {
        self::$root = $root === null ? null : rtrim($root, '/');
    }

    public static function root(): string
    {
        return self::$root ?? ROOT . '/themes';
    }

    private static function valid(string $name): bool
    {
        return preg_match('/^[a-z0-9_-]{1,40}$/', $name) === 1 && is_file(self::root() . '/' . $name . '/theme.json');
    }

    /** Папка активной темы (по настройке; нет такой — тема по умолчанию). */
    public static function name(): string
    {
        $s = (string)setting('theme', 'sun');
        $name = self::MAP[$s] ?? $s;
        return self::valid($name) ? $name : self::DEFAULT;
    }

    /** @return array<string,mixed> */
    public static function meta(string $name): array
    {
        $d = json_decode((string)@file_get_contents(self::root() . '/' . $name . '/theme.json'), true);
        return is_array($d) ? $d : [];
    }

    /** Тема и её предки: [тема, родитель, ...]. @return string[] */
    public static function chain(?string $name = null): array
    {
        $name ??= self::name();
        $chain = [];
        while ($name !== null && self::valid($name) && !in_array($name, $chain, true) && count($chain) < 5) {
            $chain[] = $name;
            $p = self::meta($name)['extends'] ?? null;
            $name = is_string($p) ? $p : null;
        }
        return $chain ?: [self::DEFAULT];
    }

    public static function find(string $tpl): ?string
    {
        if (!preg_match('/^[a-z0-9_\/-]+$/', $tpl) || str_contains($tpl, '..')) {
            return null;
        }
        foreach (self::chain() as $t) {
            $f = self::root() . '/' . $t . '/' . $tpl . '.php';
            if (is_file($f)) {
                return $f;
            }
        }
        return null;
    }

    /** Шаблон страницы внутри layout. */
    public static function render(string $tpl, array $data = []): string
    {
        $content = self::capture($tpl, $data);
        return self::capture('layout', $data + ['content' => $content]);
    }

    private static function capture(string $tpl, array $data): string
    {
        $file = self::find($tpl);
        if ($file === null) {
            throw new \RuntimeException('Шаблон темы не найден: ' . $tpl);
        }
        return (static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string)ob_get_clean();
        })($file, $data);
    }

    /** Строка интерфейса темы (themes/<тема>/lang/<язык>.php по цепочке), нет — русская, нет — ключ. */
    public static function string(string $key, array $replace = []): string
    {
        static $cache = [];
        foreach ([Ctx::$lang, 'ru'] as $lang) {
            foreach (self::chain() as $t) {
                $k = $t . ':' . $lang;
                if (!isset($cache[$k])) {
                    $f = self::root() . '/' . $t . '/lang/' . $lang . '.php';
                    $cache[$k] = is_file($f) ? (array)require $f : [];
                }
                if (isset($cache[$k][$key])) {
                    $pairs = [];
                    foreach ($replace as $n => $v) {
                        $pairs[':' . $n] = (string)$v;
                    }
                    return $pairs ? strtr((string)$cache[$k][$key], $pairs) : (string)$cache[$k][$key];
                }
            }
        }
        return $key;
    }

    /** Файл ресурса темы по цепочке: [тема-владелец, путь] или null. */
    public static function assetPath(string $theme, string $file): ?array
    {
        if (!self::valid($theme) || $file === '' || str_contains($file, '..') || str_contains($file, "\0")
            || !preg_match('/^[A-Za-z0-9_.\/-]+$/', $file)) {
            return null;
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::ASSET_EXT[$ext])) {
            return null;
        }
        $dir = realpath(self::root() . '/' . $theme . '/assets');
        $path = $dir !== false ? realpath($dir . '/' . $file) : false;
        if ($dir === false || $path === false || !is_file($path) || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return [$path, self::ASSET_EXT[$ext]];
    }

    /** Адрес ресурса (ищется по цепочке тем) с версией по времени файла. */
    public static function assetUrl(string $file): string
    {
        foreach (self::chain() as $t) {
            $a = self::assetPath($t, $file);
            if ($a !== null) {
                return url('/theme/' . $t . '/' . $file, ['v' => filemtime($a[0])]);
            }
        }
        return '';
    }

    /** CSS всех тем цепочки: сначала предки, затем сама тема (она переопределяет). @return string[] */
    public static function cssUrls(): array
    {
        $out = [];
        foreach (array_reverse(self::chain()) as $t) {
            $a = self::assetPath($t, 'style.css');
            if ($a !== null) {
                $out[] = url('/theme/' . $t . '/style.css', ['v' => filemtime($a[0])]);
            }
        }
        return $out;
    }

    /** Переменные CSS из настроек (основной цвет). Цвет проверен регулярным выражением. */
    public static function cssVars(): string
    {
        $color = (string)setting('color', '');
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $color = (string)(self::meta(self::name())['colors']['primary'] ?? '');
        }
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return '';
        }
        [$r, $g, $b] = [hexdec(substr($color, 1, 2)), hexdec(substr($color, 3, 2)), hexdec(substr($color, 5, 2))];
        $lin = static fn(int $c): float => ($c / 255) <= 0.03928 ? ($c / 255) / 12.92 : ((($c / 255) + 0.055) / 1.055) ** 2.4;
        $lum = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
        // текст кнопки: тот из двух цветов, у которого выше контраст с основным (белый или тёмно-синий #1c1f2e)
        $on = (1.05 / ($lum + 0.05)) >= (($lum + 0.05) / 0.064) ? '#ffffff' : '#1c1f2e';
        return ':root{--primary:' . $color . ';--on-primary:' . $on . '}';
    }

    /** Список установленных тем (для проверки и тестов). @return string[] */
    public static function installed(): array
    {
        $out = [];
        foreach (glob(self::root() . '/*/theme.json') ?: [] as $f) {
            $out[] = basename(dirname($f));
        }
        return $out;
    }
}
