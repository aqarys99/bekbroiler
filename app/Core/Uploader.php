<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Media;

/** Приём загруженного фото: проверки -> ImageProcessor -> атомарная запись -> строка media. */
final class Uploader
{
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const HEIC = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];

    /** @var callable(string,string):bool */
    private $writer;
    /** @var callable(string):bool */
    private $isUploaded;

    /**
     * @param callable(string,string):bool|null $writer запись файла (путь, байты); подменяется в тестах
     * @param callable(string):bool|null $isUploaded проверка источника файла
     */
    public function __construct(private ?string $root = null, ?callable $writer = null, ?callable $isUploaded = null)
    {
        $this->root = rtrim($root ?? DiskUsage::uploadsDir(), '/');
        $this->writer = $writer ?? static fn(string $p, string $b): bool => @file_put_contents($p, $b) === strlen($b);
        $this->isUploaded = $isUploaded ?? 'is_uploaded_file';
    }

    /**
     * @param array<string,mixed>|null $file элемент $_FILES
     * @return array<string,mixed> созданная строка media
     * @throws UploadException
     */
    public function handle(?array $file, ?int $userId): array
    {
        $max = Media::maxUploadBytes();
        $tooLarge = new UploadException('too_large', 413, ['max' => format_bytes($max)]);
        if ($file === null || !isset($file['error'], $file['tmp_name']) || is_array($file['error'])) {
            throw new UploadException('no_file', 422);
        }
        $err = (int)$file['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw $tooLarge;
        }
        $tmp = (string)$file['tmp_name'];
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !($this->isUploaded)($tmp) || !is_file($tmp)) {
            throw new UploadException('no_file', 422);
        }
        $sourceSize = (int)filesize($tmp);
        if ($sourceSize > $max) {
            throw $tooLarge;
        }
        if ($sourceSize === 0) {
            throw new UploadException('no_file', 422);
        }

        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (in_array($mime, self::HEIC, true)) {
            if (!ImageProcessor::heicSupported()) {
                throw new UploadException('heic_unsupported', 415);
            }
        } elseif (!in_array($mime, self::ALLOWED, true)) {
            throw new UploadException('not_image', 415);
        }

        $variants = ImageProcessor::process($tmp, $mime);
        return $this->store($variants, $this->cleanName((string)($file['name'] ?? '')), $sourceSize, $userId);
    }

    /** @param array<string,array{w:int,h:int,webp:string,jpg:string}> $variants */
    private function store(array $variants, string $name, int $sourceSize, ?int $userId): array
    {
        $rel = 'images/' . gmdate('Y') . '/' . gmdate('m');
        $dir = $this->root . '/' . $rel;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UploadException('disk_full', 507);
        }
        $hex = bin2hex(random_bytes(16));
        $base = $rel . '/' . $hex;
        $written = [];
        $meta = [];
        $total = 0;
        try {
            foreach (['thumb', 'medium', 'large'] as $size) {
                $v = $variants[$size];
                $meta[$size] = ['w' => $v['w'], 'h' => $v['h']];
                foreach (['webp', 'jpg'] as $fmt) {
                    $relFile = $base . '-' . $size . '.' . $fmt;
                    $tmpFile = $this->root . '/' . $relFile . '.tmp';
                    $written[] = $tmpFile;
                    if (!($this->writer)($tmpFile, $v[$fmt])) {
                        throw new UploadException('disk_full', 507);
                    }
                    @chmod($tmpFile, 0644);
                    if (!@rename($tmpFile, $this->root . '/' . $relFile)) {
                        throw new UploadException('disk_full', 507);
                    }
                    array_pop($written);
                    $written[] = $this->root . '/' . $relFile;
                    $meta[$size][$fmt] = $relFile;
                    $meta[$size][$fmt . '_bytes'] = strlen($v[$fmt]);
                    $total += strlen($v[$fmt]);
                }
            }
            $id = Media::create([
                'type'          => 'image',
                'original_name' => $name,
                'path'          => $base,
                'mime'          => 'image/webp',
                'size'          => $total,
                'source_size'   => $sourceSize,
                'width'         => $variants['large']['w'],
                'height'        => $variants['large']['h'],
                'variants'      => json_encode($meta, JSON_UNESCAPED_SLASHES),
                'uploaded_by'   => $userId,
            ]);
            return Media::find($id) ?? throw new \RuntimeException('Запись media не найдена после вставки');
        } catch (\Throwable $e) {
            foreach ($written as $f) {
                @unlink($f);
            }
            throw $e;
        }
    }

    /** Имя без пути и управляющих символов, до 255 символов. */
    public function cleanName(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $name = trim($name);
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }
        $name = mb_substr($name, 0, 255);
        return $name === '' || $name === '.' || $name === '..' ? 'image' : $name;
    }
}
