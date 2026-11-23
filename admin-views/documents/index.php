<?php
/**
 * Документы и чек-лист аттестации.
 * @var array $docs @var array $missingRows @var array $check @var array $tabs @var int $catId @var array $cats @var array $checkItems
 * @var array $values @var array $errors @var string $today @var string $maxLabel @var string $accept @var string $formAction @var string $base @var int $backCat
 */
$errFor = static function (string $name) use ($errors): string {
    $m = $errors[$name] ?? null;
    return $m ? '<span class="field__error" id="derr-' . e($name) . '" data-testid="documents-field-error">' . e($m) . '</span>' : '';
};
$inv = static fn(string $name): string => !empty($errors[$name]) ? ' aria-invalid="true" aria-describedby="derr-' . e($name) . '"' : '';
$complete = $check['done'] === $check['total'];
$badgeClass = static fn(string $ext): string => match ($ext) {
    'PDF' => 'doc-badge--pdf', 'XLS', 'XLSX' => 'doc-badge--xls', default => 'doc-badge--other',
};
$tabUrl = static fn(int $id): string => $id > 0 ? $base . '?cat=' . $id : $base;
$icon = static fn(string $d): string => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$pUp = '<path d="m6 15 6-6 6 6"/>';
$pDown = '<path d="m6 9 6 6 6-6"/>';
$pSwap = '<path d="M4 7h13l-3-3M20 17H7l3 3"/>';
$pDel = '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>';
$pUpload = '<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>';
?>
<div class="page-head">
  <div>
    <h1><?= e(t('documents.title')) ?></h1>
    <p><?= e(t('documents.lead')) ?></p>
  </div>
</div>

<div class="doc-banner<?= $complete ? ' doc-banner--ok' : '' ?>" data-testid="documents-banner" role="status">
  <div>
    <b data-testid="documents-banner-title"><?= e(t('documents.banner', ['done' => $check['done'], 'total' => $check['total']])) ?></b>
    <?php if (!$complete): ?>
    <p data-testid="documents-banner-missing"><?= e(t('documents.banner.missing', ['list' => mb_strtolower(implode(', ', $check['missing']))])) ?></p>
    <?php else: ?>
    <p data-testid="documents-banner-missing"><?= e(t('documents.banner.complete')) ?></p>
    <?php endif; ?>
  </div>
</div>

<div class="doc-layout">
  <div class="doc-main">
    <div class="news-filters" role="tablist" aria-label="<?= e(t('documents.tabs')) ?>">
      <?php foreach ($tabs as $tab): ?>
      <a class="news-filter" role="tab" href="<?= e($tabUrl((int)$tab['id'])) ?>" aria-selected="<?= $catId === (int)$tab['id'] ? 'true' : 'false' ?>" data-testid="documents-tab-<?= (int)$tab['id'] ?>"><?= e($tab['label']) ?> <span class="news-filter__n"><?= (int)$tab['n'] ?></span></a>
      <?php endforeach; ?>
    </div>

    <div class="table-card" data-testid="documents-list">
      <?php foreach ($docs as $d): $id = (int)$d['id']; $ext = $d['ext']; ?>
      <div class="doc-row" data-testid="documents-row">
        <span class="doc-badge <?= e($badgeClass($ext)) ?>" data-testid="documents-row-type"><?= e($ext) ?></span>
        <div class="doc-row__main">
          <a class="doc-row__title" href="<?= e($d['url']) ?>" target="_blank" rel="noopener" data-testid="documents-row-title"><?= e($d['title']) ?></a>
          <div class="doc-row__meta">
            <?php if ($d['required']): ?><span class="lang-badge lang-badge--ok" data-testid="documents-row-required"><?= e(t('documents.required')) ?></span><?php endif; ?>
            <span data-testid="documents-row-meta"><?= e($d['meta']) ?></span>
          </div>
        </div>
        <?php $pill = ['ok' => 'pill-state--published', 'warn' => 'pill-state--warn', 'expired' => 'pill-state--danger'][$d['state']]; ?>
        <span class="pill-state <?= $pill ?>" data-testid="documents-row-status"><?= e($d['status_label']) ?></span>
        <div class="doc-row__actions">
          <form method="post" action="<?= e($base . '/' . $id . '/move') ?>" data-once>
            <?= csrf_field() ?><input type="hidden" name="dir" value="up"><input type="hidden" name="back_cat" value="<?= (int)$backCat ?>">
            <button type="submit" class="icon-btn" aria-label="<?= e(t('documents.action.up')) ?>" title="<?= e(t('documents.action.up')) ?>"<?= $d['first'] ? ' disabled' : '' ?> data-testid="documents-row-up"><?= $icon($pUp) ?></button>
          </form>
          <form method="post" action="<?= e($base . '/' . $id . '/move') ?>" data-once>
            <?= csrf_field() ?><input type="hidden" name="dir" value="down"><input type="hidden" name="back_cat" value="<?= (int)$backCat ?>">
            <button type="submit" class="icon-btn" aria-label="<?= e(t('documents.action.down')) ?>" title="<?= e(t('documents.action.down')) ?>"<?= $d['last'] ? ' disabled' : '' ?> data-testid="documents-row-down"><?= $icon($pDown) ?></button>
          </form>
          <form method="post" action="<?= e($base . '/' . $id . '/replace') ?>" enctype="multipart/form-data" data-replace-form>
            <?= csrf_field() ?><input type="hidden" name="back_cat" value="<?= (int)$backCat ?>">
            <input type="file" name="file" accept="<?= e($accept) ?>" hidden data-replace-input data-testid="documents-row-replace-input" tabindex="-1">
            <button type="button" class="icon-btn" aria-label="<?= e(t('documents.action.replace')) ?>" title="<?= e(t('documents.action.replace')) ?>" data-replace-open data-testid="documents-row-replace"><?= $icon($pSwap) ?></button>
          </form>
          <form method="post" action="<?= e($base . '/' . $id . '/delete') ?>" data-confirm="<?= e(t('documents.delete.confirm', ['title' => $d['title']])) ?>" data-once>
            <?= csrf_field() ?><input type="hidden" name="back_cat" value="<?= (int)$backCat ?>">
            <button type="submit" class="icon-btn icon-btn--danger" aria-label="<?= e(t('documents.action.delete')) ?>" title="<?= e(t('documents.action.delete')) ?>" data-testid="documents-row-delete"><?= $icon($pDel) ?></button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>

      <?php foreach ($missingRows as $m): ?>
      <div class="doc-row doc-row--missing" data-testid="documents-missing-row">
        <span class="doc-badge doc-badge--none">—</span>
        <div class="doc-row__main">
          <span class="doc-row__title" data-testid="documents-missing-title"><?= e($m['title']) ?></span>
          <div class="doc-row__meta"><span class="lang-badge lang-badge--ok"><?= e(t('documents.required')) ?></span><span><?= e(t('documents.meta.none')) ?></span></div>
        </div>
        <span class="pill-state pill-state--danger" data-testid="documents-missing-status"><?= e(t('documents.status.missing')) ?></span>
        <a class="btn btn--sm" href="<?= e($m['upload_url']) ?>" data-testid="documents-upload-<?= e($m['key']) ?>"><?= e(t('documents.upload')) ?></a>
      </div>
      <?php endforeach; ?>

      <?php if (!$docs && !$missingRows): ?>
      <p class="empty" data-testid="documents-empty"><?= e(t('documents.empty')) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <form class="card stack doc-form" id="documents-form" method="post" action="<?= e($formAction) ?>" enctype="multipart/form-data" data-once novalidate data-testid="documents-form">
    <?= csrf_field() ?>
    <input type="hidden" name="back_cat" value="<?= (int)$catId ?>">
    <h2><?= e(t('documents.form.title')) ?></h2>
    <div class="field">
      <label class="doc-drop" for="documents-file"><b><?= e(t('documents.form.pick')) ?></b><span><?= e(t('documents.form.pick_hint', ['max' => $maxLabel])) ?></span><span class="doc-drop__name" data-file-name data-testid="documents-file-name"></span></label>
      <input class="sr-only" type="file" id="documents-file" name="file" accept="<?= e($accept) ?>" data-testid="documents-file"<?= $inv('file') ?>>
      <?= $errFor('file') ?>
    </div>
    <div class="field">
      <label for="documents-title"><?= e(t('documents.form.name')) ?> <span aria-hidden="true">*</span></label>
      <input class="input" type="text" id="documents-title" name="title" maxlength="200" value="<?= e($values['title']) ?>" data-testid="documents-title"<?= $inv('title') ?>>
      <?= $errFor('title') ?>
    </div>
    <div class="field">
      <label for="documents-title-kk"><?= e(t('documents.form.name_kk')) ?> <span class="muted"><?= e(t('documents.form.later')) ?></span></label>
      <input class="input" type="text" id="documents-title-kk" name="title_kk" maxlength="200" value="<?= e($values['title_kk']) ?>" placeholder="Атауы" data-testid="documents-title-kk"<?= $inv('title_kk') ?>>
      <?= $errFor('title_kk') ?>
    </div>
    <div class="field">
      <label for="documents-category"><?= e(t('documents.form.category')) ?></label>
      <select class="input select" id="documents-category" name="category_id" data-testid="documents-category"<?= $inv('category_id') ?>>
        <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$values['category_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['title']) ?></option><?php endforeach; ?>
      </select>
      <?= $errFor('category_id') ?>
    </div>
    <div class="field">
      <label for="documents-checklist"><?= e(t('documents.form.checklist')) ?></label>
      <select class="input select" id="documents-checklist" name="checklist_key" data-testid="documents-checklist"<?= $inv('checklist_key') ?>>
        <option value=""><?= e(t('documents.form.checklist_none')) ?></option>
        <?php foreach ($checkItems as $c): ?><option value="<?= e($c['key']) ?>"<?= $values['checklist_key'] === $c['key'] ? ' selected' : '' ?>><?= e($c['title']) ?></option><?php endforeach; ?>
      </select>
      <span class="field__hint"><?= e(t('documents.form.checklist_hint')) ?></span>
      <?= $errFor('checklist_key') ?>
    </div>
    <div class="doc-dates">
      <div class="field">
        <label for="documents-date"><?= e(t('documents.form.date')) ?></label>
        <input class="input" type="date" id="documents-date" name="doc_date" value="<?= e($values['doc_date']) ?>" data-testid="documents-date"<?= $inv('doc_date') ?>>
        <?= $errFor('doc_date') ?>
      </div>
      <div class="field">
        <label for="documents-valid"><?= e(t('documents.form.valid_until')) ?></label>
        <input class="input" type="date" id="documents-valid" name="valid_until" value="<?= e($values['valid_until']) ?>" data-testid="documents-valid-until"<?= $inv('valid_until') ?>>
        <?= $errFor('valid_until') ?>
      </div>
    </div>
    <span class="field__hint"><?= e(t('documents.form.valid_hint')) ?></span>
    <button type="submit" class="btn btn--md" data-testid="documents-save"><?= e(t('documents.form.save')) ?></button>
  </form>
</div>
<script src="<?= e(asset('admin/documents.js')) ?>" defer></script>
