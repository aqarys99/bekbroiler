<?php
declare(strict_types=1);

namespace App\Core;

final class Flash
{
    /** @param 'success'|'error'|'info' $type */
    public static function set(string $type, string $key, array $replace = []): void
    {
        Session::start();
        $_SESSION['_flash'] = ['type' => $type, 'text' => t($key, $replace)];
    }

    /** @return array{type:string,text:string}|null читается один раз */
    public static function pull(): ?array
    {
        Session::start();
        $f = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);
        return is_array($f) && isset($f['type'], $f['text']) ? $f : null;
    }
}
