<?php
/** @var array $rows @var string $base */
$total = count($rows);
?>
<div class="page-head">
  <div><h1><?= e(t('reviews.title')) ?></h1><p><?= e(t('reviews.lead')) ?></p></div>
  <a class="btn btn--md" href="<?= e($base . '/new') ?>" data-testid="reviews-add"><?= e(t('reviews.new')) ?></a>
</div>
<?php if (!$rows): ?>
<p class="empty" data-testid="reviews-empty"><?= e(t('reviews.empty')) ?></p>
<?php else: ?>
<div class="table-card" data-testid="reviews-list">
  <?php foreach ($rows as $i => $r): $id = (int)$r['id']; ?>
  <div class="video-row" data-testid="reviews-row" data-id="<?= $id ?>">
    <div class="video-row__main">
      <b class="video-row__title" data-testid="reviews-row-author"><?= e($r['author']) ?></b>
      <div class="video-row__meta"><span data-testid="reviews-row-text"><?= e(mb_strimwidth((string)$r['text'], 0, 140, '…')) ?></span></div>
    </div>
    <?php $on = (int)$r['is_published']; $k = 'reviews'; include __DIR__ . '/../partials/section-toggle.php'; ?>
    <?php $confirm = t('reviews.delete.confirm', ['title' => $r['author']]); include __DIR__ . '/../partials/section-actions.php'; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
