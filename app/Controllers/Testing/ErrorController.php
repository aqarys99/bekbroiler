<?php
declare(strict_types=1);

namespace App\Controllers\Testing;

final class ErrorController
{
    public function throw(array $params = []): never
    {
        throw new \RuntimeException('Test error');
    }
}
