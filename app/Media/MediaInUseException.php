<?php
declare(strict_types=1);

namespace App\Media;

final class MediaInUseException extends \RuntimeException
{
    /** @param array<int,array{source:string,label:string,url:string}> $usages */
    public function __construct(private array $usages)
    {
        parent::__construct('Файл используется');
    }

    public function usages(): array
    {
        return $this->usages;
    }
}
