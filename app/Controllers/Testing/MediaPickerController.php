<?php
declare(strict_types=1);

namespace App\Controllers\Testing;

use App\Controllers\Admin\BaseController;
use App\Core\DiskUsage;
use App\Models\Media;

/** Только APP_ENV=testing: страница с двумя полями компонента выбора из медиатеки. */
final class MediaPickerController extends BaseController
{
    public function show(array $params = []): string
    {
        return $this->render(Media::parseIds($_GET['cover'] ?? null), Media::parseIds($_GET['photos'] ?? null));
    }

    public function echo(array $params = []): string
    {
        // несуществующие id отбрасываются (значение очищается при сохранении формы)
        $cover = array_values(array_filter(Media::pickerValues(Media::parseIds($_POST['cover_media_id'] ?? null)), static fn($v) => $v['media'] !== null));
        $photos = array_values(array_filter(Media::pickerValues(Media::parseIds($_POST['photos'] ?? null)), static fn($v) => $v['media'] !== null));
        return $this->page('testing/media-picker', 'Тест выбора фото', [
            $this->home(),
            ['label' => 'Тест выбора фото', 'url' => null],
        ], $this->data(array_column($cover, 'id'), array_column($photos, 'id')) + [
            'result' => [
                'cover_media_id' => array_column($cover, 'id'),
                'photos'         => array_column($photos, 'id'),
            ],
        ]);
    }

    private function render(array $cover, array $photos): string
    {
        return $this->page('testing/media-picker', 'Тест выбора фото', [
            $this->home(),
            ['label' => 'Тест выбора фото', 'url' => null],
        ], $this->data($cover, $photos) + ['result' => null]);
    }

    private function data(array $cover, array $photos): array
    {
        $max = Media::maxUploadBytes();
        return [
            'coverValues'  => Media::pickerValues($cover),
            'photosValues' => Media::pickerValues($photos),
            'maxLabel'     => format_bytes($max),
            'upload'       => [
                'url' => admin_url('/media/upload'), 'maxBytes' => $max,
                'maxSide' => (int)config('media.client_max_side', 2560),
                'quality' => (float)config('media.client_quality', 0.85),
                'csrf' => csrf_token(),
            ],
            'pickerUrl'    => admin_url('/media/picker'),
        ];
    }
}
