<?php /** @var array $items */ ?>
<section class="page">
  <div class="container">
    <div class="section-head"><span class="eyebrow"><?= e(tt('achievements.eyebrow')) ?></span><h1><?= e(tt('nav.achievements')) ?></h1><p><?= e(tt('achievements.lead')) ?></p></div>
    <?php if (!$items): ?><p class="empty"><?= e(tt('empty')) ?></p><?php endif; ?>
    <div class="grid grid-2" data-testid="achievements-list">
      <?php foreach ($items as $a): ?>
        <div class="card achv">
          <span class="year"><?= e($a['year']) ?></span>
          <div>
            <h3><?= e(loc($a, 'title')) ?></h3>
            <?php if (($d = loc($a, 'description')) !== ''): ?><p><?= e($d) ?></p><?php endif; ?>
            <?php if (is_array($a['photo']) && ($pic = media_picture($a['photo'], 'medium', ['alt' => loc($a, 'title'), 'sizes' => '(max-width: 600px) 100vw, 400px'])) !== ''): ?>
              <div class="achv-photo"><?= $pic ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
