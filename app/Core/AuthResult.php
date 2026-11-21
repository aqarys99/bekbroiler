<?php
declare(strict_types=1);

namespace App\Core;

final class AuthResult
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const BLOCKED = 'blocked';
    public const LOCKED = 'locked';

    public function __construct(
        public readonly string $status,
        public readonly ?int $userId = null,
        public readonly int $minutes = 0,
    ) {
    }

    public function ok(): bool
    {
        return $this->status === self::OK;
    }
}
