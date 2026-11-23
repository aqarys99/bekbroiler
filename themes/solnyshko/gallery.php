<?php /** @var array $albums */ ?>
<section class="page">
  <div class="container">
    <div class="section-head"><span class="eyebrow"><?= e(tt('gallery.eyebrow')) ?></span><h1><?= e(tt('nav.gallery')) ?></h1><p><?= e(tt('gallery.lead')) ?></p></div>
    <?php if (!$albums): ?><p class="empty"><?= e(tt('empty')) ?></p><?php endif; ?>
    <div class="grid grid-3" data-testid="gallery-list">
      <?php foreach ($albums as $a): ?>
        <a class="card album-card" href="<?= e(surl('/fotogalereya/' . $a['slug'])) ?>" data-testid="gallery-album">
          <?php if (is_array($a['cover']) && ($pic = media_picture($a['cover'], 'medium', ['alt' => '', 'sizes' => '(max-width: 600px) 100vw, 360px'])) !== ''): ?>
            <div class="album-cover"><?= $pic ?></div>
          <?php endif; ?>
          <h3><?= e(loc($a, 'title')) ?></h3>
          <p class="muted"><?= e(tt('gallery.photos', ['n' => (int)$a['photo_count']])) ?><?= !empty($a['event_date']) ? ' · ' . e(date('d.m.Y', (int)strtotime((string)$a['event_date']))) : '' ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
