<?php
/**
 * Изменение полей документа (файл заменяется на экране списка).
 * @var array $doc @var bool $isPdf @var array $values @var array $errors @var array $pages @var array $checkItems @var string $action @var string $back
 */
$errFor = static function (string $name) use ($errors): string {
    $m = $errors[$name] ?? null;
    return $m ? '<span class="field__error" id="derr-' . e($name) . '" data-testid="documents-field-error">' . e($m) . '</span>' : '';
};
$inv = static fn(string $name): string => !empty($errors[$name]) ? ' aria-invalid="true" aria-describedby="derr-' . e($name) . '"' : '';
?>
<div class="page-head">
  <div>
    <h1><?= e(t('documents.edit.title')) ?></h1>
    <p><?= e((string)$doc['file_name']) ?></p>
  </div>
</div>
<form class="card stack doc-form" method="post" action="<?= e($action) ?>" data-once novalidate data-testid="documents-edit-form">
  <?= csrf_field() ?>
  <div class="field">
    <label for="documents-title"><?= e(t('documents.form.name')) ?> <span aria-hidden="true">*</span></label>
    <input class="input" type="text" id="documents-title" name="title" maxlength="200" value="<?= e($values['title']) ?>" data-testid="documents-edit-title"<?= $inv('title') ?>>
    <?= $errFor('title') ?>
  </div>
  <div class="field">
    <label for="documents-title-kk"><?= e(t('documents.form.name_kk')) ?></label>
    <input class="input" type="text" id="documents-title-kk" name="title_kk" maxlength="200" value="<?= e($values['title_kk']) ?>" data-testid="documents-edit-title-kk"<?= $inv('title_kk') ?>>
    <?= $errFor('title_kk') ?>
  </div>
  <div class="field">
    <label for="documents-page"><?= e(t('documents.form.page')) ?></label>
    <select class="input select" id="documents-page" name="page_id" data-testid="documents-edit-page"<?= $inv('page_id') ?>>
      <?php foreach ($pages as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$values['page_id'] === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['title']) ?></option><?php endforeach; ?>
    </select>
    <?= $errFor('page_id') ?>
  </div>
  <div class="field">
    <label for="documents-checklist"><?= e(t('documents.form.checklist')) ?></label>
    <select class="input select" id="documents-checklist" name="checklist_key" data-testid="documents-edit-checklist"<?= $inv('checklist_key') ?>>
      <option value=""><?= e(t('documents.form.checklist_none')) ?></option>
      <?php foreach ($checkItems as $c): ?><option value="<?= e($c['key']) ?>"<?= $values['checklist_key'] === $c['key'] ? ' selected' : '' ?>><?= e($c['title']) ?></option><?php endforeach; ?>
    </select>
    <?= $errFor('checklist_key') ?>
  </div>
  <div class="doc-dates">
    <div class="field">
      <label for="documents-date"><?= e(t('documents.form.date')) ?></label>
      <input class="input" type="date" id="documents-date" name="doc_date" value="<?= e($values['doc_date']) ?>" data-testid="documents-edit-date"<?= $inv('doc_date') ?>>
      <?= $errFor('doc_date') ?>
    </div>
    <div class="field">
      <label for="documents-valid"><?= e(t('documents.form.valid_until')) ?></label>
      <input class="input" type="date" id="documents-valid" name="valid_until" value="<?= e($values['valid_until']) ?>" data-testid="documents-edit-valid-until"<?= $inv('valid_until') ?>>
      <?= $errFor('valid_until') ?>
    </div>
  </div>
  <?php if ($isPdf): ?>
  <div class="field">
    <label for="documents-view-mode"><?= e(t('documents.form.view_mode')) ?></label>
    <select class="input select" id="documents-view-mode" name="view_mode" data-testid="documents-edit-view-mode">
      <option value="reader"<?= $values['view_mode'] !== 'book' ? ' selected' : '' ?>><?= e(t('documents.view.reader')) ?></option>
      <option value="book"<?= $values['view_mode'] === 'book' ? ' selected' : '' ?>><?= e(t('documents.view.book')) ?></option>
    </select>
    <span class="field__hint"><?= e(t('documents.view.hint')) ?></span>
  </div>
  <?php endif; ?>
  <label class="check"><input type="checkbox" name="is_published" value="1"<?= !empty($values['is_published']) ? ' checked' : '' ?> data-testid="documents-edit-published"> <?= e(t('documents.edit.published')) ?></label>
  <div class="doc-dates">
    <button type="submit" class="btn btn--md" data-testid="documents-edit-save"><?= e(t('documents.edit.save')) ?></button>
    <a class="btn btn--outline btn--md" href="<?= e($back) ?>" data-testid="documents-edit-cancel"><?= e(t('documents.edit.cancel')) ?></a>
  </div>
</form>
