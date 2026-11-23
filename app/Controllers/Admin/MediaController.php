<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\DiskUsage;
use App\Core\ErrorHandler;
use App\Core\Flash;
use App\Core\Json;
use App\Core\UploadException;
use App\Core\Uploader;
use App\Models\Media;
use App\Media\MediaInUseException;
use App\Media\UsageCheckException;

final class MediaController extends BaseController
{
    public function index(array $params = []): string
    {
        $q = Media::cleanQuery($_GET['q'] ?? '');
        $type = Media::normalizeType($_GET['type'] ?? 'all');
        $page = self::intParam($_GET['page'] ?? 1);
        $list = Media::list($q, $type, $page);
        $ids = array_map(static fn(array $r): int => (int)$r['id'], $list['items']);
        $disk = DiskUsage::summary();
        $max = Media::maxUploadBytes();

        return $this->page('media/index', t('media.title'), [
            $this->home(),
            ['label' => t('media.title'), 'url' => null],
        ], [
            'items'      => $list['items'],
            'counts'     => Media::usageCounts($ids),
            'q'          => $q,
            'type'       => $type,
            'types'      => Media::availableTypes(),
            'page'       => $list['page'],
            'pages'      => $list['pages'],
            'total'      => $list['total'],
            'hasFilter'  => $q !== '' || $type !== 'all',
            'disk'       => $disk,
            'diskLine'   => DiskUsage::line($disk),
            'maxBytes'   => $max,
            'maxLabel'   => format_bytes($max),
            'upload'     => self::uploadConfig($max),
        ]);
    }

    public function upload(array $params = []): string
    {
        $user = $this->currentUser();
        $row = null;
        try {
            $row = (new Uploader())->handle(is_array($_FILES['file'] ?? null) ? $_FILES['file'] : null, (int)$user['id']);
            Audit::log((int)$user['id'], 'media.upload', 'media', (int)$row['id']);
        } catch (UploadException $e) {
            return Json::respond($e->status(), ['ok' => false, 'code' => $e->errorCode(), 'message' => $e->userMessage()]);
        } catch (\Throwable $e) {
            ErrorHandler::report($e);
            if ($row !== null) {
                try {
                    Media::delete((int)$row['id']);
                } catch (\Throwable) {
                    // уборка по возможности
                }
            }
            return Json::respond(500, ['ok' => false, 'code' => 'server', 'message' => t('media.error.network')]);
        }
        $item = Media::pickerItem($row);
        $large = media_variants($row)['large'] ?? [];
        return Json::respond(201, [
            'ok'    => true,
            'media' => [
                'id'            => $item['id'],
                'original_name' => $item['original_name'],
                'source_size'   => (int)$row['source_size'],
                'result_size'   => (int)($large['webp_bytes'] ?? 0),
                'width'         => $item['width'],
                'height'        => $item['height'],
                'thumb_webp'    => $item['thumb_webp'],
                'thumb_jpg'     => $item['thumb_jpg'],
                'url'           => admin_url('/media/' . $item['id']),
                'large_url'     => media_url($row, 'large', 'webp'),
                'alt'           => $item['alt'],
                'used'          => 0,
            ],
            'disk'  => self::diskJson(),
        ]);
    }

    public function show(array $params): string
    {
        return $this->renderShow($this->mustFind($params), 200, [], []);
    }

    public function update(array $params): string
    {
        $row = $this->mustFind($params);
        $user = $this->currentUser();
        $alt = self::cleanAlt(self::post('alt'));
        if (mb_strlen($alt) > 300) {
            return $this->renderShow($row, 422, ['alt' => t('media.alt.too_long')], ['alt' => $alt]);
        }
        Media::updateAlt((int)$row['id'], $alt);
        Audit::log((int)$user['id'], 'media.update', 'media', (int)$row['id']);
        Flash::set('success', 'media.saved');
        redirect(admin_url('/media/' . (int)$row['id']) . self::backQuery());
    }

    public function delete(array $params): string
    {
        $row = $this->mustFind($params);
        $user = $this->currentUser();
        $id = (int)$row['id'];
        try {
            Media::delete($id, static fn(array $r) => Audit::log((int)$user['id'], 'media.delete', 'media', (int)$r['id']));
        } catch (MediaInUseException $e) {
            $list = implode(', ', array_map(static fn(array $u): string => $u['label'], $e->usages()));
            Flash::set('error', 'media.delete.in_use', ['list' => $list !== '' ? $list : t('media.usages.title')]);
            redirect(admin_url('/media/' . $id) . self::backQuery());
        } catch (UsageCheckException) {
            Flash::set('error', 'media.usages.failed');
            redirect(admin_url('/media/' . $id) . self::backQuery());
        }
        Flash::set('success', 'media.deleted');
        redirect(self::backUrl());
    }

    /** JSON для модального окна выбора: только фото. */
    public function picker(array $params = []): string
    {
        $list = Media::list(Media::cleanQuery($_GET['q'] ?? ''), 'image', self::intParam($_GET['page'] ?? 1));
        return Json::respond(200, [
            'items' => array_map([Media::class, 'pickerItem'], $list['items']),
            'page'  => $list['page'],
            'pages' => $list['pages'],
        ]);
    }

    private function renderShow(array $row, int $status, array $errors, array $old): string
    {
        $usages = [];
        $failed = false;
        try {
            $usages = Media::usages((int)$row['id']);
        } catch (UsageCheckException) {
            $failed = true;
        }
        $root = DiskUsage::uploadsDir();
        $missing = false;
        foreach (Media::filePaths($row) as $rel) {
            if (!is_file($root . '/' . $rel)) {
                $missing = true;
                break;
            }
        }
        $author = $row['uploaded_by'] !== null
            ? \App\Core\Database::fetchValue('SELECT name FROM users WHERE id = ?', [(int)$row['uploaded_by']])
            : null;

        return $this->page('media/show', t('media.title'), [
            $this->home(),
            ['label' => t('media.title'), 'url' => admin_url('/media')],
            ['label' => (string)$row['original_name'], 'url' => null],
        ], [
            'media'        => $row,
            'variants'     => media_variants($row),
            'usages'       => $usages,
            'usagesFailed' => $failed,
            'inUse'        => $failed || $usages !== [],
            'missingFiles' => $missing,
            'author'       => $author !== null ? (string)$author : null,
            'backUrl'      => self::backUrl(),
            'backParam'    => self::backParam(),
            'errors'       => $errors,
            'old'          => $old,
        ], $status);
    }

    private function mustFind(array $params): array
    {
        $row = Media::find((int)($params['id'] ?? 0));
        if ($row === null) {
            abort(404);
        }
        return $row;
    }

    private static function cleanAlt(string $s): string
    {
        return trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $s) ?? '');
    }

    private static function intParam(mixed $v): int
    {
        return is_string($v) && preg_match('/^\d{1,9}$/', $v) ? max(1, (int)$v) : 1;
    }

    private static function uploadConfig(int $max): array
    {
        return [
            'url'        => admin_url('/media/upload'),
            'maxBytes'   => $max,
            'maxSide'    => (int)config('media.client_max_side', 2560),
            'quality'    => (float)config('media.client_quality', 0.85),
            'csrf'       => csrf_token(),
        ];
    }

    private static function diskJson(): array
    {
        $d = DiskUsage::summary();
        return $d + ['line' => DiskUsage::line($d)];
    }

    /** Допустимый ?back=: только путь внутри админки /media. */
    private static function backParam(): string
    {
        $b = $_GET['back'] ?? $_POST['back'] ?? '';
        if (!is_string($b) || $b === '') {
            return '';
        }
        $safe = \App\Core\Guard::safeNext($b);
        $path = explode('?', $safe, 2)[0];
        $media = admin_url('/media');
        return $safe === $b && ($path === $media) ? $b : '';
    }

    private static function backUrl(): string
    {
        $b = self::backParam();
        return $b !== '' ? $b : admin_url('/media');
    }

    private static function backQuery(): string
    {
        $b = self::backParam();
        return $b !== '' ? '?back=' . rawurlencode($b) : '';
    }
}
