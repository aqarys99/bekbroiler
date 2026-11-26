<?php
/** Строка файла. @var string $accept @var array $checkItems @var ?array $rowPrefill */
$rp = $rowPrefill ?? ['title' => '', 'title_kk' => '', 'checklist_key' => ''];
?>
<div class="att-row" data-row data-testid="att-row">
  <div class="att-row__main">
    <input class="input att-row__title" type="text" name="titles[]" maxlength="200" value="<?= e($rp['title']) ?>" placeholder="<?= e(t('attestation.row.title_ph')) ?>" aria-label="<?= e(t('attestation.row.title')) ?>" data-role="title" data-testid="att-row-title">
    <label class="btn btn--md btn--ghost att-pick" data-testid="att-row-pick">
      <span data-role="fname"><?= e(t('attestation.row.pick')) ?></span>
      <input class="sr-only" type="file" name="files[]" accept="<?= e($accept) ?>" data-role="file" data-testid="att-row-file">
    </label>
    <button type="button" class="icon-btn" data-role="remove" hidden aria-label="<?= e(t('attestation.row.remove')) ?>" title="<?= e(t('attestation.row.remove')) ?>" data-testid="att-row-remove">&times;</button>
  </div>
  <div class="att-row__status" data-role="status" aria-live="polite" data-testid="att-row-status"></div>
  <details class="att-more">
    <summary data-testid="att-row-more"><?= e(t('attestation.row.more')) ?></summary>
    <div class="att-more__grid">
      <div class="field"><label><?= e(t('documents.form.name_kk')) ?></label><input class="input" type="text" name="titles_kk[]" maxlength="200" value="<?= e($rp['title_kk']) ?>" data-role="title_kk" data-testid="att-row-title-kk"></div>
      <div class="field"><label><?= e(t('documents.form.date')) ?></label><input class="input" type="date" name="doc_dates[]" data-role="doc_date" data-testid="att-row-date"></div>
      <div class="field"><label><?= e(t('documents.form.valid_until')) ?></label><input class="input" type="date" name="valid_untils[]" data-role="valid_until" data-testid="att-row-valid-until"></div>
      <div class="field"><label><?= e(t('documents.form.checklist')) ?></label>
        <select class="input select" name="checklist_keys[]" data-role="checklist_key" data-testid="att-row-checklist">
          <option value=""><?= e(t('documents.form.checklist_none')) ?></option>
          <?php foreach ($checkItems as $c): ?><option value="<?= e($c['key']) ?>"<?= $rp['checklist_key'] === $c['key'] ? ' selected' : '' ?>><?= e($c['title']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field" data-role="viewmode-field" hidden><label><?= e(t('documents.form.view_mode')) ?></label>
        <select class="input select" name="view_modes[]" data-role="view_mode" data-testid="att-row-view-mode">
          <option value="reader"><?= e(t('documents.view.reader')) ?></option>
          <option value="book"><?= e(t('documents.view.book')) ?></option>
        </select></div>
    </div>
  </details>
</div>
