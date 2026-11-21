<?php
/** @var ?array $target @var array $old @var array $errors @var ?string $generalError @var ?string $roleLocked @var string $formAction */
$isNew = $target === null;
$role = $old['role'] ?? 'editor';
$roleErr = $errors['role'] ?? null;
$describe = trim(($roleErr ? 'f-role-error ' : '') . ($roleLocked ? 'f-role-locked ' : '') . 'f-role-hint');
?>
<div class="page-head"><h1><?= e(t($isNew ? 'users.new.title' : 'users.edit.title')) ?></h1></div>

<div class="card card--form">
  <form class="form" method="post" action="<?= e($formAction) ?>" novalidate data-once>
    <?= csrf_field() ?>
    <?php if ($generalError): ?>
    <div class="alert alert--danger" role="alert"><?= e($generalError) ?></div>
    <?php endif; ?>
    <?php
    $f = ['name' => 'name', 'label' => t('users.field.name'), 'value' => $old['name'] ?? '', 'testid' => 'user-form-name', 'error' => $errors['name'] ?? null, 'autocomplete' => 'name'];
    include __DIR__ . '/../partials/field.php';
    $f = ['name' => 'login', 'label' => t('users.field.login'), 'value' => $old['login'] ?? '', 'testid' => 'user-form-login', 'error' => $errors['login'] ?? null, 'autocomplete' => 'off'];
    include __DIR__ . '/../partials/field.php';
    ?>
    <div class="field">
      <label for="f-role"><?= e(t('users.field.role')) ?></label>
      <select class="input select" id="f-role" name="role" data-testid="user-form-role"<?= $roleLocked ? ' disabled' : '' ?><?= $roleErr ? ' aria-invalid="true"' : '' ?> aria-describedby="<?= e($describe) ?>">
        <?php foreach (['editor', 'owner'] as $r): ?>
        <option value="<?= $r ?>"<?= $role === $r ? ' selected' : '' ?>><?= e(t('role.' . $r)) ?></option>
        <?php endforeach; ?>
      </select>
      <span class="field__hint" id="f-role-hint"><?= e(t('role.owner')) ?>: <?= e(t('role.owner.hint')) ?>. <?= e(t('role.editor')) ?>: <?= e(t('role.editor.hint')) ?>.</span>
      <?php if ($roleLocked): ?><span class="field__hint" id="f-role-locked" data-testid="user-form-role-locked"><?= e(t('users.role_locked.' . $roleLocked)) ?></span><?php endif; ?>
      <?php if ($roleErr): ?><span class="field__error" id="f-role-error"><?= e(t($roleErr)) ?></span><?php endif; ?>
    </div>
    <?php if ($isNew):
      $f = ['name' => 'password', 'type' => 'password', 'label' => t('users.field.password'), 'testid' => 'user-form-password', 'toggle' => 'user-form-password-toggle', 'error' => $errors['password'] ?? null, 'autocomplete' => 'new-password'];
      include __DIR__ . '/../partials/field.php';
    endif; ?>
    <div class="actions">
      <button type="submit" class="btn btn--md" data-testid="user-form-submit"><?= e(t($isNew ? 'users.create' : 'users.save')) ?></button>
      <a class="btn btn--md btn--ghost" href="<?= e(admin_url('/users')) ?>" data-testid="user-form-cancel"><?= e(t('users.cancel')) ?></a>
    </div>
  </form>
</div>
