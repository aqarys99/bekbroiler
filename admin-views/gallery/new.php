<?php
/** @var array $old @var array $errors */
?>
<div class="page-head"><h1><?= e(t('gallery.new.title')) ?></h1></div>
<div class="card card--form">
  <form class="form" method="post" action="<?= e(admin_url('/gallery')) ?>" novalidate data-once data-testid="gallery-form">
    <?= csrf_field() ?>
    <?php include __DIR__ . '/_fields.php'; ?>
    <label class="check"><input type="checkbox" name="is_published" value="1"<?= !empty($old['is_published']) ? ' checked' : '' ?> data-testid="gallery-form-published"> <?= e(t('gallery.field.published')) ?></label>
    <p class="field__hint"><?= e(t('gallery.new.hint')) ?></p>
    <div class="actions">
      <button type="submit" class="btn btn--md" data-testid="gallery-form-submit"><?= e(t('gallery.create')) ?></button>
      <a class="btn btn--md btn--ghost" href="<?= e(admin_url('/gallery')) ?>" data-testid="gallery-form-cancel"><?= e(t('gallery.cancel')) ?></a>
    </div>
  </form>
</div>
