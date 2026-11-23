<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;

/** Журнал действий (только owner). Читает audit_log; паролей и токенов там нет по построению. */
final class AuditController extends BaseController
{
    public const PER_PAGE = 30;

    public function index(array $params = []): string
    {
        $user = self::intParam($_GET['user'] ?? null);
        $entity = is_string($_GET['entity'] ?? null) ? substr($_GET['entity'], 0, 40) : '';
        $from = self::dateParam($_GET['from'] ?? null);
        $to = self::dateParam($_GET['to'] ?? null);
        $page = max(1, self::intParam($_GET['page'] ?? null));

        $where = [];
        $args = [];
        if ($user > 0) {
            $where[] = 'a.user_id = ?';
            $args[] = $user;
        }
        if ($entity !== '') {
            $where[] = 'a.entity = ?';
            $args[] = $entity;
        }
        if ($from !== '') {
            $where[] = 'a.created_at >= ?';
            $args[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where[] = 'a.created_at <= ?';
            $args[] = $to . ' 23:59:59';
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int)Database::fetchValue('SELECT COUNT(*) FROM audit_log a' . $w, $args);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $rows = Database::fetchAll(
            'SELECT a.id, a.user_id, a.action, a.entity, a.entity_id, a.created_at, u.name AS user_name, u.login AS user_login
             FROM audit_log a LEFT JOIN users u ON u.id = a.user_id' . $w . '
             ORDER BY a.id DESC LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $args
        );
        foreach ($rows as &$r) {
            $r['label'] = self::label((string)$r['action'], (string)$r['entity']);
            $r['when'] = self::when((string)$r['created_at']);
            $r['who'] = $r['user_name'] !== null ? (string)$r['user_name'] : t('audit.system');
        }
        unset($r);

        return $this->page('audit/index', t('audit.title'), [$this->home(), ['label' => t('audit.title'), 'url' => null]], [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'users'    => Database::fetchAll('SELECT id, name FROM users ORDER BY name'),
            'entities' => array_map(static fn($e) => ['key' => (string)$e, 'label' => self::entityLabel((string)$e)],
                array_column(Database::fetchAll('SELECT DISTINCT entity AS e FROM audit_log ORDER BY entity'), 'e')),
            'f'        => ['user' => $user, 'entity' => $entity, 'from' => $from, 'to' => $to],
        ]);
    }

    /** Русская подпись действия; неизвестное действие показываем как есть (без ключа). */
    public static function label(string $action, string $entity = ''): string
    {
        foreach (['audit.' . $action, 'audit.' . $entity . '.' . $action] as $k) {
            $s = t($k);
            if ($s !== $k) {
                return $s;
            }
        }
        return $action;
    }

    public static function entityLabel(string $entity): string
    {
        $s = t('audit.entity.' . $entity);
        return $s !== 'audit.entity.' . $entity ? $s : $entity;
    }

    private static function when(string $utc): string
    {
        try {
            $d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
            return $d->setTimezone(new \DateTimeZone('Asia/Almaty'))->format('d.m.Y H:i');
        } catch (\Throwable $e) {
            return $utc;
        }
    }

    private static function intParam(mixed $v): int
    {
        return is_string($v) && ctype_digit($v) && strlen($v) < 10 ? (int)$v : 0;
    }

    private static function dateParam(mixed $v): string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : '';
    }
}
