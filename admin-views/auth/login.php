<?php
/** @var string $siteName @var array $old @var array $errors @var ?string $generalError @var ?string $notice @var string $next */
$locked = http_response_code() === 429;
$focusPass = !empty($errors) || $generalError;
$loginErr = $errors['login'] ?? null;
$passErr = $errors['password'] ?? null;
?>
<div class="auth__head">
  <h1><?= e(t('auth.title')) ?></h1>
  <p data-testid="login-site-name"><?= e($siteName) ?></p>
</div>

<form class="form" method="post" action="<?= e(admin_url('/login')) ?>" novalidate data-once data-testid="login-form">
  <?= csrf_field() ?>
  <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>

  <?php if ($notice): ?>
  <div class="alert alert--info" role="status" data-testid="login-notice"><?= e($notice) ?></div>
  <?php endif; ?>
  <?php if ($generalError): ?>
  <div class="alert alert--danger" role="alert" data-testid="<?= $locked ? 'login-locked' : 'login-error' ?>"><?= e($generalError) ?></div>
  <?php endif; ?>

  <div class="field">
    <label for="f-login"><?= e(t('auth.login')) ?></label>
    <input class="input" id="f-login" name="login" type="text" value="<?= e($old['login'] ?? '') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" data-testid="login-login"<?= $loginErr ? ' aria-invalid="true" aria-describedby="f-login-error"' : '' ?><?= !$focusPass ? ' autofocus' : '' ?>>
    <?php if ($loginErr): ?><span class="field__error" id="f-login-error"><?= e(t($loginErr)) ?></span><?php endif; ?>
  </div>

  <div class="field">
    <label for="f-password"><?= e(t('auth.password')) ?></label>
    <div class="pw"<?= $passErr ? ' data-invalid="true"' : '' ?>>
      <input class="input" id="f-password" name="password" type="password" data-pw autocomplete="current-password" data-testid="login-password"<?= $passErr ? ' aria-invalid="true" aria-describedby="f-password-error"' : '' ?><?= $focusPass ? ' autofocus' : '' ?>>
      <button type="button" class="pw__toggle" data-password-toggle data-testid="login-password-toggle" aria-pressed="false"
        aria-label="<?= e(t('auth.password_show')) ?>" data-label-show="<?= e(t('auth.password_show')) ?>" data-label-hide="<?= e(t('auth.password_hide')) ?>">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
      </button>
    </div>
    <?php if ($passErr): ?><span class="field__error" id="f-password-error"><?= e(t($passErr)) ?></span><?php endif; ?>
  </div>

  <label class="check"><input type="checkbox" name="remember" value="1" data-testid="login-remember"><?= e(t('auth.remember')) ?></label>

  <button class="btn" type="submit" data-testid="login-submit"><?= e(t('auth.submit')) ?></button>

  <div class="hint-box" data-testid="login-forgot">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .8-1 1.5V14M12 17h.01"/></svg>
    <span><?= e(t('auth.forgot')) ?></span>
  </div>
</form>
<p class="auth__lock" data-testid="login-lock-hint"><?= e(t('auth.lock_hint')) ?></p>
