<?php /** @var array $target @var array $errors @var string $formAction */ ?>
<div class="page-head"><h1><?= e(t('users.password.title')) ?></h1></div>

<div class="card card--form">
  <form class="form" method="post" action="<?= e($formAction) ?>" novalidate data-once>
    <?= csrf_field() ?>
    <p class="section-title"><?= e(t('users.password.for', ['name' => $target['name']])) ?></p>
    <?php
    $f = ['name' => 'password', 'type' => 'password', 'label' => t('users.password.new'), 'testid' => 'user-password-new', 'toggle' => 'user-password-toggle', 'error' => $errors['password'] ?? null, 'autocomplete' => 'new-password', 'autofocus' => true];
    include __DIR__ . '/../partials/field.php';
    ?>
    <div class="actions">
      <button type="submit" class="btn btn--md" data-testid="user-password-submit"><?= e(t('users.password.submit')) ?></button>
      <a class="btn btn--md btn--ghost" href="<?= e(admin_url('/users')) ?>" data-testid="user-password-cancel"><?= e(t('users.cancel')) ?></a>
    </div>
  </form>
</div>
