<?php
declare(strict_types=1);

namespace App\Core;

/** Ошибка загрузки с кодом для JSON-ответа и HTTP-статусом. */
final class UploadException extends \RuntimeException
{
    /** @param array<string,string|int> $params подстановки для текста media.error.<code> */
    public function __construct(private string $errorCode, private int $status, private array $params = [])
    {
        parent::__construct($errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function params(): array
    {
        return $this->params;
    }

    public function userMessage(): string
    {
        return t('media.error.' . $this->errorCode, $this->params);
    }
}
