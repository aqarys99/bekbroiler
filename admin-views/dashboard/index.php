<?php /** @var array $user */ ?>
<div class="page-head" data-testid="dashboard-greeting">
  <div>
    <h1><?= e(t('dashboard.greeting', ['name' => $user['name']])) ?></h1>
    <p><?= e(t('dashboard.text')) ?></p>
  </div>
</div>
