<?php
declare(strict_types=1);

namespace App\Core;

/** Ошибка установки; $messageKey — ключ перевода для пользователя. */
final class InstallException extends \RuntimeException
{
    public function __construct(private string $messageKey, private int $status = 422, ?\Throwable $previous = null)
    {
        parent::__construct($messageKey, 0, $previous);
    }

    public function messageKey(): string
    {
        return $this->messageKey;
    }

    public function status(): int
    {
        return $this->status;
    }
}
