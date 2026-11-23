<?php
/** @var array $albums */
?>
<div class="page-head">
  <div>
    <h1><?= e(t('gallery.title')) ?></h1>
    <p><?= e(t('gallery.subtitle')) ?></p>
  </div>
  <a class="btn btn--md" href="<?= e(admin_url('/gallery/new')) ?>" data-testid="gallery-new"><?= e(t('gallery.new')) ?></a>
</div>

<?php if (!$albums): ?>
<p class="empty" data-testid="gallery-empty"><?= e(t('gallery.empty')) ?></p>
<?php else: ?>
<ul class="album-grid" data-testid="gallery-grid">
  <?php foreach ($albums as $a): ?>
  <li>
    <a class="album-card" href="<?= e(admin_url('/gallery/' . (int)$a['id'])) ?>" data-testid="gallery-album" data-album-id="<?= (int)$a['id'] ?>">
      <span class="album-card__cover"><?php if ($a['cover']): ?><?= media_picture($a['cover'], 'thumb', ['sizes' => '260px', 'alt' => '']) ?><?php else: ?><span class="album-card__none"><?= e(t('gallery.no_cover')) ?></span><?php endif; ?></span>
      <b><?= e($a['title']) ?></b>
      <span class="album-card__meta" data-testid="gallery-album-meta"><?= e(t('gallery.photos_count', ['n' => (int)$a['photo_count']])) ?><?php if ($a['event_date']): ?> · <?= e(date('d.m.Y', (int)strtotime((string)$a['event_date']))) ?><?php endif; ?></span>
      <?php if (!(int)$a['is_published']): ?><span class="badge badge--editor album-card__hidden" data-testid="gallery-album-hidden"><?= e(t('gallery.hidden_badge')) ?></span><?php endif; ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
