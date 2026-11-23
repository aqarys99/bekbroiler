<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\ErrorHandler;
use App\Core\Ffmpeg;
use App\Core\Flash;
use App\Core\Json;
use App\Core\UploadException;
use App\Core\VideoUploader;
use App\Core\YouTube;
use App\Models\Media;
use App\Models\Video;

final class VideosController extends BaseController
{
    public function index(array $params = []): string
    {
        $edit = isset($_GET['edit']) && is_string($_GET['edit']) && ctype_digit($_GET['edit']) ? (int)$_GET['edit'] : 0;
        return $this->render(200, ['edit' => $edit]);
    }

    /** Добавить ролик по ссылке YouTube. */
    public function youtube(array $params = []): string
    {
        $user = $this->currentUser();
        $link = trim(self::post('url'));
        $title = trim(self::post('title'));
        $id = YouTube::parseId($link);
        $err = null;
        $needTitle = false;
        if ($link === '') {
            $err = t('videos.error.url_required');
        } elseif ($id === null) {
            $err = t('videos.error.url_invalid');
        } elseif (Video::youtubeExists($id)) {
            $err = t('videos.error.duplicate');
        } else {
            if ($title === '') {
                $title = YouTube::fetchTitle($id) ?? '';
            }
            if ($title === '') {
                $err = t('videos.error.title_manual');
                $needTitle = true;
            } elseif (mb_strlen($title) > Video::TITLE_MAX) {
                $err = t('videos.error.title_long', ['max' => Video::TITLE_MAX]);
                $needTitle = true;
            }
        }
        if ($err !== null) {
            return $this->render(422, ['ytError' => $err, 'ytUrl' => $link, 'ytTitle' => $title, 'needTitle' => $needTitle]);
        }
        $vid = Video::createYoutube((string)$id, $title, '', (int)$user['id']);
        Audit::log((int)$user['id'], 'videos.create', 'videos', $vid);
        Flash::set('success', 'videos.flash.added');
        redirect(admin_url('/videos'));
    }

    // ---------- загрузка файла частями (JSON) ----------

    public function uploadInit(array $params = []): string
    {
        $user = $this->currentUser();
        return $this->upload(fn() => (new VideoUploader())->init(self::post('name'), (int)self::post('size'), (int)$user['id']));
    }

    public function uploadStatus(array $params = []): string
    {
        $user = $this->currentUser();
        return $this->upload(fn() => (new VideoUploader())->status(self::post('upload_id'), (int)$user['id']));
    }

    public function uploadChunk(array $params = []): string
    {
        $user = $this->currentUser();
        return $this->upload(fn() => (new VideoUploader())->chunk(
            self::post('upload_id'),
            (int)self::post('index'),
            is_array($_FILES['chunk'] ?? null) ? $_FILES['chunk'] : null,
            (int)$user['id']
        ));
    }

    public function uploadFinish(array $params = []): string
    {
        $user = $this->currentUser();
        $media = null;
        return $this->upload(function () use ($user, &$media): array {
            $queue = Ffmpeg::available();
            $res = (new VideoUploader())->finish(self::post('upload_id'), (int)$user['id'], $queue ? 'processing' : 'ready');
            $media = $res['media'];
            $title = mb_substr(trim(self::post('title')), 0, Video::TITLE_MAX);
            if ($title === '') {
                $title = trim((string)preg_replace('/\.[^.]+$/', '', $res['name']));
            }
            if ($title === '') {
                $title = t('videos.default_title');
            }
            try {
                $vid = Video::createUpload($title, (int)$media['id'], $res['duration'], $queue ? 'processing' : 'ready', (int)$user['id']);
            } catch (\Throwable $e) {
                Media::delete((int)$media['id']);
                throw $e;
            }
            Audit::log((int)$user['id'], 'videos.upload', 'videos', $vid);
            Flash::set('success', $queue ? 'videos.flash.processing' : 'videos.flash.uploaded');
            return ['ok' => true, 'id' => $vid, 'redirect' => admin_url('/videos')];
        });
    }

    /** Общая обёртка JSON-ответов загрузки: понятные ошибки вместо стека. */
    private function upload(callable $fn): string
    {
        try {
            return Json::respond(200, ['ok' => true] + $fn());
        } catch (UploadException $e) {
            return Json::respond($e->status(), ['ok' => false, 'code' => $e->errorCode(), 'message' => $e->userMessage()]);
        } catch (\Throwable $e) {
            ErrorHandler::report($e);
            return Json::respond(500, ['ok' => false, 'code' => 'server', 'message' => t('media.error.network')]);
        }
    }

    // ---------- действия над видео ----------

    public function update(array $params): string
    {
        $user = $this->currentUser();
        $video = $this->mustFind($params);
        $prep = Video::prepare($_POST);
        if ($prep['errors']) {
            return $this->render(422, ['edit' => (int)$video['id'], 'editErrors' => $prep['errors'], 'editOld' => $_POST]);
        }
        Video::update((int)$video['id'], $prep['data']);
        Audit::log((int)$user['id'], 'videos.update', 'videos', (int)$video['id']);
        Flash::set('success', 'videos.flash.saved');
        redirect(admin_url('/videos'));
    }

    public function publish(array $params): string
    {
        $user = $this->currentUser();
        $video = $this->mustFind($params);
        $on = !empty($_POST['is_published']);
        Video::setPublished((int)$video['id'], $on);
        Audit::log((int)$user['id'], $on ? 'videos.publish' : 'videos.unpublish', 'videos', (int)$video['id']);
        Flash::set('success', $on ? 'videos.flash.shown' : 'videos.flash.hidden');
        redirect(admin_url('/videos'));
    }

    public function move(array $params): string
    {
        $user = $this->currentUser();
        $video = $this->mustFind($params);
        if (Video::move((int)$video['id'], self::post('dir') === 'up' ? -1 : 1)) {
            Audit::log((int)$user['id'], 'videos.move', 'videos', (int)$video['id']);
            Flash::set('success', 'videos.flash.moved');
        }
        redirect(admin_url('/videos'));
    }

    public function delete(array $params): string
    {
        $user = $this->currentUser();
        $row = Video::delete((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        Audit::log((int)$user['id'], 'videos.delete', 'videos', (int)$row['id']);
        Flash::set('success', 'videos.flash.deleted');
        redirect(admin_url('/videos'));
    }

    private function mustFind(array $params): array
    {
        $v = Video::find((int)($params['id'] ?? 0));
        if ($v === null) {
            abort(404);
        }
        return $v;
    }

    private function render(int $status, array $extra = []): string
    {
        $max = Media::maxUploadBytes();
        $upload = [
            'url' => admin_url('/media/upload'), 'maxBytes' => $max,
            'maxSide' => (int)config('media.client_max_side', 2560),
            'quality' => (float)config('media.client_quality', 0.85), 'csrf' => csrf_token(),
        ];
        $mfBase = ['upload' => $upload, 'pickerUrl' => admin_url('/media/picker'), 'maxLabel' => format_bytes($max)];
        return $this->page('videos/index', t('videos.title'), [
            $this->home(),
            ['label' => t('videos.title'), 'url' => null],
        ], $extra + [
            'videos'    => Video::all(),
            'edit'      => 0,
            'ytError'   => null, 'ytUrl' => '', 'ytTitle' => '', 'needTitle' => false,
            'editErrors' => [], 'editOld' => [],
            'maxLabel'  => format_bytes(VideoUploader::maxBytes()),
            'ffmpeg'    => Ffmpeg::available(),
            'mfBase'    => $mfBase,
            'base'      => admin_url('/videos'),
        ], $status);
    }
}
