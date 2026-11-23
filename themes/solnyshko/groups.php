<?php /** @var array $groups */ ?>
<section class="page">
  <div class="container">
    <div class="section-head"><span class="eyebrow"><?= e(tt('groups.eyebrow')) ?></span><h1><?= e(tt('nav.groups')) ?></h1><p><?= e(tt('groups.lead')) ?></p></div>
    <?php if (!$groups): ?><p class="empty"><?= e(tt('empty')) ?></p><?php endif; ?>
    <div class="grid grid-4" data-testid="groups-list">
      <?php foreach ($groups as $g): include \App\Site\Theme::find('_group-card'); endforeach; ?>
    </div>
  </div>
</section>
