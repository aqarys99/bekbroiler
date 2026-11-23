<?php
/** Поля альбома. @var array $old @var array $errors */
$fld = static function (string $name, string $label, string $testid, bool $area = false, string $type = 'text') use ($old, $errors): void {
    $err = $errors[$name] ?? null;
    ?>
    <div class="field" data-field="<?= e($name) ?>">
      <label for="g-<?= e($name) ?>"><?= e($label) ?></label>
      <?php if ($area): ?>
      <textarea class="input" id="g-<?= e($name) ?>" name="<?= e($name) ?>" rows="3" maxlength="1000" data-testid="<?= e($testid) ?>"<?= $err ? ' aria-invalid="true" aria-describedby="g-' . e($name) . '-error"' : '' ?>><?= e((string)($old[$name] ?? '')) ?></textarea>
      <?php else: ?>
      <input class="input" type="<?= e($type) ?>" id="g-<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e((string)($old[$name] ?? '')) ?>" data-testid="<?= e($testid) ?>"<?= $name === 'title' || $name === 'title_kk' ? ' maxlength="120"' : '' ?><?= $err ? ' aria-invalid="true" aria-describedby="g-' . e($name) . '-error"' : '' ?>>
      <?php endif; ?>
      <?php if ($err): ?><span class="field__error" id="g-<?= e($name) ?>-error"><?= e($err) ?></span><?php endif; ?>
    </div>
    <?php
};
$fld('title', t('gallery.field.title'), 'gallery-form-title');
$fld('title_kk', t('gallery.field.title_kk'), 'gallery-form-title-kk');
$fld('description', t('gallery.field.description'), 'gallery-form-description', true);
$fld('description_kk', t('gallery.field.description_kk'), 'gallery-form-description-kk', true);
$fld('event_date', t('gallery.field.event_date'), 'gallery-form-date', false, 'date');
?>
