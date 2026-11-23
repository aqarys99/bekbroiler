<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Конвейер фото: проверка размеров -> декодирование -> поворот по EXIF -> три размера в WebP и JPEG.
 * Метаданные не переносятся: перекодирование через GD их отбрасывает.
 */
final class ImageProcessor
{
    public const SIZES = ['large' => 1920, 'medium' => 1200, 'thumb' => 400];

    /** @var bool|null подмена доступности GD (тесты) */
    private static ?bool $availableOverride = null;

    public static function overrideAvailable(?bool $v): void
    {
        self::$availableOverride = $v;
    }

    /** GD с JPEG и WebP: без него фото обработать нельзя. */
    public static function available(): bool
    {
        return self::$availableOverride
            ?? (extension_loaded('gd') && function_exists('imagecreatefromjpeg') && function_exists('imagewebp'));
    }

    /**
     * @return array{thumb:array,medium:array,large:array} каждый: w, h, webp (байты), jpg (байты)
     * @throws UploadException
     */
    public static function process(string $path, string $mime): array
    {
        if (!self::available()) {
            throw new UploadException('image_unsupported', 503);
        }
        $isHeic = in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true);
        $blob = null;
        if ($isHeic) {
            $blob = self::heicToPng($path);
            $path = null;
        } else {
            self::checkDimensions($path);
        }
        $data = $blob ?? (string)file_get_contents($path);
        $orientation = (!$isHeic && $mime === 'image/jpeg') ? self::readOrientation($data) : 1;

        if ($mime === 'image/jpeg' && !self::jpegComplete($data)) {
            throw new UploadException('broken', 422);
        }
        $im = self::decode($data, $mime === 'image/jpeg');
        unset($data, $blob);
        $im = self::orient($im, $orientation);

        $w = imagesx($im);
        $h = imagesy($im);
        $out = [];
        $prev = $im;
        foreach (self::SIZES as $name => $side) {
            $scale = min(1.0, $side / max($w, $h));
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            if ($nw === imagesx($prev) && $nh === imagesy($prev)) {
                $cur = $prev;
            } else {
                $cur = imagecreatetruecolor($nw, $nh);
                imagealphablending($cur, false);
                imagesavealpha($cur, true);
                imagefill($cur, 0, 0, imagecolorallocatealpha($cur, 0, 0, 0, 127));
                imagecopyresampled($cur, $prev, 0, 0, 0, 0, $nw, $nh, imagesx($prev), imagesy($prev));
            }
            $out[$name] = [
                'w' => $nw, 'h' => $nh,
                'webp' => self::encodeWebp($cur),
                'jpg' => self::encodeJpeg($cur),
            ];
            $prev = $cur;
        }
        return $out;
    }

    /** Размеры и защита от decompression bomb до декодирования. */
    public static function checkDimensions(string $path): array
    {
        $info = @getimagesize($path);
        if (!is_array($info) || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw new UploadException('broken', 422);
        }
        [$w, $h] = [(int)$info[0], (int)$info[1]];
        $maxPixels = (int)config('media.max_pixels', 40000000);
        if ($w * $h > $maxPixels || $w * $h * 5 * 1.8 > self::freeMemory()) {
            throw new UploadException('too_many_pixels', 422, [
                'mp' => (int)round($w * $h / 1000000),
                'max' => (int)floor(min($maxPixels, max(0, self::freeMemory() / 9)) / 1000000),
            ]);
        }
        return [$w, $h];
    }

    /** false, если данные JPEG обрываются до маркера EOI (обрезанный файл). GD такое молча «дочитывает». */
    public static function jpegComplete(string $d): bool
    {
        $len = strlen($d);
        $pos = 2;
        while ($pos + 2 <= $len) {
            if ($d[$pos] !== "\xFF") {
                return true; // странная структура — решает декодер
            }
            $m = ord($d[$pos + 1]);
            if ($m === 0xFF) {
                $pos++;
                continue;
            }
            if ($m === 0xD9) {
                return true;
            }
            if ($m === 0x00 || ($m >= 0xD0 && $m <= 0xD8) || $m === 0x01) {
                $pos += 2;
                continue;
            }
            if ($pos + 4 > $len) {
                return false;
            }
            $segLen = (ord($d[$pos + 2]) << 8) | ord($d[$pos + 3]);
            $pos += 2 + $segLen;
            if ($m === 0xDA) {
                // энтропийные данные до следующего маркера
                while (true) {
                    $f = strpos($d, "\xFF", $pos);
                    if ($f === false || $f + 1 >= $len) {
                        return false;
                    }
                    $n = ord($d[$f + 1]);
                    if ($n === 0x00 || ($n >= 0xD0 && $n <= 0xD7)) {
                        $pos = $f + 2;
                        continue;
                    }
                    $pos = $f;
                    break;
                }
            }
        }
        return false;
    }

    private static function freeMemory(): float
    {
        $limit = Guard::iniBytes((string)ini_get('memory_limit'));
        if ($limit <= 0) {
            return PHP_FLOAT_MAX;
        }
        return max(0, $limit - memory_get_usage(true));
    }

    private static function decode(string $data, bool $jpeg): \GdImage
    {
        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            $im = imagecreatefromstring($data);
        } finally {
            restore_error_handler();
        }
        if ($im === false) {
            throw new UploadException('broken', 422);
        }
        if ($jpeg) {
            foreach ($warnings as $m) {
                if (stripos($m, 'premature end') !== false || stripos($m, 'not a JPEG') !== false
                    || stripos($m, 'Corrupt JPEG data: premature') !== false) {
                    throw new UploadException('broken', 422);
                }
            }
        }
        if (!imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }
        imagealphablending($im, false);
        imagesavealpha($im, true);
        return $im;
    }

    private static function orient(\GdImage $im, int $o): \GdImage
    {
        if ($o < 2 || $o > 8) {
            return $im;
        }
        // 5 и 7: сначала поворот на 90 по часовой, затем отражение
        $rot = [3 => 180, 5 => -90, 6 => -90, 7 => -90, 8 => 90][$o] ?? 0;
        if ($rot !== 0) {
            $white = imagecolorallocatealpha($im, 0, 0, 0, 127);
            $r = imagerotate($im, $rot, $white);
            if ($r === false) {
                throw new UploadException('broken', 422);
            }
            imagealphablending($r, false);
            imagesavealpha($r, true);
            $im = $r;
        }
        $flip = match ($o) {
            2, 5 => IMG_FLIP_HORIZONTAL,
            4, 7 => IMG_FLIP_VERTICAL,
            default => null,
        };
        if ($flip !== null) {
            imageflip($im, $flip);
        }
        return $im;
    }

    private static function encodeWebp(\GdImage $im): string
    {
        ob_start();
        $ok = imagewebp($im, null, (int)config('media.webp_quality', 80));
        $bytes = (string)ob_get_clean();
        if (!$ok || $bytes === '') {
            throw new \RuntimeException('Не удалось закодировать WebP');
        }
        return $bytes;
    }

    private static function encodeJpeg(\GdImage $im): string
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagealphablending($flat, true);
        imagecopy($flat, $im, 0, 0, 0, 0, $w, $h);
        imageinterlace($flat, true);
        ob_start();
        $ok = imagejpeg($flat, null, (int)config('media.jpeg_quality', 82));
        $bytes = (string)ob_get_clean();
        if (!$ok || $bytes === '') {
            throw new \RuntimeException('Не удалось закодировать JPEG');
        }
        return $bytes;
    }

    public static function heicSupported(): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }
        try {
            return \Imagick::queryFormats('HEIC') !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /** HEIC -> PNG без метаданных, с учётом ориентации (только при Imagick с HEIC). */
    private static function heicToPng(string $path): string
    {
        if (!self::heicSupported()) {
            throw new UploadException('heic_unsupported', 415);
        }
        try {
            $im = new \Imagick();
            $im->pingImage($path);
            $maxPixels = (int)config('media.max_pixels', 40000000);
            if ($im->getImageWidth() * $im->getImageHeight() > $maxPixels) {
                throw new UploadException('too_many_pixels', 422, [
                    'mp' => (int)round($im->getImageWidth() * $im->getImageHeight() / 1000000),
                    'max' => (int)floor($maxPixels / 1000000),
                ]);
            }
            $im->clear();
            $im->readImage($path . '[0]');
            $im->autoOrient();
            $im->stripImage();
            $im->setImageFormat('png');
            return $im->getImageBlob();
        } catch (UploadException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new UploadException('broken', 422);
        }
    }

    /**
     * Ориентация EXIF (1-8) из JPEG: встроенный разбор APP1, не зависит от расширения exif.
     * @param string $data байты JPEG или путь к файлу
     */
    public static function readOrientation(string $data): int
    {
        if (strlen($data) < 1024 && is_file($data)) {
            $data = (string)file_get_contents($data);
        }
        $len = strlen($data);
        if ($len < 4 || $data[0] !== "\xFF" || $data[1] !== "\xD8") {
            return 1;
        }
        $pos = 2;
        while ($pos + 4 <= $len) {
            if ($data[$pos] !== "\xFF") {
                return 1;
            }
            $marker = ord($data[$pos + 1]);
            if ($marker === 0xFF) {
                $pos++;
                continue;
            }
            if ($marker === 0xDA || $marker === 0xD9) {
                return 1;
            }
            $segLen = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
            if ($segLen < 2) {
                return 1;
            }
            if ($marker === 0xE1 && substr($data, $pos + 4, 6) === "Exif\0\0") {
                $o = self::orientationFromTiff(substr($data, $pos + 10, $segLen - 8));
                if ($o !== null) {
                    return $o;
                }
            }
            $pos += 2 + $segLen;
        }
        return 1;
    }

    private static function orientationFromTiff(string $t): ?int
    {
        if (strlen($t) < 8) {
            return null;
        }
        $le = substr($t, 0, 2) === 'II';
        if (!$le && substr($t, 0, 2) !== 'MM') {
            return null;
        }
        $u16 = static fn(int $p): int => $p + 2 <= strlen($t) ? unpack($le ? 'v' : 'n', $t, $p)[1] : 0;
        $u32 = static fn(int $p): int => $p + 4 <= strlen($t) ? unpack($le ? 'V' : 'N', $t, $p)[1] : 0;
        if ($u16(2) !== 42) {
            return null;
        }
        $ifd = $u32(4);
        if ($ifd < 8 || $ifd + 2 > strlen($t)) {
            return null;
        }
        $n = min($u16($ifd), 200);
        for ($i = 0; $i < $n; $i++) {
            $e = $ifd + 2 + $i * 12;
            if ($e + 12 > strlen($t)) {
                return null;
            }
            if ($u16($e) === 0x0112) {
                $v = $u16($e + 8);
                return $v >= 1 && $v <= 8 ? $v : 1;
            }
        }
        return null;
    }
}
