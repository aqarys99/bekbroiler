<?php
/** @var array $rows @var string $base */
$total = count($rows);
?>
<div class="page-head">
  <div><h1><?= e(t('achievements.title')) ?></h1><p><?= e(t('achievements.lead')) ?></p></div>
  <a class="btn btn--md" href="<?= e($base . '/new') ?>" data-testid="achievements-add"><?= e(t('achievements.new')) ?></a>
</div>
<?php if (!$rows): ?>
<p class="empty" data-testid="achievements-empty"><?= e(t('achievements.empty')) ?></p>
<?php else: ?>
<div class="table-card" data-testid="achievements-list">
  <?php foreach ($rows as $i => $r): $id = (int)$r['id']; ?>
  <div class="video-row" data-testid="achievements-row" data-id="<?= $id ?>">
    <span class="video-thumb sec-thumb" data-testid="achievements-row-photo"><?php if ($r['photo'] !== null): ?><?= media_picture($r['photo'], 'thumb') ?><?php else: ?><b><?= (int)$r['year'] ?></b><?php endif; ?></span>
    <div class="video-row__main">
      <b class="video-row__title" data-testid="achievements-row-title"><?= e($r['title']) ?></b>
      <div class="video-row__meta"><span data-testid="achievements-row-meta"><?= (int)$r['year'] ?><?= $r['description'] !== '' ? ' · ' . e(mb_strimwidth((string)$r['description'], 0, 90, '…')) : '' ?></span></div>
    </div>
    <?php $on = (int)$r['is_published']; $k = 'achievements'; include __DIR__ . '/../partials/section-toggle.php'; ?>
    <?php $confirm = t('achievements.delete.confirm', ['title' => $r['title']]); include __DIR__ . '/../partials/section-actions.php'; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
