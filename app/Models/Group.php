<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Group extends SectionModel
{
    protected const TABLE = 'groups';

    public static function prepare(array $in): array
    {
        $err = [];
        $teachers = [];
        $known = Staff::options();
        foreach (Media::parseIds($in['teacher_ids'] ?? []) as $id) {
            if (isset($known[$id])) {
                $teachers[] = $id;
            }
        }
        $d = [
            'name'           => self::text($in, 'name', 100, $err, t('groups.field.name'), true),
            'name_kk'        => self::text($in, 'name_kk', 100, $err, t('groups.field.name_kk')),
            'age_range'      => self::text($in, 'age_range', 50, $err, t('groups.field.age')),
            'age_range_kk'   => self::text($in, 'age_range_kk', 50, $err, t('groups.field.age_kk')),
            'children_count' => self::intOrNull($in, 'children_count', 0, 1000, $err, t('groups.field.children')),
            'groups_count'   => self::intOrNull($in, 'groups_count', 1, 100, $err, t('groups.field.count')),
            'is_published'   => !empty($in['is_published']) ? 1 : 0,
        ];
        return ['data' => $d, 'errors' => $err, 'teachers' => $teachers];
    }

    /** @return int[] */
    public static function teacherIds(int $groupId): array
    {
        return array_map('intval', array_column(
            Database::fetchAll('SELECT staff_id FROM group_teachers WHERE group_id = ? ORDER BY staff_id', [$groupId]), 'staff_id'
        ));
    }

    public static function saveTeachers(int $groupId, array $ids): void
    {
        Database::immediate(static function () use ($groupId, $ids): void {
            Database::query('DELETE FROM group_teachers WHERE group_id = ?', [$groupId]);
            foreach (array_unique($ids) as $sid) {
                Database::insert('group_teachers', ['group_id' => $groupId, 'staff_id' => (int)$sid]);
            }
        });
    }

    /** Для списка админки: к каждой группе добавлены teachers (ФИО). */
    public static function allWithTeachers(): array
    {
        return self::withTeachers(self::all());
    }

    /** Для сайта (фича 11): показанные группы по порядку, teachers = показанные педагоги (full_name, position). */
    public static function published(): array
    {
        return self::withTeachers(self::publishedRows(), true);
    }

    private static function withTeachers(array $rows, bool $onlyPublished = false): array
    {
        foreach ($rows as &$r) {
            $r['teachers'] = Database::fetchAll(
                'SELECT s.id, s.full_name, s.full_name_kk, s.position, s.position_kk FROM group_teachers gt JOIN staff s ON s.id = gt.staff_id
                 WHERE gt.group_id = ?' . ($onlyPublished ? ' AND s.is_published = 1' : '') . ' ORDER BY s.sort, s.id',
                [(int)$r['id']]
            );
        }
        unset($r);
        return $rows;
    }
}
