<?php
declare(strict_types=1);

namespace App\Core;

final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors поле => ключ перевода */
    public function __construct(private array $errors)
    {
        parent::__construct('Validation failed');
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
