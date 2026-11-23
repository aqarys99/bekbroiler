<?php
/** Текстовая страница (о нас, программа, режим, питание, условия). @var array $row @var string $heading @var string $body */
use App\Site\Ctx;
?>
<section class="page">
  <div class="container narrow">
    <h1 data-testid="page-title"><?= e($heading) ?></h1>
    <div class="prose" data-testid="page-body"><?= $body /* санитизировано при сохранении */ ?></div>
    <?php if (!empty($row['schedule'])): ?>
      <div class="schedule" data-testid="page-schedule">
        <?php foreach ($row['schedule'] as $it): ?>
          <div class="slot"><time><?= e($it['time_range']) ?></time><span><?= e(loc($it, 'text')) ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
