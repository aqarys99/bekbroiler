<?php
/** @var array $requirements @var bool $hasFail @var array $errors @var array $old @var ?string $generalError */
$icons = [
  'ok'   => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
  'warn' => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17h.01"/>',
  'fail' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
];
$field = static function (string $name, string $label, string $hint = '') use ($errors, $old, $hasFail): void {
    $err = $errors[$name] ?? null;
    $id = 'f-' . $name;
    $tid = 'install-' . str_replace('_', '-', $name);
    $describe = trim(($err ? $id . '-error ' : '') . ($hint ? $id . '-hint' : ''));
    ?>
    <div class="field">
      <label for="<?= e($id) ?>"><?= e($label) ?></label>
      <input class="input" id="<?= e($id) ?>" name="<?= e($name) ?>" type="text" value="<?= e($old[$name] ?? '') ?>"
        data-testid="<?= e($tid) ?>"<?= $name === 'login' ? ' autocomplete="username" autocapitalize="none" spellcheck="false"' : '' ?>
        <?= $err ? 'aria-invalid="true"' : '' ?><?= $describe ? ' aria-describedby="' . e($describe) . '"' : '' ?>
        <?= $hasFail ? 'disabled' : '' ?>>
      <?php if ($hint): ?><span class="field__hint" id="<?= e($id) ?>-hint"><?= e($hint) ?></span><?php endif; ?>
      <?php if ($err): ?><span class="field__error" id="<?= e($id) ?>-error"><?= e(t($err)) ?></span><?php endif; ?>
    </div>
    <?php
};
$pwField = static function (string $name, string $label, string $hint, bool $toggle) use ($errors, $hasFail): void {
    $err = $errors[$name] ?? null;
    $id = 'f-' . $name;
    $tid = 'install-' . str_replace('_', '-', $name);
    $describe = trim(($err ? $id . '-error ' : '') . ($hint ? $id . '-hint' : ''));
    ?>
    <div class="field">
      <label for="<?= e($id) ?>"><?= e($label) ?></label>
      <div class="pw"<?= $err ? ' data-invalid="true"' : '' ?>>
        <input class="input" id="<?= e($id) ?>" name="<?= e($name) ?>" type="password" data-pw autocomplete="new-password"
          data-testid="<?= e($tid) ?>"<?= $err ? ' aria-invalid="true"' : '' ?><?= $describe ? ' aria-describedby="' . e($describe) . '"' : '' ?>
          <?= $hasFail ? 'disabled' : '' ?>>
        <?php if ($toggle): ?>
        <button type="button" class="pw__toggle" data-testid="install-password-toggle" aria-pressed="false"
          aria-label="<?= e(t('install.password_show')) ?>"
          data-label-show="<?= e(t('install.password_show')) ?>" data-label-hide="<?= e(t('install.password_hide')) ?>">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
        <?php endif; ?>
      </div>
      <?php if ($hint): ?><span class="field__hint" id="<?= e($id) ?>-hint"><?= e($hint) ?></span><?php endif; ?>
      <?php if ($err): ?><span class="field__error" id="<?= e($id) ?>-error"><?= e(t($err)) ?></span><?php endif; ?>
    </div>
    <?php
};
?>
<div class="auth__head">
  <h1><?= e(t('install.title')) ?></h1>
  <p><?= e(t('install.subtitle')) ?></p>
</div>

<?php if ($generalError): ?>
<div class="alert alert--danger" role="alert" data-testid="install-error"><?= e($generalError) ?></div>
<?php endif; ?>

<section aria-labelledby="req-title" class="form" style="gap:12px" data-testid="install-requirements">
  <h2 class="section-title" id="req-title"><?= e(t('install.requirements')) ?></h2>
  <ul class="reqs">
    <?php foreach ($requirements as $r): $st = in_array($r['status'], ['ok', 'warn', 'fail'], true) ? $r['status'] : 'fail'; ?>
    <li class="req" data-testid="install-req-<?= e($r['key']) ?>" data-status="<?= e($st) ?>">
      <svg class="req__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icons[$st] ?></svg>
      <span class="req__label"><?= e($r['label']) ?></span>
      <span class="req__value"><?= e($r['value']) ?></span>
      <?php if ($st !== 'ok' && $r['hint'] !== ''): ?><span class="req__hint"><?= e($r['hint']) ?></span><?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>
  <a class="btn btn--secondary" href="<?= e(url('/install.php')) ?>" data-testid="install-recheck"><?= e(t('install.recheck')) ?></a>
</section>

<?php if ($hasFail): ?>
<div class="alert alert--danger" role="alert" data-testid="install-requirements-failed"><?= e(t('install.requirements_failed')) ?></div>
<?php endif; ?>

<form class="form" method="post" action="<?= e(url('/install.php')) ?>" novalidate data-install-form>
  <?= csrf_field() ?>
  <fieldset class="fieldset"<?= $hasFail ? ' disabled' : '' ?>>
    <legend class="section-title"><?= e(t('install.section.site')) ?></legend>
    <?php $field('site_name', t('install.site_name')); ?>
  </fieldset>
  <fieldset class="fieldset"<?= $hasFail ? ' disabled' : '' ?>>
    <legend class="section-title"><?= e(t('install.section.owner')) ?></legend>
    <?php $field('owner_name', t('install.owner_name')); ?>
    <?php $field('login', t('install.login'), t('install.login_hint')); ?>
    <?php $pwField('password', t('install.password'), t('install.password_hint'), true); ?>
    <?php $pwField('password_confirm', t('install.password_confirm'), '', false); ?>
  </fieldset>
  <button class="btn" type="submit" data-testid="install-submit" data-submitting="<?= e(t('install.submitting')) ?>"<?= $hasFail ? ' disabled' : '' ?>><?= e(t('install.submit')) ?></button>
</form>
<script src="<?= e(asset('admin/install.js')) ?>" defer></script>
