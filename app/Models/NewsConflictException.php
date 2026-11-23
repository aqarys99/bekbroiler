<?php
declare(strict_types=1);

namespace App\Models;

/** Версия новости в форме не совпадает с версией в БД (оптимистическая блокировка). */
final class NewsConflictException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Конфликт версий новости');
    }
}
