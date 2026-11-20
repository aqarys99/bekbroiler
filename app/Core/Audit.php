<?php
declare(strict_types=1);

namespace App\Core;

final class Audit
{
    public static function log(?int $userId, string $action, string $entity, ?int $entityId = null): void
    {
        Database::insert('audit_log', [
            'user_id'    => $userId,
            'action'     => $action,
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
