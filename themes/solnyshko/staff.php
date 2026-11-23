<?php /** @var array $staff */ ?>
<section class="page">
  <div class="container">
    <div class="section-head"><span class="eyebrow"><?= e(tt('staff.eyebrow')) ?></span><h1><?= e(tt('staff.title')) ?></h1><p><?= e(tt('staff.lead')) ?></p></div>
    <?php if (!$staff): ?><p class="empty"><?= e(tt('empty')) ?></p><?php endif; ?>
    <div class="grid grid-4" data-testid="staff-list">
      <?php foreach ($staff as $i => $s): include \App\Site\Theme::find('_staff-card'); endforeach; ?>
    </div>
  </div>
</section>
