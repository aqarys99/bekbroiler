<?php
declare(strict_types=1);

namespace App\Controllers\Testing;

use App\Core\Clock;

/** Только APP_ENV=testing: подмена текущего времени (UTC) через storage/test_clock.json. */
final class ClockController
{
    public function set(array $params = []): string
    {
        $file = Clock::testFile();
        $body = trim((string)file_get_contents('php://input'));
        $data = $body !== '' ? json_decode($body, true) : null;
        if (is_array($data) && is_string($data['now'] ?? null)) {
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
            file_put_contents($file, json_encode(['now' => $data['now']]));
        } elseif (is_file($file)) {
            @unlink($file);
        }
        http_response_code(204);
        return '';
    }
}
