<?php
/** Поле формы. Ожидает $f: name,label,value,testid,[type],[error],[hint],[toggle],[autocomplete],[autofocus] */
$type = $f['type'] ?? 'text';
$id = 'f-' . $f['name'];
$err = $f['error'] ?? null;
$hint = $f['hint'] ?? '';
$describe = trim(($err ? $id . '-error ' : '') . ($hint ? $id . '-hint' : ''));
$attrs = ' id="' . e($id) . '" name="' . e($f['name']) . '" data-testid="' . e($f['testid']) . '"'
  . (isset($f['autocomplete']) ? ' autocomplete="' . e($f['autocomplete']) . '"' : '')
  . ($type === 'password' ? ' data-pw' : ' autocapitalize="none" spellcheck="false"')
  . ($err ? ' aria-invalid="true"' : '')
  . ($describe ? ' aria-describedby="' . e($describe) . '"' : '')
  . (!empty($f['autofocus']) ? ' autofocus' : '');
?>
<div class="field">
  <label for="<?= e($id) ?>"><?= e($f['label']) ?></label>
  <?php if ($type === 'password'): ?>
  <div class="pw"<?= $err ? ' data-invalid="true"' : '' ?>>
    <input class="input" type="password"<?= $attrs ?>>
    <?php if (!empty($f['toggle'])): ?>
    <button type="button" class="pw__toggle" data-password-toggle data-testid="<?= e($f['toggle']) ?>" aria-pressed="false"
      aria-label="<?= e(t('auth.password_show')) ?>" data-label-show="<?= e(t('auth.password_show')) ?>" data-label-hide="<?= e(t('auth.password_hide')) ?>">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
    </button>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <input class="input" type="text" value="<?= e($f['value'] ?? '') ?>"<?= $attrs ?>>
  <?php endif; ?>
  <?php if ($hint): ?><span class="field__hint" id="<?= e($id) ?>-hint"><?= e($hint) ?></span><?php endif; ?>
  <?php if ($err): ?><span class="field__error" id="<?= e($id) ?>-error"><?= e(t($err)) ?></span><?php endif; ?>
</div>
