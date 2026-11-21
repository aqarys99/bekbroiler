<?php /** @var array $user @var array $errors */ ?>
<div class="page-head"><h1><?= e(t('profile.title')) ?></h1></div>

<section class="card card--form" data-testid="profile-info">
  <h2><?= e(t('profile.info')) ?></h2>
  <dl class="info-list">
    <dt><?= e(t('users.field.name')) ?></dt><dd><?= e($user['name']) ?></dd>
    <dt><?= e(t('users.field.login')) ?></dt><dd><?= e($user['login']) ?></dd>
    <dt><?= e(t('users.field.role')) ?></dt><dd><?= e(t('role.' . $user['role'])) ?></dd>
  </dl>
</section>

<section class="card card--form">
  <h2><?= e(t('profile.change_password')) ?></h2>
  <form class="form" method="post" action="<?= e(admin_url('/profile/password')) ?>" novalidate data-once>
    <?= csrf_field() ?>
    <?php if (!empty($generalError)): ?>
    <div class="alert alert--danger" role="alert" data-testid="form-error"><?= e($generalError) ?></div>
    <?php endif; ?>
    <?php
    $f = ['name' => 'current_password', 'type' => 'password', 'label' => t('profile.current_password'), 'testid' => 'profile-current-password', 'toggle' => 'profile-password-toggle', 'error' => $errors['current_password'] ?? null, 'autocomplete' => 'current-password'];
    include __DIR__ . '/../partials/field.php';
    $f = ['name' => 'new_password', 'type' => 'password', 'label' => t('profile.new_password'), 'testid' => 'profile-new-password', 'error' => $errors['new_password'] ?? null, 'autocomplete' => 'new-password'];
    include __DIR__ . '/../partials/field.php';
    $f = ['name' => 'new_password_confirm', 'type' => 'password', 'label' => t('profile.new_password_confirm'), 'testid' => 'profile-new-password-confirm', 'error' => $errors['new_password_confirm'] ?? null, 'autocomplete' => 'new-password'];
    include __DIR__ . '/../partials/field.php';
    ?>
    <div class="actions">
      <button type="submit" class="btn btn--md" data-testid="profile-password-submit"><?= e(t('profile.submit')) ?></button>
    </div>
  </form>
</section>
