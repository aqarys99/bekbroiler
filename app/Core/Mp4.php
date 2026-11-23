<?php
declare(strict_types=1);

namespace App\Core;

/** Минимальный разбор MP4: сигнатура ftyp и длительность из mvhd. */
final class Mp4
{
    public static function isMp4(string $file): bool
    {
        $h = @file_get_contents($file, false, null, 0, 12);
        return is_string($h) && strlen($h) >= 12 && substr($h, 4, 4) === 'ftyp';
    }

    /** Длительность в секундах или null. */
    public static function duration(string $file): ?int
    {
        $f = @fopen($file, 'rb');
        if ($f === false) {
            return null;
        }
        try {
            $size = (int)filesize($file);
            $pos = 0;
            for ($i = 0; $i < 64 && $pos + 8 <= $size; $i++) {
                fseek($f, $pos);
                $h = (string)fread($f, 16);
                if (strlen($h) < 8) {
                    return null;
                }
                $len = unpack('N', substr($h, 0, 4))[1];
                $type = substr($h, 4, 4);
                $hdr = 8;
                if ($len === 1 && strlen($h) >= 16) {
                    $len = unpack('J', substr($h, 8, 8))[1];
                    $hdr = 16;
                } elseif ($len === 0) {
                    $len = $size - $pos;
                }
                if ($len < $hdr) {
                    return null;
                }
                if ($type === 'moov') {
                    $take = min($len - $hdr, 16 * 1048576);
                    fseek($f, $pos + $hdr);
                    return self::fromMoov((string)fread($f, $take));
                }
                $pos += $len;
            }
        } finally {
            fclose($f);
        }
        return null;
    }

    private static function fromMoov(string $moov): ?int
    {
        $p = 0;
        $n = strlen($moov);
        while ($p + 8 <= $n) {
            $len = unpack('N', substr($moov, $p, 4))[1];
            $type = substr($moov, $p + 4, 4);
            if ($len < 8) {
                return null;
            }
            if ($type === 'mvhd') {
                $v = ord($moov[$p + 8] ?? "\0");
                if ($v === 1) {
                    $ts = unpack('N', substr($moov, $p + 28, 4))[1] ?? 0;
                    $dur = unpack('J', substr($moov, $p + 32, 8))[1] ?? 0;
                } else {
                    $ts = unpack('N', substr($moov, $p + 20, 4))[1] ?? 0;
                    $dur = unpack('N', substr($moov, $p + 24, 4))[1] ?? 0;
                }
                return $ts > 0 && $dur > 0 ? (int)round($dur / $ts) : null;
            }
            $p += $len;
        }
        return null;
    }
}
