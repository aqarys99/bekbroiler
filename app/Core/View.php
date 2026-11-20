<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    private static ?string $path = null;

    public static function setPath(?string $path): void
    {
        self::$path = $path === null ? null : rtrim($path, '/');
    }

    private static function dir(): string
    {
        return self::$path ?? ROOT . '/admin-views';
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/auth'): string
    {
        $content = self::capture($template, $data);
        if ($layout === null) {
            return $content;
        }
        $data['content'] = $content;
        $data['title'] = $data['title'] ?? '';
        return self::capture($layout, $data);
    }

    public static function exists(string $template): bool
    {
        return !str_contains($template, '..') && is_file(self::dir() . '/' . $template . '.php');
    }

    private static function capture(string $template, array $data): string
    {
        if (str_contains($template, '..') || str_contains($template, "\0")) {
            throw new \InvalidArgumentException('Недопустимое имя шаблона');
        }
        $file = self::dir() . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('Шаблон не найден: ' . $template);
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
}
