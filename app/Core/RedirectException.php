<?php
declare(strict_types=1);

namespace App\Core;

/** Бросается redirect() только в консоли (тесты); в вебе redirect() завершает запрос. */
final class RedirectException extends \RuntimeException
{
    public function __construct(public readonly string $url, public readonly int $status = 302)
    {
        parent::__construct('Redirect to ' . $url, $status);
    }
}
