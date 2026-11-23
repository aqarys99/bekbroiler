<?php
declare(strict_types=1);

namespace App\Core;

final class ErrorHandler
{
    public static function register(): void
    {
        ini_set('display_errors', '0');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            if ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) {
                Logger::warning($message, ['file' => $file . ':' . $line]);
                return true;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        set_exception_handler([self::class, 'handle']);
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::handle(new \ErrorException($err['message'], 0, $err['type'], $err['file'], $err['line']));
            }
        });
    }

    public static function handle(\Throwable $e): void
    {
        $code = $e instanceof HttpException ? $e->getStatusCode() : 500;
        if ($code >= 500) {
            self::report($e);
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
            if ($e instanceof HttpException) {
                foreach ($e->getHeaders() as $name => $value) {
                    header($name . ': ' . $value);
                }
            }
        }
        echo self::render($e);
    }

    /** Запись в лог. Тело POST не логируется, аргументы вызовов в трассировку не попадают. */
    public static function report(\Throwable $e): void
    {
        Logger::error(get_class($e) . ': ' . $e->getMessage(), [
            'at'     => $e->getFile() . ':' . $e->getLine(),
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'uri'    => $_SERVER['REQUEST_URI'] ?? '',
            'trace'  => self::formatTrace($e),
        ]);
    }

    public static function formatTrace(\Throwable $e): string
    {
        $lines = [];
        foreach ($e->getTrace() as $i => $f) {
            $where = isset($f['file']) ? $f['file'] . ':' . ($f['line'] ?? 0) : '[internal]';
            $lines[] = '#' . $i . ' ' . $where . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . '()';
        }
        return implode("\n", $lines);
    }

    public static function render(\Throwable $e): string
    {
        $code = $e instanceof HttpException ? $e->getStatusCode() : 500;
        $debug = $code >= 500 && (bool)config('debug', false);
        $titleKey = 'error.' . $code . '.title';
        $title = t($titleKey);
        $text = t('error.' . $code . '.text');
        if ($code === 404 && self::isPublicPath()) {
            try {
                return \App\Controllers\Site\SiteController::notFound();
            } catch (\Throwable) {
                // падаем на обычную страницу ошибки
            }
        }
        try {
            $tpl = 'errors/' . $code;
            if (!View::exists($tpl)) {
                throw new \RuntimeException('no template');
            }
            return View::render($tpl, [
                'title'     => $title,
                'code'      => $code,
                'debug'     => $debug,
                'exception' => $debug ? $e : null,
                'trace'     => $debug ? self::formatTrace($e) : '',
            ]);
        } catch (\Throwable) {
            return self::fallback($code, $title, $text, $debug ? $e : null);
        }
    }

    /** GET-адрес публичного сайта (не админка, не установщик, не служебные тестовые маршруты). */
    private static function isPublicPath(): bool
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true) || !Installer::isInstalled()) {
            return false;
        }
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $base = rtrim((string)config('base_path', ''), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $a = '/' . trim((string)config('admin_path', 'admin'), '/');
        return !($path === $a || str_starts_with($path, $a . '/') || str_starts_with($path, '/install.php') || str_starts_with($path, '/__test'));
    }

    private static function fallback(int $code, string $title, string $text, ?\Throwable $e): string
    {
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>' . e($title)
            . '</title></head><body><h1>' . e($title) . '</h1>';
        if ($text !== 'error.' . $code . '.text') {
            $html .= '<p>' . e($text) . '</p>';
        }
        if ($e !== null) {
            $html .= '<pre>' . e(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine()
                . "\n" . self::formatTrace($e)) . '</pre>';
        }
        return $html . '<p><a href="' . e(url('/')) . '">' . e(t('error.home')) . '</a></p></body></html>';
    }
}
