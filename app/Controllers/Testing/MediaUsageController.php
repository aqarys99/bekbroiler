<?php
declare(strict_types=1);

namespace App\Controllers\Testing;

use App\Media\TestUsageProvider;

/** Только APP_ENV=testing: задать использования файлов тестовым провайдером. */
final class MediaUsageController
{
    public function set(array $params = []): string
    {
        $file = TestUsageProvider::file();
        $body = trim((string)file_get_contents('php://input'));
        $data = $body !== '' ? json_decode($body, true) : null;
        if (is_array($data) && $data !== []) {
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));
        } elseif (is_file($file)) {
            @unlink($file);
        }
        http_response_code(204);
        return '';
    }
}
