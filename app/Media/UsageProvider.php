<?php
declare(strict_types=1);

namespace App\Media;

/** Потребитель медиа (новости, альбомы, ...). Регистрируется в app/media_usages.php. */
interface UsageProvider
{
    public function key(): string;

    /**
     * Один запрос на все id.
     * @param int[] $mediaIds
     * @return array<int,array<int,array{label:string,url:string}>> [media_id => [[label, url], ...]]
     */
    public function find(array $mediaIds): array;
}
