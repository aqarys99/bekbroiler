<?php
declare(strict_types=1);

namespace App\Media;

use App\Models\Settings;

/** Настройки как потребитель медиа: логотип и картинка главной (I-02, I-25). */
final class SettingsUsageProvider implements UsageProvider
{
    public function key(): string
    {
        return 'settings';
    }

    public function find(array $mediaIds): array
    {
        $out = [];
        foreach (['logo_media_id' => 'settings.usage.logo', 'hero_media_id' => 'settings.usage.hero'] as $key => $label) {
            $id = (int)Settings::get($key, 0);
            if ($id > 0 && in_array($id, array_map('intval', $mediaIds), true)) {
                $out[$id][] = ['label' => t($label), 'url' => admin_url('/settings')];
            }
        }
        return $out;
    }
}
