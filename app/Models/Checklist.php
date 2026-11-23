<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Чек-лист аттестации (I-05): для экрана «Документы» и главной (фича 08). */
final class Checklist
{
    /**
     * По каждому пункту: ok | warn (срок < 30 дней или истёк) | missing, плюс итог.
     * Учитываются только опубликованные документы с файлом; лучший по статусу документ определяет пункт.
     * @return array{items:array<int,array>,done:int,total:int,missing:array<int,string>}
     */
    public static function status(?string $today = null): array
    {
        $today ??= Document::today();
        $best = [];
        $rows = Database::fetchAll(
            'SELECT d.id, d.checklist_key, d.valid_until FROM documents d JOIN media m ON m.id = d.media_id
             WHERE d.is_published = 1 AND d.checklist_key IS NOT NULL ORDER BY d.id'
        );
        $rank = ['ok' => 2, 'warn' => 1, 'expired' => 1];
        foreach ($rows as $r) {
            $s = Document::statusOf($r, $today);
            $k = (string)$r['checklist_key'];
            if (!isset($best[$k]) || $rank[$s['state']] > $rank[$best[$k]['state']]) {
                $best[$k] = ['document_id' => (int)$r['id'], 'state' => $s['state'], 'days_left' => $s['days_left']];
            }
        }
        $items = [];
        $done = 0;
        $missing = [];
        foreach (Document::checklistItems() as $c) {
            if ((int)$c['is_required'] !== 1) {
                continue;
            }
            $b = $best[$c['key']] ?? null;
            $state = $b === null ? 'missing' : ($b['state'] === 'ok' ? 'ok' : 'warn');
            if ($b === null) {
                $missing[] = (string)$c['title'];
            } else {
                $done++;
            }
            $items[] = [
                'key' => (string)$c['key'], 'title' => (string)$c['title'], 'title_kk' => (string)$c['title_kk'],
                'state' => $state, 'document_id' => $b['document_id'] ?? null, 'days_left' => $b['days_left'] ?? null,
                'expired' => $b !== null && $b['state'] === 'expired',
            ];
        }
        return ['items' => $items, 'done' => $done, 'total' => count($items), 'missing' => $missing];
    }
}
