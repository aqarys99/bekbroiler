<?php
/** @var array $rows @var string $base */
$total = count($rows);
?>
<div class="page-head">
  <div><h1><?= e(t('groups.title')) ?></h1><p><?= e(t('groups.lead')) ?></p></div>
  <a class="btn btn--md" href="<?= e($base . '/new') ?>" data-testid="groups-add"><?= e(t('groups.new')) ?></a>
</div>
<?php if (!$rows): ?>
<p class="empty" data-testid="groups-empty"><?= e(t('groups.empty')) ?></p>
<?php else: ?>
<div class="table-card" data-testid="groups-list">
  <?php foreach ($rows as $i => $r): $id = (int)$r['id']; ?>
  <div class="video-row" data-testid="groups-row" data-id="<?= $id ?>">
    <div class="video-row__main">
      <b class="video-row__title" data-testid="groups-row-name"><?= e($r['name']) ?></b>
      <div class="video-row__meta">
        <span data-testid="groups-row-meta"><?= e(implode(' · ', array_filter([
            (string)$r['age_range'],
            $r['groups_count'] !== null ? t('groups.groups_n', ['n' => (int)$r['groups_count']]) : '',
            $r['children_count'] !== null ? t('groups.children_n', ['n' => (int)$r['children_count']]) : '',
            $r['teachers'] ? implode(', ', array_column($r['teachers'], 'full_name')) : '',
        ]))) ?></span>
      </div>
    </div>
    <?php $on = (int)$r['is_published']; $k = 'groups'; include __DIR__ . '/../partials/section-toggle.php'; ?>
    <?php $confirm = t('groups.delete.confirm', ['title' => $r['name']]); include __DIR__ . '/../partials/section-actions.php'; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
