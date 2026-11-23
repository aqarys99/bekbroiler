<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Audit;
use App\Core\DiskUsage;
use App\Core\Flash;
use App\Core\SettingsValidator;
use App\Core\SiteCache;
use App\Models\Media;
use App\Models\Settings;

final class SettingsController extends BaseController
{
    private const TEXT_KEYS = [
        'site_name', 'full_name', 'full_name_kk', 'director', 'director_kk', 'founded_year', 'capacity', 'work_hours',
        'license_number', 'license_date', 'bin', 'disk_quota_mb', 'theme', 'default_lang', 'address', 'address_kk',
        'email', 'whatsapp', 'instagram', 'facebook', 'telegram', 'twogis',
    ];

    public function show(array $params = []): string
    {
        $f = [];
        foreach (self::TEXT_KEYS as $k) {
            $f[$k] = (string)Settings::get($k, '');
        }
        $f['site_name'] = (string)Settings::get('site_name', t('app.name'));
        $f['theme'] = (string)Settings::get('theme', 'sun');
        $f['default_lang'] = (string)Settings::get('default_lang', 'ru');
        $f['teach_langs'] = (array)Settings::get('teach_langs', ['ru']);
        $f['phones'] = implode("\n", (array)Settings::get('phones', []));
        $f['color'] = (string)Settings::get('color', SettingsValidator::COLORS[0]);
        $f['kk_enabled'] = (bool)Settings::get('kk_enabled', false);
        $f['a11y_enabled'] = (bool)Settings::get('a11y_enabled', true);
        $f['map_code'] = ($src = (string)Settings::get('map_src', '')) !== '' ? '<iframe src="' . $src . '"></iframe>' : '';
        $f['logo_media_id'] = (int)Settings::get('logo_media_id', 0) ?: null;
        $f['hero_media_id'] = (int)Settings::get('hero_media_id', 0) ?: null;
        return $this->render(200, $f, []);
    }

    public function save(array $params = []): string
    {
        $user = $this->currentUser();
        $res = SettingsValidator::validate($_POST);
        if ($res['errors']) {
            $f = $res['values'];
            $f['phones'] = implode("\n", $f['phones']);
            $f['map_code'] = is_string($_POST['map_code'] ?? null) ? $_POST['map_code'] : '';
            return $this->render(422, $f, $res['errors']);
        }
        $values = $res['values'];
        $values['disk_quota_mb'] = $values['disk_quota_mb'] === '' ? null : (float)$values['disk_quota_mb'];
        Settings::setMany($values);
        Audit::log((int)$user['id'], 'settings.update', 'settings');
        SiteCache::flush();
        Flash::set('success', 'settings.flash.saved');
        redirect(admin_url('/settings'));
    }

    /** @param array<string,mixed> $f @param array<string,string> $errors */
    private function render(int $status, array $f, array $errors): string
    {
        $max = Media::maxUploadBytes();
        $field = static fn(string $name, string $label, ?int $sel): array => [
            'name' => $name, 'label' => $label, 'multiple' => false,
            'values' => Media::pickerValues($sel !== null ? [$sel] : []),
            'upload' => [
                'url' => admin_url('/media/upload'), 'maxBytes' => $max,
                'maxSide' => (int)config('media.client_max_side', 2560),
                'quality' => (float)config('media.client_quality', 0.85), 'csrf' => csrf_token(),
            ],
            'pickerUrl' => admin_url('/media/picker'), 'maxLabel' => format_bytes($max),
        ];
        $first = array_key_first($errors);
        $tabOf = ['site_name' => 'main', 'full_name' => 'main', 'full_name_kk' => 'main', 'director' => 'main', 'director_kk' => 'main',
            'founded_year' => 'main', 'capacity' => 'main', 'work_hours' => 'main', 'license_number' => 'main', 'license_date' => 'main',
            'bin' => 'main', 'disk_quota_mb' => 'main', 'theme' => 'look', 'color' => 'look', 'logo_media_id' => 'look',
            'hero_media_id' => 'look', 'default_lang' => 'look'];
        $errTab = 'main';
        foreach (array_keys($errors) as $k) {
            $errTab = $tabOf[$k] ?? 'contacts';
            break;
        }
        return $this->page('settings/index', t('settings.title'), [
            $this->home(),
            ['label' => t('settings.title'), 'url' => null],
        ], [
            'f' => $f,
            'errors' => $errors,
            'activeTab' => $errors ? $errTab : 'main',
            'diskLine' => DiskUsage::line(DiskUsage::summary()),
            'logoField' => $field('logo_media_id', t('settings.logo'), $f['logo_media_id'] ?? null),
            'heroField' => $field('hero_media_id', t('settings.hero'), $f['hero_media_id'] ?? null),
            'headerAction' => ['form' => 'settings-form', 'label' => t('settings.save'), 'testid' => 'settings-save'],
        ], $status);
    }
}
