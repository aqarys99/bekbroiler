<?php
declare(strict_types=1);

namespace App\Media;

use App\Core\Config;

/** Только APP_ENV=testing: использования из storage/test_media_usages.json. */
final class TestUsageProvider implements UsageProvider
{
    public static function file(): string
    {
        return Config::storageDir() . '/test_media_usages.json';
    }

    public function key(): string
    {
        return 'test';
    }

    public function find(array $mediaIds): array
    {
        $file = self::file();
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        if (!is_array($data)) {
            return [];
        }
        if (isset($data['__throw'])) {
            throw new \RuntimeException('Тестовый провайдер: сбой');
        }
        $out = [];
        foreach ($mediaIds as $id) {
            foreach ((array)($data[(string)$id] ?? []) as $u) {
                if (is_array($u) && isset($u['label'])) {
                    $out[$id][] = ['label' => (string)$u['label'], 'url' => (string)($u['url'] ?? '#')];
                }
            }
        }
        return $out;
    }
}
