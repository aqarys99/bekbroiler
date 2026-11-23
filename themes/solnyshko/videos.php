<?php /** @var array $items */ ?>
<section class="page">
  <div class="container">
    <div class="section-head"><span class="eyebrow"><?= e(tt('videos.eyebrow')) ?></span><h1><?= e(tt('nav.videos')) ?></h1><p><?= e(tt('videos.lead')) ?></p></div>
    <?php if (!$items): ?><p class="empty"><?= e(tt('empty')) ?></p><?php endif; ?>
    <div class="grid grid-2" data-testid="videos-list">
      <?php foreach ($items as $v): if ($v['html'] === '') { continue; } ?>
        <div class="card video-card"><?= $v['html'] /* video_embed: свой экранированный HTML */ ?><h3><?= e($v['title']) ?></h3></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
