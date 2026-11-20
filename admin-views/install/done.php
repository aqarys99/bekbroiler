<?php /** @var string $login */ ?>
<div class="auth__head">
  <h1 data-testid="install-done"><?= e(t('install.done.title')) ?></h1>
  <p><?= e(t('install.done.text', ['login' => $login])) ?></p>
</div>
<a class="btn" href="<?= e(admin_url()) ?>" data-testid="install-go-admin"><?= e(t('install.done.go_admin')) ?></a>
