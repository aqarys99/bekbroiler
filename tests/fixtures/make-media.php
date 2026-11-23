<?php
declare(strict_types=1);

// Генерация фикстур для E2E медиатеки в tests/fixtures/media/. Запуск: php tests/fixtures/make-media.php [--force]
require __DIR__ . '/../Support/ImageFixtures.php';

use Tests\Support\ImageFixtures;

$dir = __DIR__ . '/media';
$force = in_array('--force', $argv ?? [], true);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

function put(string $dir, string $name, callable $make, bool $force): void
{
    $f = $dir . '/' . $name;
    if (is_file($f) && !$force) {
        return;
    }
    file_put_contents($f, $make());
}

/** Градиент с цветным маркером в левом верхнем углу (виден поворот). */
function scene(int $w, int $h): GdImage
{
    $im = imagecreatetruecolor($w, $h);
    for ($x = 0; $x < $w; $x += 8) {
        $c = imagecolorallocate($im, (int)($x * 255 / $w), 120, 255 - (int)($x * 255 / $w));
        imagefilledrectangle($im, $x, 0, $x + 7, $h - 1, $c);
    }
    for ($i = 0; $i < 60; $i++) {
        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(40, 300), mt_rand(40, 300), imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
    }
    imagefilledrectangle($im, 0, 0, 199, 199, imagecolorallocate($im, 255, 0, 0));
    return $im;
}

/** Добавить COM-сегменты со случайными байтами, чтобы довести JPEG до $target байт (декодер их игнорирует). */
function padJpeg(string $jpeg, int $target): string
{
    $pad = '';
    while (strlen($jpeg) + strlen($pad) < $target) {
        $chunk = random_bytes(65000);
        $pad .= "\xFF\xFE" . pack('n', 65002) . $chunk;
    }
    // после SOI (+ возможных APP1 уже в $jpeg): вставляем в позицию 2, до остальных сегментов
    return substr($jpeg, 0, 2) . $pad . substr($jpeg, 2);
}

mt_srand(42);

// Большой JPEG ~9 МБ, 4000x3000, EXIF Orientation=6 + GPS + XMP (генерируемый, в .gitignore)
put($dir, 'photo-exif-gps.jpg', static function () {
    $im = scene(4000, 3000);
    return padJpeg(ImageFixtures::jpegWithExif($im, 6, true), 9 * 1048576);
}, $force);

// Небольшие фото с ориентацией 3, 6, 8 и без EXIF
foreach ([3, 6, 8] as $o) {
    put($dir, "orient-$o.jpg", static function () use ($o) {
        return ImageFixtures::jpegWithExif(ImageFixtures::marker(400, 300, 40), $o, true);
    }, $force);
}
put($dir, 'small.jpg', static fn() => ImageFixtures::jpegBytes(ImageFixtures::marker(640, 480, 60)), $force);
put($dir, 'small2.jpg', static fn() => ImageFixtures::jpegBytes(ImageFixtures::marker(320, 240, 30)), $force);

// PNG с прозрачностью 2600x1800 (больше client_max_side=2560 -> сжимается в браузере)
put($dir, 'alpha.png', static function () {
    $im = imagecreatetruecolor(800, 600);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledellipse($im, 400, 300, 500, 400, imagecolorallocatealpha($im, 200, 30, 30, 0));
    return ImageFixtures::pngBytes($im);
}, $force);
put($dir, 'tiny.png', static fn() => ImageFixtures::pngBytes(ImageFixtures::marker(64, 48, 8)), $force);

// GIF
put($dir, 'anim.gif', static function () {
    $im = ImageFixtures::marker(120, 90, 20);
    ob_start();
    imagegif($im);
    return (string)ob_get_clean();
}, $force);

// ~21 МБ GIF (больше лимита 20 МБ; клиент отправляет GIF как есть -> too_large). Генерируемый, в .gitignore
put($dir, 'big.gif', static function () {
    ob_start();
    imagegif(ImageFixtures::marker(100, 100, 10));
    $g = (string)ob_get_clean();
    // мусор после трейлера GIF (0x3B) игнорируется декодерами
    return $g . random_bytes(21 * 1048576);
}, $force);

// Опасные и неподходящие
put($dir, 'fake.php.jpg', static fn() => "<?php echo 'PWNED'; system(\$_GET['c'] ?? 'id'); ?>\n" . str_repeat('A', 2048), $force);
put($dir, 'fake-polyglot.php.jpg', static fn() => ImageFixtures::jpegBytes(ImageFixtures::marker(100, 100, 10)) . "<?php echo 'PWNED'; ?>", $force);
put($dir, 'image.svg', static fn() => '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script><rect width="10" height="10"/></svg>', $force);
put($dir, 'doc.pdf', static fn() => "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R/Size 4>>\n%%EOF\n", $force);
put($dir, 'bomb.png', static fn() => ImageFixtures::pngHeader(30000, 30000), $force);
put($dir, 'broken.jpg', static function () {
    $j = ImageFixtures::jpegBytes(ImageFixtures::marker(800, 600, 50));
    return substr($j, 0, (int)(strlen($j) * 0.4));
}, $force);
put($dir, 'sample.heic', static fn() => "\0\0\0\x18ftypheic\0\0\0\0mif1heic" . random_bytes(512), $force);

echo "OK\n";
