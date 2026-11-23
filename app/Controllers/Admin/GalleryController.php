<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\Json;
use App\Core\View;
use App\Models\Album;
use App\Models\Media;

final class GalleryController extends BaseController
{
    public function index(array $params = []): string
    {
        return $this->page('gallery/index', t('gallery.title'), [
            $this->home(),
            ['label' => t('gallery.title'), 'url' => null],
        ], ['albums' => Album::all()]);
    }

    public function create(array $params = []): string
    {
        return $this->renderNew(200, ['title' => '', 'title_kk' => '', 'description' => '', 'description_kk' => '', 'event_date' => '', 'is_published' => 1], []);
    }

    public function store(array $params = []): string
    {
        $user = $this->currentUser();
        $prep = Album::prepare($_POST);
        if ($prep['errors']) {
            return $this->renderNew(422, $this->oldValues(), $prep['errors']);
        }
        $id = Album::create($prep['data'], (int)$user['id']);
        Audit::log((int)$user['id'], 'gallery.create', 'albums', $id);
        Flash::set('success', 'gallery.flash.created');
        redirect(admin_url('/gallery/' . $id));
    }

    public function show(array $params): string
    {
        return $this->renderShow($this->mustFind($params), 200, [], null);
    }

    public function update(array $params): string
    {
        $user = $this->currentUser();
        $album = $this->mustFind($params);
        $prep = Album::prepare($_POST);
        if ($prep['errors']) {
            return $this->renderShow($album, 422, $prep['errors'], $this->oldValues());
        }
        Album::update((int)$album['id'], $prep['data']);
        Audit::log((int)$user['id'], 'gallery.update', 'albums', (int)$album['id']);
        Flash::set('success', 'gallery.flash.saved');
        redirect(admin_url('/gallery/' . (int)$album['id']));
    }

    public function publish(array $params): string
    {
        $user = $this->currentUser();
        $album = $this->mustFind($params);
        $on = !empty($_POST['is_published']);
        Album::setPublished((int)$album['id'], $on);
        Audit::log((int)$user['id'], $on ? 'gallery.publish' : 'gallery.unpublish', 'albums', (int)$album['id']);
        Flash::set('success', $on ? 'gallery.flash.shown' : 'gallery.flash.hidden');
        redirect(admin_url('/gallery/' . (int)$album['id']));
    }

    public function delete(array $params): string
    {
        $user = $this->currentUser();
        $row = Album::delete((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        Audit::log((int)$user['id'], 'gallery.delete', 'albums', (int)$row['id']);
        Flash::set('success', 'gallery.flash.deleted');
        redirect(admin_url('/gallery'));
    }

    /** Добавить фото в альбом: из медиатеки (форма) или сразу после загрузки (JSON, возвращает плитки). */
    public function addPhotos(array $params): string
    {
        $user = $this->currentUser();
        $album = $this->mustFind($params);
        $ids = Media::parseIds($_POST['media_ids'] ?? []);
        $added = $ids ? Album::addMedia((int)$album['id'], $ids) : [];
        if ($added) {
            Audit::log((int)$user['id'], 'gallery.add_photos', 'albums', (int)$album['id']);
        }
        if (Json::wanted()) {
            $fresh = Album::find((int)$album['id']);
            $cover = $fresh !== null ? Album::effectiveCover($fresh) : null;
            $tiles = [];
            $rows = Media::findMany($added);
            $photos = Album::photos((int)$album['id']);
            $lastId = $photos ? (int)end($photos)['id'] : 0;
            foreach ($added as $mid) {
                if (!isset($rows[$mid])) {
                    continue;
                }
                $photo = $rows[$mid] + ['caption' => ''];
                $tiles[] = [
                    'id'   => $mid,
                    'html' => View::render('gallery/_photo', [
                        'albumId' => (int)$album['id'], 'p' => $photo, 'isCover' => $cover === $mid, 'first' => false, 'last' => $mid === $lastId,
                    ], null),
                ];
            }
            return Json::respond(200, ['ok' => true, 'added' => count($added), 'cover' => $cover, 'tiles' => $tiles]);
        }
        Flash::set($added ? 'success' : 'error', $added ? 'gallery.flash.added' : 'gallery.flash.nothing_added', ['n' => count($added)]);
        redirect(admin_url('/gallery/' . (int)$album['id']));
    }

    /** Порядок после перетаскивания (JSON). */
    public function order(array $params): string
    {
        $user = $this->currentUser();
        $album = $this->mustFind($params);
        Album::reorder((int)$album['id'], Media::parseIds($_POST['order'] ?? []));
        Audit::log((int)$user['id'], 'gallery.reorder', 'albums', (int)$album['id']);
        return Json::respond(200, ['ok' => true]);
    }

    public function photoAction(array $params): string
    {
        $user = $this->currentUser();
        $album = $this->mustFind($params);
        $aid = (int)$album['id'];
        $mid = (int)($params['mid'] ?? 0);
        $ok = false;
        $flash = 'gallery.flash.photo_missing';
        switch ($params['act'] ?? '') {
            case 'move':
                $ok = Album::move($aid, $mid, self::post('dir') === 'left' ? -1 : 1);
                $flash = 'gallery.flash.moved';
                break;
            case 'cover':
                $ok = Album::setCover($aid, $mid);
                $flash = 'gallery.flash.cover';
                break;
            case 'remove':
                $ok = Album::removeMedia($aid, $mid);
                $flash = 'gallery.flash.removed';
                break;
            case 'caption':
                $cap = mb_substr(trim(self::post('caption')), 0, Album::CAPTION_MAX);
                $ok = Album::setCaption($aid, $mid, $cap);
                $flash = 'gallery.flash.caption';
                break;
            default:
                abort(404);
        }
        if ($ok) {
            Audit::log((int)$user['id'], 'gallery.photo_' . $params['act'], 'albums', $aid);
            Flash::set('success', $flash);
        } elseif ($flash === 'gallery.flash.photo_missing' || ($params['act'] ?? '') !== 'move') {
            Flash::set('error', 'gallery.flash.photo_missing');
        }
        redirect(admin_url('/gallery/' . $aid) . '#gallery-photos');
    }

    // ------------------------------------------------------------------ служебное

    private function renderNew(int $status, array $old, array $errors): string
    {
        return $this->page('gallery/new', t('gallery.new.title'), [
            $this->home(),
            ['label' => t('gallery.title'), 'url' => admin_url('/gallery')],
            ['label' => t('gallery.new.title'), 'url' => null],
        ], ['old' => $old, 'errors' => $errors], $status);
    }

    private function renderShow(array $album, int $status, array $errors, ?array $old): string
    {
        $max = Media::maxUploadBytes();
        $upload = [
            'url' => admin_url('/media/upload'), 'maxBytes' => $max,
            'maxSide' => (int)config('media.client_max_side', 2560),
            'quality' => (float)config('media.client_quality', 0.85), 'csrf' => csrf_token(),
        ];
        $photos = Album::photos((int)$album['id']);
        return $this->page('gallery/show', (string)$album['title'], [
            $this->home(),
            ['label' => t('gallery.title'), 'url' => admin_url('/gallery')],
            ['label' => (string)$album['title'], 'url' => null],
        ], [
            'album'    => $album,
            'photos'   => $photos,
            'cover'    => Album::effectiveCover($album),
            'old'      => $old ?? [
                'title' => $album['title'], 'title_kk' => $album['title_kk'], 'description' => $album['description'],
                'description_kk' => $album['description_kk'], 'event_date' => (string)($album['event_date'] ?? ''),
                'is_published' => (int)$album['is_published'],
            ],
            'errors'   => $errors,
            'editOpen' => $errors !== [],
            'maxLabel' => format_bytes($max),
            'upload'   => $upload,
            'mf'       => ['name' => 'gallery_pick', 'label' => t('gallery.add_library'), 'multiple' => true, 'values' => [],
                           'upload' => $upload, 'pickerUrl' => admin_url('/media/picker'), 'maxLabel' => format_bytes($max)],
        ], $status);
    }

    private function oldValues(): array
    {
        $v = [];
        foreach (['title', 'title_kk', 'description', 'description_kk', 'event_date'] as $k) {
            $v[$k] = self::post($k);
        }
        $v['is_published'] = !empty($_POST['is_published']) ? 1 : 0;
        return $v;
    }

    private function mustFind(array $params): array
    {
        $row = Album::find((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        return $row;
    }
}
