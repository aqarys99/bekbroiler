<?php
declare(strict_types=1);

namespace App\Media;

/** Один из потребителей не смог ответить: считаем файл используемым. */
final class UsageCheckException extends \RuntimeException
{
}
