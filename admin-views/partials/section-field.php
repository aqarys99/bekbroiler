<?php
/**
 * Одно поле формы раздела. @var array $fd [name, label, testid, (type), (area), (hint), (required), (max), (step)]
 * @var array $old @var array $errors
 */
$fdName = (string)$fd['name'];
$fdErr = $errors[$fdName] ?? null;
$fdId = 'f-' . $fdName;
$fdAttr = ' id="' . e($fdId) . '" name="' . e($fdName) . '" data-testid="' . e($fd['testid']) . '"'
    . (!empty($fd['max']) ? ' maxlength="' . (int)$fd['max'] . '"' : '')
    . ($fdErr ? ' aria-invalid="true" aria-describedby="' . e($fdId) . '-error"' : '');
?>
<div class="field" data-field="<?= e($fdName) ?>">
  <label for="<?= e($fdId) ?>"><?= e($fd['label']) ?><?= !empty($fd['required']) ? ' <span aria-hidden="true">*</span>' : '' ?></label>
  <?php if (!empty($fd['area'])): ?>
  <textarea class="input" rows="4"<?= $fdAttr ?>><?= e((string)($old[$fdName] ?? '')) ?></textarea>
  <?php else: ?>
  <input class="input" type="<?= e($fd['type'] ?? 'text') ?>"<?= !empty($fd['numeric']) ? ' inputmode="numeric"' : '' ?> value="<?= e((string)($old[$fdName] ?? '')) ?>"<?= $fdAttr ?>>
  <?php endif; ?>
  <?php if (!empty($fd['hint'])): ?><span class="field__hint"><?= e($fd['hint']) ?></span><?php endif; ?>
  <?php if ($fdErr): ?><span class="field__error" id="<?= e($fdId) ?>-error" data-testid="<?= e($fd['testid']) ?>-error"><?= e($fdErr) ?></span><?php endif; ?>
</div>
