<?php
/** Карточка группы. @var array $g */
$parts = [];
if ((int)($g['groups_count'] ?? 0) > 0) { $parts[] = tt('groups.count', ['n' => (int)$g['groups_count']]); }
if ((int)($g['children_count'] ?? 0) > 0) { $parts[] = tt('groups.children', ['n' => (int)$g['children_count']]); }
?>
<div class="card" data-testid="group-card">
  <?php if (($age = loc($g, 'age_range')) !== ''): ?><div class="group-age"><?= e($age) ?></div><?php endif; ?>
  <h3><?= e(loc($g, 'name', null, false)) ?></h3>
  <?php if ($parts): ?><p><?= e(implode(' · ', $parts)) ?></p><?php endif; ?>
  <?php if (!empty($g['teachers'])): ?><p class="muted"><?= e(tt('groups.teachers')) ?>: <?= e(implode(', ', array_map(static fn(array $t): string => loc($t, 'full_name', null, false), $g['teachers']))) ?></p><?php endif; ?>
</div>
