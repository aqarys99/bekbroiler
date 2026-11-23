<?php /** @var array $items */ ?>
<section class="page">
  <div class="container">
    <div class="section-head"><span class="eyebrow"><?= e(tt('reviews.eyebrow')) ?></span><h1><?= e(tt('nav.reviews')) ?></h1></div>
    <div class="grid grid-3" data-testid="reviews-list">
      <?php foreach ($items as $r): ?>
        <div class="card review"><p>«<?= e(loc($r, 'text')) ?>»</p><b><?= e(loc($r, 'author', null, false)) ?></b></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
