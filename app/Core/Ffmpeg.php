<?php
declare(strict_types=1);

namespace App\Core;

/** Необязательный ffmpeg: без него загрузка видео работает как обычно, без перекодирования. */
final class Ffmpeg
{
    private static ?string $path = null;
    private static bool $checked = false;

    /** Путь к ffmpeg или null. Ищем через `which` (proc_open без оболочки). */
    public static function path(): ?string
    {
        if (self::$checked) {
            return self::$path;
        }
        self::$checked = true;
        if (config('video.ffmpeg', true) === false || !function_exists('proc_open')
            || in_array('proc_open', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
            return null;
        }
        $res = self::run(['which', 'ffmpeg'], 3);
        $p = $res !== null && $res['code'] === 0 ? trim(strtok($res['out'], "\n") ?: '') : '';
        self::$path = $p !== '' && is_executable($p) ? $p : null;
        return self::$path;
    }

    public static function available(): bool
    {
        return self::path() !== null;
    }

    /** @param string[] $cmd @return array{code:int,out:string}|null */
    public static function run(array $cmd, int $timeout): ?array
    {
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $start = time();
        while (true) {
            $out .= (string)stream_get_contents($pipes[1]);
            $out .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $code = $st['exitcode'];
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($proc, 9);
                $code = -1;
                break;
            }
            usleep(100000);
        }
        $out .= (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($proc);
        return ['code' => $code === -1 && $closed !== -1 ? $closed : $code, 'out' => substr($out, -4000)];
    }
}
