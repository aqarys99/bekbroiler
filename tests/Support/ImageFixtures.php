<?php
declare(strict_types=1);

namespace Tests\Support;

/** Тестовые картинки, собранные через GD и вручную байтами (EXIF/GPS/XMP вставляются в JPEG сами). */
final class ImageFixtures
{
    /** Серый фон, красный квадрат (side px) в левом верхнем углу: по нему видно поворот. */
    public static function marker(int $w, int $h, int $side = 20): \GdImage
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 128, 128, 128));
        imagefilledrectangle($im, 0, 0, $side - 1, $side - 1, imagecolorallocate($im, 255, 0, 0));
        return $im;
    }

    public static function jpegBytes(\GdImage $im, int $q = 90): string
    {
        ob_start();
        imagejpeg($im, null, $q);
        return (string)ob_get_clean();
    }

    public static function pngBytes(\GdImage $im): string
    {
        ob_start();
        imagepng($im);
        return (string)ob_get_clean();
    }

    /** JPEG с EXIF (Orientation), GPS и XMP — всё это обязано исчезнуть после обработки. */
    public static function jpegWithExif(\GdImage $im, int $orientation, bool $gps = true): string
    {
        $jpeg = self::jpegBytes($im);
        $le = static fn(int $v, int $n = 4): string => pack($n === 2 ? 'v' : 'V', $v);

        // IFD0: Orientation (+ GPSInfo)
        $entries = 1 + ($gps ? 1 : 0);
        $ifd0Size = 2 + 12 * $entries + 4;
        $gpsOffset = 8 + $ifd0Size;
        $tiff = 'II' . $le(42, 2) . $le(8);
        $tiff .= $le($entries, 2);
        $tiff .= $le(0x0112, 2) . $le(3, 2) . $le(1) . $le($orientation, 2) . "\0\0";
        if ($gps) {
            $tiff .= $le(0x8825, 2) . $le(4, 2) . $le(1) . $le($gpsOffset);
        }
        $tiff .= $le(0);
        if ($gps) {
            // GPS IFD: LatitudeRef 'N', Latitude 43/1 15/1 0/1
            $latOffset = $gpsOffset + 2 + 12 * 2 + 4;
            $tiff .= $le(2, 2);
            $tiff .= $le(1, 2) . $le(2, 2) . $le(2) . "N\0\0\0";
            $tiff .= $le(2, 2) . $le(5, 2) . $le(3) . $le($latOffset);
            $tiff .= $le(0);
            $tiff .= $le(43) . $le(1) . $le(15) . $le(1) . $le(0) . $le(1);
        }
        $exif = "Exif\0\0" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
        $xmp = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta xmlns:x=\"adobe:ns:meta/\"></x:xmpmeta>";
        $app1x = "\xFF\xE1" . pack('n', strlen($xmp) + 2) . $xmp;
        return "\xFF\xD8" . $app1 . $app1x . substr($jpeg, 2);
    }

    /** PNG-заголовок с заявленными размерами (без данных): проверка decompression bomb. */
    public static function pngHeader(int $w, int $h): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };
        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            . $chunk('IDAT', gzcompress(str_repeat("\0", 16))) . $chunk('IEND', '');
    }

    /** Пиксель результата: [r,g,b,a] (a: 0 непрозрачный .. 127 прозрачный). */
    public static function pixel(string $bytes, int $x, int $y): array
    {
        $im = imagecreatefromstring($bytes);
        $c = imagecolorat($im, $x, $y);
        return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255, ($c >> 24) & 127];
    }
}
