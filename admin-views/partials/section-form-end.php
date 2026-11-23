<?php
/** Нижняя часть формы: показ на сайте и кнопки. @var string $k @var string $base @var array $old @var ?array $row */
?>
<label class="check"><input type="checkbox" name="is_published" value="1"<?= !empty($old['is_published']) ? ' checked' : '' ?> data-testid="<?= e($k) ?>-form-published"> <?= e(t('sections.show_on_site')) ?></label>
<div class="actions">
  <button type="submit" class="btn btn--md" data-testid="<?= e($k) ?>-form-submit"><?= e(t($row === null ? 'sections.create' : 'sections.save')) ?></button>
  <a class="btn btn--md btn--ghost" href="<?= e($base) ?>" data-testid="<?= e($k) ?>-form-cancel"><?= e(t('sections.cancel')) ?></a>
</div>
