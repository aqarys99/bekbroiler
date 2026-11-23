<?php
declare(strict_types=1);

/**
 * Обработка загруженных видео (только если на сервере есть ffmpeg): H.264 720p + кадр-обложка.
 * Запуск по cron раз в минуту: php /путь/к/сайту/bin/process-videos.php
 * Без ffmpeg ничего не делает: видео и так работают без перекодирования.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}

define('ROOT', dirname(__DIR__));
require ROOT . '/app/autoload.php';
require ROOT . '/app/helpers.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\DiskUsage;
use App\Core\Ffmpeg;
use App\Core\Logger;
use App\Core\Mp4;
use App\Core\Uploader;
use App\Core\VideoUploader;
use App\Models\Media;

date_default_timezone_set('UTC');
Config::load();

$lock = fopen(Config::storageDir() . '/video-process.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // уже работает другой процесс
}

(new VideoUploader())->sweep();

$ffmpeg = Ffmpeg::path();
if ($ffmpeg === null) {
    echo "ffmpeg не найден, обработка не требуется." . PHP_EOL;
    exit(0);
}

$root = DiskUsage::uploadsDir();
$rows = Database::fetchAll("SELECT v.id, v.media_id, v.poster_media_id, m.path FROM videos v JOIN media m ON m.id = v.media_id WHERE v.status = 'processing' AND v.source = 'upload' ORDER BY v.id");
foreach ($rows as $r) {
    $src = $root . '/' . $r['path'];
    $tmpOut = $src . '.out.mp4';
    $tmpPoster = $src . '.poster.jpg';
    $status = 'failed';
    $now = gmdate('Y-m-d H:i:s');
    try {
        if (!is_file($src)) {
            throw new RuntimeException('Исходный файл не найден');
        }
        $enc = Ffmpeg::run([$ffmpeg, '-y', '-v', 'error', '-i', $src, '-vf', "scale=-2:'min(720,ih)'", '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '26',
            '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', $tmpOut], 3600);
        if ($enc === null || $enc['code'] !== 0 || !Mp4::isMp4($tmpOut)) {
            throw new RuntimeException('ffmpeg: ' . ($enc['out'] ?? 'не запустился'));
        }
        rename($tmpOut, $src);
        $size = (int)filesize($src);
        $posterId = $r['poster_media_id'] !== null ? (int)$r['poster_media_id'] : null;
        $shot = Ffmpeg::run([$ffmpeg, '-y', '-v', 'error', '-ss', '1', '-i', $src, '-frames:v', '1', '-q:v', '3', $tmpPoster], 120);
        if ($posterId === null && $shot !== null && $shot['code'] === 0 && is_file($tmpPoster) && filesize($tmpPoster) > 0) {
            try {
                $row = (new Uploader(null, null, static fn(string $p): bool => true))->handle(
                    ['name' => 'poster.jpg', 'tmp_name' => $tmpPoster, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpPoster)],
                    null
                );
                $posterId = (int)$row['id'];
            } catch (Throwable $e) {
                Logger::warning('Не удалось сохранить кадр-обложку', ['error' => $e->getMessage()]);
            }
        }
        Database::query('UPDATE media SET size = ?, status = ?, updated_at = ? WHERE id = ?', [$size, 'ready', $now, (int)$r['media_id']]);
        Database::query(
            'UPDATE videos SET status = ?, poster_media_id = ?, duration = COALESCE(?, duration), updated_at = ? WHERE id = ?',
            ['ready', $posterId, Mp4::duration($src), $now, (int)$r['id']]
        );
        $status = 'ready';
    } catch (Throwable $e) {
        Logger::error('Обработка видео не удалась', ['video' => $r['id'], 'error' => $e->getMessage()]);
        Database::query("UPDATE media SET status = 'failed', updated_at = ? WHERE id = ?", [$now, (int)$r['media_id']]);
        Database::query("UPDATE videos SET status = 'failed', updated_at = ? WHERE id = ?", [$now, (int)$r['id']]);
    }
    @unlink($tmpOut);
    @unlink($tmpPoster);
    echo "Видео #{$r['id']}: $status" . PHP_EOL;
}
exit(0);
