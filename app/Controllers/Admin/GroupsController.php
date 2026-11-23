<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Group;
use App\Models\Staff;

final class GroupsController extends SectionController
{
    protected const KEY = 'groups';
    protected const MODEL = Group::class;

    protected function listRows(): array
    {
        return Group::allWithTeachers();
    }

    protected function extras(?array $row, bool $form = true): array
    {
        if (!$form) {
            return [];
        }
        return ['staffOptions' => Staff::options()];
    }

    protected function valuesOf(array $row): array
    {
        return $row + ['teacher_ids' => Group::teacherIds((int)$row['id'])];
    }

    protected function afterSave(int $id, array $prep): void
    {
        Group::saveTeachers($id, $prep['teachers'] ?? []);
    }
}
