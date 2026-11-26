<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Database;
use App\Core\DiskUsage;
use App\Models\Checklist;
use App\Models\News;

/** Главная админки: только чтение (фича 08). */
final class DashboardController extends BaseController
{
    private const CHECK_LIMIT = 6;

    public function index(array $params = []): string
    {
        $user = $this->currentUser();
        $check = Checklist::status();

        // проблемные пункты первыми (нет файла, истёк, истекает), затем готовые
        $order = ['missing' => 0, 'expired' => 1, 'warn' => 2, 'ok' => 3];
        $items = $check['items'];
        usort($items, static fn(array $a, array $b): int => $order[$a['state']] <=> $order[$b['state']]);
        foreach ($items as &$it) {
            $it['date'] = $it['valid_until'] !== '' ? date('d.m.Y', (int)strtotime($it['valid_until'] . ' UTC')) : '';
            $it['url'] = DocumentsController::uploadUrl($it['key']);
            if ($it['state'] === 'warn' || $it['state'] === 'expired') {
                $it['url'] = admin_url('/documents');
            }
        }
        unset($it);
        $shown = array_slice($items, 0, self::CHECK_LIMIT);

        $tr = $this->translation();
        $disk = DiskUsage::summary();

        $total = $check['total'];
        return $this->page('dashboard/index', t('menu.dashboard'), [['label' => t('menu.dashboard'), 'url' => null]], [
            'check'     => ['done' => $check['done'], 'total' => $total, 'percent' => $total > 0 ? (int)round($check['done'] * 100 / $total) : 0],
            'checkRows' => $shown,
            'trans'     => $tr,
            'disk'      => $disk + [
                'percent' => $disk['total'] ? min(100, (int)round($disk['used'] * 100 / $disk['total'])) : 0,
                'by'      => DiskUsage::byType(),
            ],
            'latest'    => News::latest(3),
            'actions'   => $this->actions($user),
        ]);
    }

    /** @return array{total:int,translated:int,percent:int} */
    private function translation(): array
    {
        $n = News::translationStats();
        $total = $n['total'];
        $done = $n['translated'];
        foreach (['documents', 'albums', 'videos'] as $table) {
            $r = Database::fetch(
                "SELECT COUNT(*) AS total,
                        COALESCE(SUM(CASE WHEN TRIM(title_kk) <> '' AND TRIM(title_kk) <> TRIM(title) THEN 1 ELSE 0 END), 0) AS done
                 FROM $table"
            );
            $total += (int)($r['total'] ?? 0);
            $done += (int)($r['done'] ?? 0);
        }
        return ['total' => $total, 'translated' => $done, 'percent' => $total > 0 ? (int)round($done * 100 / $total) : 0];
    }

    /** @return array<int,array{key:string,label:string,url:string,icon:string}> */
    private function actions(array $user): array
    {
        $all = [
            ['key' => 'photo', 'label' => 'dashboard.action.photo', 'url' => '/gallery', 'icon' => 'image', 'role' => 'editor'],
            ['key' => 'document', 'label' => 'dashboard.action.document', 'url' => '/documents/new', 'icon' => 'doc', 'role' => 'editor'],
            ['key' => 'video', 'label' => 'dashboard.action.video', 'url' => '/videos', 'icon' => 'video', 'role' => 'editor'],
            ['key' => 'news', 'label' => 'dashboard.action.news', 'url' => '/news/new', 'icon' => 'news', 'role' => 'editor'],
        ];
        $out = [];
        foreach ($all as $a) {
            if ($a['role'] === 'owner' && ($user['role'] ?? '') !== 'owner') {
                continue;
            }
            $out[] = ['key' => $a['key'], 'label' => t($a['label']), 'url' => admin_url($a['url']), 'icon' => $a['icon']];
        }
        return $out;
    }
}
