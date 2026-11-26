<?php
/** @var ?array $row @var bool $isNew @var array $old @var array $errors @var array $docs @var array $checkItems @var ?array $prefill @var string $maxLabel @var int $maxBytes @var string $accept */
$base = admin_url('/attestation');
$err = static fn(string $n): string => !empty($errors[$n]) ? '<span class="field__error" id="att-err-' . e($n) . '" data-role="err-' . e($n) . '" data-testid="att-form-' . e($n) . '-error">' . e($errors[$n]) . '</span>' : '<span class="field__error" data-role="err-' . e($n) . '" data-testid="att-form-' . e($n) . '-error" hidden></span>';
?>
<div class="page-head"><div><h1><?= e($isNew ? t('attestation.new') : $row['title']) ?></h1><?php if (!$isNew): ?><p><?= e(t('attestation.page_lead')) ?> <a href="<?= e(url('/' . $row['slug'])) ?>" target="_blank" rel="noopener" data-testid="att-form-open"><?= e(t('attestation.open_site')) ?></a></p><?php endif; ?></div></div>

<?php if ($isNew): ?>
<form class="stack att-form" method="post" action="<?= e($base) ?>" enctype="multipart/form-data" novalidate data-att-form data-testid="att-form">
  <?= csrf_field() ?>
  <div class="card card--form">
    <div class="form">
      <div class="field" data-field="title">
        <label for="att-title"><?= e(t('attestation.field.title')) ?> <span aria-hidden="true">*</span></label>
        <input class="input" type="text" id="att-title" name="title" maxlength="150" value="<?= e((string)$old['title']) ?>" data-role="title" data-testid="att-form-title"<?= !empty($errors['title']) ? ' aria-invalid="true" aria-describedby="att-err-title"' : '' ?>>
        <?= $err('title') ?>
      </div>
      <div class="field" data-field="title_kk">
        <label for="att-title-kk"><?= e(t('attestation.field.title_kk')) ?> <span class="muted"><?= e(t('pages.field.optional')) ?></span></label>
        <input class="input" type="text" id="att-title-kk" name="title_kk" maxlength="150" value="<?= e((string)$old['title_kk']) ?>" data-role="title_kk" data-testid="att-form-title-kk">
        <?= $err('title_kk') ?>
      </div>
      <label class="check"><input type="checkbox" name="in_menu" value="1"<?= !empty($old['in_menu']) ? ' checked' : '' ?> data-role="in_menu" data-testid="att-form-in-menu"> <?= e(t('attestation.field.in_menu')) ?></label>
      <label class="check"><input type="checkbox" name="is_published" value="1"<?= !empty($old['is_published']) ? ' checked' : '' ?> data-role="is_published" data-testid="att-form-published"> <?= e(t('sections.show_on_site')) ?></label>
    </div>
  </div>
  <div class="card">
    <?php $mode = 'create'; include __DIR__ . '/_upload.php'; ?>
    <div class="actions att-actions">
      <button type="submit" class="btn btn--md" data-role="submit" data-testid="att-form-submit"><?= e(t('sections.save')) ?></button>
      <button type="button" class="btn btn--md btn--ghost" data-role="skip" hidden data-testid="att-form-skip"><?= e(t('attestation.js.skip')) ?></button>
      <a class="btn btn--md btn--ghost" href="<?= e($base) ?>" data-role="cancel" data-testid="att-form-cancel"><?= e(t('sections.cancel')) ?></a>
    </div>
  </div>
</form>
<?php else: ?>
<div class="card card--form">
  <form class="form" method="post" action="<?= e($base . '/' . (int)$row['id']) ?>" novalidate data-once data-testid="att-form">
    <?= csrf_field() ?>
    <div class="field" data-field="title">
      <label for="att-title"><?= e(t('attestation.field.title')) ?> <span aria-hidden="true">*</span></label>
      <input class="input" type="text" id="att-title" name="title" maxlength="150" value="<?= e((string)$old['title']) ?>" data-testid="att-form-title"<?= !empty($errors['title']) ? ' aria-invalid="true" aria-describedby="att-err-title"' : '' ?>>
      <?= $err('title') ?>
    </div>
    <div class="field" data-field="title_kk">
      <label for="att-title-kk"><?= e(t('attestation.field.title_kk')) ?> <span class="muted"><?= e(t('pages.field.optional')) ?></span></label>
      <input class="input" type="text" id="att-title-kk" name="title_kk" maxlength="150" value="<?= e((string)$old['title_kk']) ?>" data-testid="att-form-title-kk">
      <?= $err('title_kk') ?>
    </div>
    <label class="check"><input type="checkbox" name="is_published" value="1"<?= !empty($old['is_published']) ? ' checked' : '' ?> data-testid="att-form-published"> <?= e(t('sections.show_on_site')) ?></label>
    <div class="actions">
      <button type="submit" class="btn btn--md" data-testid="att-form-submit"><?= e(t('sections.save')) ?></button>
      <a class="btn btn--md btn--ghost" href="<?= e($base) ?>" data-testid="att-form-cancel"><?= e(t('sections.cancel')) ?></a>
    </div>
  </form>
  <form class="page-delete" method="post" action="<?= e($base . '/' . (int)$row['id'] . '/delete') ?>" data-confirm="<?= e(t('attestation.delete.confirm', ['title' => $row['title']])) ?>" data-once>
    <?= csrf_field() ?>
    <button type="submit" class="btn btn--md btn--ghost btn--danger" data-testid="att-form-delete"><?= e(t('attestation.delete')) ?></button>
  </form>
</div>
<?php include __DIR__ . '/_files.php'; ?>
<form class="stack att-form" method="post" action="<?= e($base . '/' . (int)$row['id'] . '/files') ?>" enctype="multipart/form-data" novalidate data-att-form data-testid="att-add-form">
  <?= csrf_field() ?>
  <div class="card">
    <?php $mode = 'edit'; include __DIR__ . '/_upload.php'; ?>
    <div class="actions att-actions">
      <button type="submit" class="btn btn--md" data-role="submit" data-testid="att-files-submit"><?= e(t('attestation.files.upload')) ?></button>
      <button type="button" class="btn btn--md btn--ghost" data-role="skip" hidden data-testid="att-form-skip"><?= e(t('attestation.js.skip')) ?></button>
    </div>
  </div>
</form>
<?php endif; ?>
<script src="<?= e(asset('admin/documents.js')) ?>" defer></script>
<script src="<?= e(asset('admin/attestation.js')) ?>" defer></script>
