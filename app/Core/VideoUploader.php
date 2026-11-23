<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Media;

/**
 * Загрузка MP4 частями (chunked, с возобновлением): init -> chunk x N -> finish.
 * Части лежат в storage/video-chunks/<id>/; недособранное старше суток удаляется.
 */
final class VideoUploader
{
    public const CHUNK = 5 * 1048576;
    public const STALE_SECONDS = 86400;

    private string $base;
    private string $uploads;

    public function __construct(?string $storage = null, ?string $uploads = null)
    {
        $this->base = rtrim($storage ?? Config::storageDir(), '/') . '/video-chunks';
        $this->uploads = rtrim($uploads ?? DiskUsage::uploadsDir(), '/');
    }

    public static function maxBytes(): int
    {
        return (int)round((float)config('video.max_mb', 100) * 1048576);
    }

    /** Размер части с учётом лимитов PHP (post_max_size / upload_max_filesize), с запасом на служебные поля. */
    public static function chunkSize(): int
    {
        $size = self::CHUNK;
        foreach (['upload_max_filesize', 'post_max_size'] as $k) {
            $b = Guard::iniBytes((string)ini_get($k));
            if ($b > 0) {
                $size = min($size, $b - 65536);
            }
        }
        return max(65536, $size);
    }

    /** @return array{upload_id:string,chunk_size:int,chunks:int,received:int[]} */
    public function init(string $name, int $size, int $userId): array
    {
        $max = self::maxBytes();
        if ($size <= 0) {
            throw new UploadException('no_file', 422);
        }
        if ($size > $max) {
            throw new UploadException('video_too_large', 413, ['max' => format_bytes($max)]);
        }
        $name = $this->cleanName($name);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'mp4') {
            throw new UploadException('video_type', 415);
        }
        $this->sweep();
        if (!is_dir($this->base) && !@mkdir($this->base, 0755, true) && !is_dir($this->base)) {
            throw new UploadException('disk_full', 507);
        }
        $free = @disk_free_space($this->base);
        if ($free !== false && $free < $size * 2) {
            throw new UploadException('disk_full', 507);
        }
        $id = bin2hex(random_bytes(16));
        $dir = $this->base . '/' . $id;
        if (!@mkdir($dir, 0755)) {
            throw new UploadException('disk_full', 507);
        }
        $chunk = self::chunkSize();
        $meta = ['name' => $name, 'size' => $size, 'user' => $userId, 'chunk' => $chunk, 'chunks' => (int)ceil($size / $chunk)];
        file_put_contents($dir . '/meta.json', json_encode($meta));
        return ['upload_id' => $id, 'chunk_size' => $chunk, 'chunks' => $meta['chunks'], 'received' => []];
    }

    /** Возобновление: какие части уже на сервере. @return array{upload_id:string,chunk_size:int,chunks:int,received:int[]} */
    public function status(string $id, int $userId): array
    {
        $meta = $this->meta($id, $userId);
        touch($this->dir($id));
        return ['upload_id' => $id, 'chunk_size' => $meta['chunk'], 'chunks' => $meta['chunks'], 'received' => $this->received($id, $meta)];
    }

    /** @param array<string,mixed>|null $file элемент $_FILES */
    public function chunk(string $id, int $index, ?array $file, int $userId): array
    {
        $meta = $this->meta($id, $userId);
        if ($index < 0 || $index >= $meta['chunks']) {
            throw new UploadException('no_file', 422);
        }
        if ($file === null || !isset($file['error'], $file['tmp_name']) || is_array($file['error'])) {
            throw new UploadException('no_file', 422);
        }
        $err = (int)$file['error'];
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadException('too_large', 413, ['max' => format_bytes($meta['chunk'])]);
        }
        $tmp = (string)$file['tmp_name'];
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            throw new UploadException('no_file', 422);
        }
        $expected = $index === $meta['chunks'] - 1 ? $meta['size'] - $meta['chunk'] * $index : $meta['chunk'];
        if ((int)filesize($tmp) !== $expected) {
            throw new UploadException('video_chunk', 422);
        }
        $dest = $this->dir($id) . '/' . $index . '.part';
        $part = $dest . '.tmp';
        if (!@move_uploaded_file($tmp, $part) || !@rename($part, $dest)) {
            @unlink($part);
            throw new UploadException('disk_full', 507);
        }
        touch($this->dir($id));
        return ['received' => count($this->received($id, $meta)), 'chunks' => $meta['chunks']];
    }

    /**
     * Собрать файл, проверить MP4 и создать строку media (type='video').
     * @return array{media:array,duration:?int,name:string}
     */
    public function finish(string $id, int $userId, string $status): array
    {
        $meta = $this->meta($id, $userId);
        $dir = $this->dir($id);
        if (count($this->received($id, $meta)) !== $meta['chunks']) {
            throw new UploadException('video_chunk', 409); // не всё загружено: докачать можно, ничего не удаляем
        }
        // забираем загрузку себе: повторный finish не соберёт файл дважды
        $work = $dir . '.assembling';
        if (!@rename($dir, $work)) {
            throw new UploadException('video_chunk', 409);
        }
        $out = $work . '/assembled.mp4';
        try {
            $fh = fopen($out, 'wb');
            if ($fh === false) {
                throw new UploadException('disk_full', 507);
            }
            for ($i = 0; $i < $meta['chunks']; $i++) {
                $p = $work . '/' . $i . '.part';
                $in = is_file($p) ? fopen($p, 'rb') : false;
                if ($in === false) {
                    fclose($fh);
                    throw new UploadException('video_chunk', 422);
                }
                $ok = stream_copy_to_stream($in, $fh) !== false;
                fclose($in);
                if (!$ok) {
                    fclose($fh);
                    throw new UploadException('disk_full', 507);
                }
            }
            fclose($fh);
            clearstatcache();
            if ((int)filesize($out) !== $meta['size']) {
                throw new UploadException('video_chunk', 422);
            }
            if (!Mp4::isMp4($out)) {
                throw new UploadException('video_type', 415);
            }
            $rel = 'videos/' . gmdate('Y') . '/' . gmdate('m');
            $destDir = $this->uploads . '/' . $rel;
            if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                throw new UploadException('disk_full', 507);
            }
            $path = $rel . '/' . bin2hex(random_bytes(16)) . '.mp4';
            $dest = $this->uploads . '/' . $path;
            if (!@rename($out, $dest) && !(@copy($out, $dest) && @unlink($out))) {
                throw new UploadException('disk_full', 507);
            }
            @chmod($dest, 0644);
            $duration = Mp4::duration($dest);
            try {
                $mid = Media::create([
                    'type' => 'video', 'original_name' => $meta['name'], 'path' => $path, 'mime' => 'video/mp4',
                    'size' => $meta['size'], 'source_size' => $meta['size'], 'variants' => '{}',
                    'status' => $status, 'uploaded_by' => $userId,
                ]);
            } catch (\Throwable $e) {
                @unlink($dest);
                throw $e;
            }
            return ['media' => Media::find($mid) ?? throw new \RuntimeException('Запись media не найдена'), 'duration' => $duration, 'name' => $meta['name']];
        } finally {
            self::rrmdir($work);
        }
    }

    /** Удалить недособранные загрузки старше суток. @return int сколько удалено */
    public function sweep(): int
    {
        $n = 0;
        foreach (glob($this->base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (time() - (int)filemtime($d) > self::STALE_SECONDS) {
                self::rrmdir($d);
                $n++;
            }
        }
        return $n;
    }

    private function dir(string $id): string
    {
        return $this->base . '/' . $id;
    }

    /** @return array{name:string,size:int,user:int,chunk:int,chunks:int} */
    private function meta(string $id, int $userId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || !is_file($this->dir($id) . '/meta.json')) {
            throw new UploadException('video_session', 404);
        }
        $m = json_decode((string)file_get_contents($this->dir($id) . '/meta.json'), true);
        if (!is_array($m) || (int)($m['user'] ?? 0) !== $userId) {
            throw new UploadException('video_session', 404);
        }
        return $m;
    }

    /** @return int[] */
    private function received(string $id, array $meta): array
    {
        $out = [];
        for ($i = 0; $i < $meta['chunks']; $i++) {
            if (is_file($this->dir($id) . '/' . $i . '.part')) {
                $out[] = $i;
            }
        }
        return $out;
    }

    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim(preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '');
        $name = mb_substr($name, 0, 255);
        return $name === '' ? 'video.mp4' : $name;
    }

    public static function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? self::rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
