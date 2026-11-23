<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Основа для списков раздела сайта (педагоги, группы, достижения, отзывы): порядок, показ, CRUD.
 * Дочерний класс задаёт TABLE и prepare(); имена колонок берутся только из ключей, которые он сам вернул.
 */
abstract class SectionModel
{
    protected const TABLE = '';

    /** @return array{data:array,errors:array<string,string>} */
    abstract public static function prepare(array $in): array;

    public static function find(int $id): ?array
    {
        return Database::fetch('SELECT * FROM ' . static::TABLE . ' WHERE id = ?', [$id]);
    }

    /** @return array<int,array> */
    public static function all(): array
    {
        return Database::fetchAll('SELECT * FROM ' . static::TABLE . ' ORDER BY sort, id');
    }

    /** @return array<int,array> */
    protected static function publishedRows(): array
    {
        return Database::fetchAll('SELECT * FROM ' . static::TABLE . ' WHERE is_published = 1 ORDER BY sort, id');
    }

    public static function create(array $data): int
    {
        $data['sort'] = (int)Database::fetchValue('SELECT COALESCE(MAX(sort), 0) + 1 FROM ' . static::TABLE);
        return Database::insert(static::TABLE, $data);
    }

    public static function update(int $id, array $data): void
    {
        $set = implode(', ', array_map(static fn(string $c): string => $c . ' = ?', array_keys($data)));
        Database::query('UPDATE ' . static::TABLE . ' SET ' . $set . ' WHERE id = ?', [...array_values($data), $id]);
    }

    public static function setPublished(int $id, bool $on): void
    {
        Database::query('UPDATE ' . static::TABLE . ' SET is_published = ? WHERE id = ?', [$on ? 1 : 0, $id]);
    }

    public static function delete(int $id): ?array
    {
        return Database::immediate(static function () use ($id): ?array {
            $row = static::find($id);
            if ($row === null) {
                return null;
            }
            Database::query('DELETE FROM ' . static::TABLE . ' WHERE id = ?', [$id]);
            return $row;
        });
    }

    /** Сдвиг на одну позицию: -1 вверх, +1 вниз. */
    public static function move(int $id, int $dir): bool
    {
        return (bool)Database::immediate(static function () use ($id, $dir): bool {
            $ids = array_map('intval', array_column(Database::fetchAll('SELECT id FROM ' . static::TABLE . ' ORDER BY sort, id'), 'id'));
            $i = array_search($id, $ids, true);
            $j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
            if ($i === false || $j < 0 || $j >= count($ids)) {
                return false;
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $pos => $rid) {
                Database::query('UPDATE ' . static::TABLE . ' SET sort = ? WHERE id = ?', [$pos + 1, $rid]);
            }
            return true;
        });
    }

    // ------------------------------------------------------------------ помощники проверки

    protected static function str(array $in, string $k): string
    {
        return trim(is_string($in[$k] ?? null) ? (string)$in[$k] : '');
    }

    /** Обязательная/необязательная строка с лимитом длины; ошибка пишется в $err[$k]. */
    protected static function text(array $in, string $k, int $max, array &$err, string $label, bool $required = false): string
    {
        $v = self::str($in, $k);
        if ($required && $v === '') {
            $err[$k] = t('sections.error.required', ['field' => $label]);
        } elseif (mb_strlen($v) > $max) {
            $err[$k] = t('sections.error.long', ['field' => $label, 'max' => $max]);
        }
        return $v;
    }

    /** Целое в диапазоне или null (пусто). */
    protected static function intOrNull(array $in, string $k, int $min, int $max, array &$err, string $label): ?int
    {
        $v = self::str($in, $k);
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^\d{1,6}$/', $v) || (int)$v < $min || (int)$v > $max) {
            $err[$k] = t('sections.error.range', ['field' => $label, 'min' => $min, 'max' => $max]);
            return null;
        }
        return (int)$v;
    }

    /** Одно фото из медиатеки (только изображение) или null. */
    protected static function photoId(array $in, string $k, array &$err): ?int
    {
        $id = Media::parseIds($in[$k] ?? null)[0] ?? null;
        if ($id === null) {
            return null;
        }
        $m = Media::findMany([$id])[$id] ?? null;
        if ($m === null || $m['type'] !== 'image') {
            $err[$k] = t('sections.error.photo');
            return null;
        }
        return $id;
    }
}
