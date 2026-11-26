<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Media;

/** Приём файла документа: белый список, проверка сигнатуры, случайное имя, строка media (type='file'). */
final class DocumentUploader
{
    /** расширение => [mime, вид сигнатуры] */
    public const TYPES = [
        'pdf'  => ['application/pdf', 'pdf'],
        'doc'  => ['application/msword', 'ole'],
        'xls'  => ['application/vnd.ms-excel', 'ole'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'ole'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'zip:word/'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'zip:xl/'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'zip:ppt/'],
        'jpg'  => ['image/jpeg', 'image/jpeg'],
        'png'  => ['image/png', 'image/png'],
    ];

    /** Допустимые для документов страниц типы: PDF, Word, Excel. */
    public const PAGE_TYPES = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private ?string $root = null)
    {
        $this->root = rtrim($root ?? DiskUsage::uploadsDir(), '/');
    }

    /**
     * @param array<string,mixed>|null $file элемент $_FILES
     * @param string[]|null $allowed ограничение по расширениям (null — весь белый список)
     * @return array<string,mixed> строка media
     * @throws UploadException
     */
    public function handle(?array $file, ?int $userId, ?array $allowed = null): array
    {
        $max = min(Media::maxUploadBytes(), self::MAX_BYTES);
        if ($file === null || !isset($file['error'], $file['tmp_name']) || is_array($file['error'])) {
            throw new UploadException('no_file', 422);
        }
        $err = (int)$file['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadException('too_large', 413, ['max' => format_bytes($max)]);
        }
        $tmp = (string)$file['tmp_name'];
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp) || !is_file($tmp)) {
            throw new UploadException('no_file', 422);
        }
        $size = (int)filesize($tmp);
        if ($size > $max) {
            throw new UploadException('too_large', 413, ['max' => format_bytes($max)]);
        }
        if ($size === 0) {
            throw new UploadException('no_file', 422);
        }
        $name = $this->cleanName((string)($file['name'] ?? ''));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }
        if (!isset(self::TYPES[$ext]) || ($allowed !== null && !in_array($ext, $allowed, true)) || !$this->signatureOk($tmp, $ext)) {
            throw new UploadException('doc_type', 415);
        }

        $rel = 'docs/' . gmdate('Y') . '/' . gmdate('m');
        $dir = $this->root . '/' . $rel;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UploadException('disk_full', 507);
        }
        $path = $rel . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $this->root . '/' . $path;
        if (!@move_uploaded_file($tmp, $dest)) {
            throw new UploadException('disk_full', 507);
        }
        @chmod($dest, 0644);
        try {
            $id = Media::create([
                'type' => 'file', 'original_name' => $name, 'path' => $path, 'mime' => self::TYPES[$ext][0],
                'size' => $size, 'source_size' => $size, 'variants' => '{}', 'uploaded_by' => $userId,
            ]);
            return Media::find($id) ?? throw new \RuntimeException('Запись media не найдена после вставки');
        } catch (\Throwable $e) {
            @unlink($dest);
            throw $e;
        }
    }

    private function signatureOk(string $tmp, string $ext): bool
    {
        $head = (string)file_get_contents($tmp, false, null, 0, 1024);
        $kind = self::TYPES[$ext][1];
        if ($kind === 'pdf') {
            return str_contains($head, '%PDF-') && (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmp) === 'application/pdf';
        }
        if ($kind === 'ole') {
            return str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1");
        }
        if (str_starts_with($kind, 'image/')) {
            $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            return $mime === $kind;
        }
        // Office Open XML: zip с [Content_Types].xml и папкой своего формата
        if (!str_starts_with($head, "PK\x03\x04")) {
            return false;
        }
        if (!class_exists(\ZipArchive::class)) {
            return true;
        }
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            return false;
        }
        $ok = $zip->locateName('[Content_Types].xml') !== false;
        if ($ok) {
            $prefix = substr($kind, 4);
            $ok = false;
            for ($i = 0, $n = min($zip->numFiles, 2000); $i < $n; $i++) {
                if (str_starts_with((string)$zip->getNameIndex($i), $prefix)) {
                    $ok = true;
                    break;
                }
            }
        }
        $zip->close();
        return $ok;
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '');
        if (!mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }
        $name = mb_substr($name, 0, 255);
        return $name === '' || $name === '.' || $name === '..' ? 'document' : $name;
    }
}
