<?php
/**
 * Блок «Документы» на экране страницы.
 * @var array $row @var array $docs @var array $docValues @var array $docErrors @var bool $docOpen @var array $checkItems @var string $maxLabel @var string $accept
 */
$base = admin_url('/documents');
$pageBase = admin_url('/pages/' . (int)$row['id']);
$de = static fn(string $n): string => !empty($docErrors[$n]) ? '<span class="field__error" id="pd-err-' . e($n) . '" data-testid="page-docs-error-' . e($n) . '">' . e($docErrors[$n]) . '</span>' : '';
$di = static fn(string $n): string => !empty($docErrors[$n]) ? ' aria-invalid="true" aria-describedby="pd-err-' . e($n) . '"' : '';
$ic = static fn(string $d): string => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$badge = static fn(string $ext): string => match ($ext) { 'PDF' => 'doc-badge--pdf', 'XLS', 'XLSX' => 'doc-badge--xls', default => 'doc-badge--other' };
?>
<section class="card page-docs" id="page-docs" data-page-docs data-order-url="<?= e($pageBase . '/documents/order') ?>" data-csrf="<?= e(csrf_token()) ?>"
         data-failed="<?= e(t('page_docs.order_failed')) ?>" data-testid="page-docs">
  <div class="page-docs__head">
    <div><h2><?= e(t('page_docs.title')) ?></h2><p class="muted"><?= e(t('page_docs.lead')) ?></p></div>
    <button type="button" class="btn btn--md" data-role="toggle" aria-expanded="<?= $docOpen ? 'true' : 'false' ?>" aria-controls="page-docs-form" data-testid="page-docs-add"><?= e(t('page_docs.add')) ?></button>
  </div>

  <form class="stack doc-form page-docs__form" id="page-docs-form" method="post" action="<?= e($pageBase . '/documents') ?>" enctype="multipart/form-data" data-once novalidate<?= $docOpen ? '' : ' hidden' ?> data-testid="page-docs-form">
    <?= csrf_field() ?>
    <div class="field">
      <label class="doc-drop" for="documents-file"><b><?= e(t('page_docs.pick')) ?></b><span><?= e(t('page_docs.pick_hint', ['max' => $maxLabel])) ?></span><span class="doc-drop__name" data-file-name data-testid="page-docs-file-name"></span></label>
      <input class="sr-only" type="file" id="documents-file" name="file" accept="<?= e($accept) ?>" data-testid="page-docs-file"<?= $di('file') ?>>
      <?= $de('file') ?>
    </div>
    <div class="field">
      <label for="documents-title"><?= e(t('documents.form.name')) ?> <span aria-hidden="true">*</span></label>
      <input class="input" type="text" id="documents-title" name="title" maxlength="200" value="<?= e($docValues['title']) ?>" data-testid="page-docs-title"<?= $di('title') ?>>
      <?= $de('title') ?>
    </div>
    <div class="field">
      <label for="documents-title-kk"><?= e(t('documents.form.name_kk')) ?> <span class="muted"><?= e(t('documents.form.later')) ?></span></label>
      <input class="input" type="text" id="documents-title-kk" name="title_kk" maxlength="200" value="<?= e($docValues['title_kk']) ?>" data-testid="page-docs-title-kk"<?= $di('title_kk') ?>>
      <?= $de('title_kk') ?>
    </div>
    <div class="doc-dates">
      <div class="field">
        <label for="documents-date"><?= e(t('documents.form.date')) ?></label>
        <input class="input" type="date" id="documents-date" name="doc_date" value="<?= e($docValues['doc_date']) ?>" data-testid="page-docs-date"<?= $di('doc_date') ?>>
        <?= $de('doc_date') ?>
      </div>
      <div class="field">
        <label for="documents-valid"><?= e(t('documents.form.valid_until')) ?></label>
        <input class="input" type="date" id="documents-valid" name="valid_until" value="<?= e($docValues['valid_until']) ?>" data-testid="page-docs-valid-until"<?= $di('valid_until') ?>>
        <?= $de('valid_until') ?>
      </div>
    </div>
    <div class="field">
      <label for="documents-checklist"><?= e(t('documents.form.checklist')) ?></label>
      <select class="input select" id="documents-checklist" name="checklist_key" data-testid="page-docs-checklist"<?= $di('checklist_key') ?>>
        <option value=""><?= e(t('documents.form.checklist_none')) ?></option>
        <?php foreach ($checkItems as $c): ?><option value="<?= e($c['key']) ?>"<?= $docValues['checklist_key'] === $c['key'] ? ' selected' : '' ?>><?= e($c['title']) ?></option><?php endforeach; ?>
      </select>
      <?= $de('checklist_key') ?>
    </div>
    <div class="field" data-viewmode-field hidden>
      <label for="documents-view-mode"><?= e(t('documents.form.view_mode')) ?></label>
      <select class="input select" id="documents-view-mode" name="view_mode" data-testid="page-docs-view-mode">
        <option value="reader"<?= ($docValues['view_mode'] ?? 'reader') !== 'book' ? ' selected' : '' ?>><?= e(t('documents.view.reader')) ?></option>
        <option value="book"<?= ($docValues['view_mode'] ?? '') === 'book' ? ' selected' : '' ?>><?= e(t('documents.view.book')) ?></option>
      </select>
      <span class="field__hint"><?= e(t('documents.view.hint')) ?></span>
    </div>
    <div class="doc-dates">
      <button type="submit" class="btn btn--md" data-testid="page-docs-save"><?= e(t('documents.form.save')) ?></button>
      <button type="button" class="btn btn--md btn--ghost" data-role="cancel" data-testid="page-docs-cancel"><?= e(t('sections.cancel')) ?></button>
    </div>
  </form>

  <div class="table-card" data-role="list" data-testid="page-docs-list">
    <?php foreach ($docs as $d): $id = (int)$d['id']; ?>
    <div class="doc-row" draggable="true" data-id="<?= $id ?>" data-testid="page-docs-row">
      <span class="doc-drag" aria-hidden="true" title="<?= e(t('page_docs.drag')) ?>">⋮⋮</span>
      <span class="doc-badge <?= e($badge($d['ext'])) ?>" data-testid="page-docs-row-type"><?= e($d['ext']) ?></span>
      <div class="doc-row__main">
        <a class="doc-row__title" href="<?= e($d['url']) ?>" target="_blank" rel="noopener" data-testid="page-docs-row-title"><?= e($d['title']) ?></a>
        <div class="doc-row__meta">
          <?php if ($d['required']): ?><span class="lang-badge lang-badge--ok"><?= e(t('documents.required')) ?></span><?php endif; ?>
          <span data-testid="page-docs-row-meta"><?= e($d['meta']) ?></span>
          <?php if ((int)$d['is_published'] !== 1): ?><span class="lang-badge"><?= e(t('sections.hidden')) ?></span><?php endif; ?>
        </div>
      </div>
      <?php $pill = ['ok' => 'pill-state--published', 'warn' => 'pill-state--warn', 'expired' => 'pill-state--danger'][$d['state']]; ?>
      <span class="pill-state <?= $pill ?>"><?= e($d['status_label']) ?></span>
      <div class="doc-row__actions">
        <a class="icon-btn" href="<?= e($base . '/' . $id . '/edit') ?>" aria-label="<?= e(t('documents.action.edit')) ?>" title="<?= e(t('documents.action.edit')) ?>" data-testid="page-docs-row-edit"><?= $ic('<path d="M4 20h4L19 9l-4-4L4 16v4zM13 7l4 4"/>') ?></a>
        <?php foreach (['up' => ['<path d="m6 15 6-6 6 6"/>', $d['first']], 'down' => ['<path d="m6 9 6 6 6-6"/>', $d['last']]] as $dir => [$path, $off]): ?>
        <form method="post" action="<?= e($base . '/' . $id . '/move') ?>" data-once>
          <?= csrf_field() ?><input type="hidden" name="dir" value="<?= $dir ?>"><input type="hidden" name="back_page" value="<?= (int)$row['id'] ?>">
          <button type="submit" class="icon-btn" aria-label="<?= e(t('documents.action.' . $dir)) ?>" title="<?= e(t('documents.action.' . $dir)) ?>"<?= $off ? ' disabled' : '' ?> data-testid="page-docs-row-<?= $dir ?>"><?= $ic($path) ?></button>
        </form>
        <?php endforeach; ?>
        <form method="post" action="<?= e($base . '/' . $id . '/replace') ?>" enctype="multipart/form-data" data-replace-form>
          <?= csrf_field() ?><input type="hidden" name="back_page" value="<?= (int)$row['id'] ?>">
          <input type="file" name="file" accept="<?= e($accept) ?>" hidden data-replace-input data-testid="page-docs-row-replace-input" tabindex="-1">
          <button type="button" class="icon-btn" aria-label="<?= e(t('documents.action.replace')) ?>" title="<?= e(t('documents.action.replace')) ?>" data-replace-open data-testid="page-docs-row-replace"><?= $ic('<path d="M4 7h13l-3-3M20 17H7l3 3"/>') ?></button>
        </form>
        <form method="post" action="<?= e($base . '/' . $id . '/delete') ?>" data-confirm="<?= e(t('documents.delete.confirm', ['title' => $d['title']])) ?>" data-once>
          <?= csrf_field() ?><input type="hidden" name="back_page" value="<?= (int)$row['id'] ?>">
          <button type="submit" class="icon-btn icon-btn--danger" aria-label="<?= e(t('documents.action.delete')) ?>" title="<?= e(t('documents.action.delete')) ?>" data-testid="page-docs-row-delete"><?= $ic('<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>') ?></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    <p class="empty" data-testid="page-docs-empty"<?= $docs ? ' hidden' : '' ?>><?= e(t('page_docs.empty')) ?></p>
  </div>
</section>
<script src="<?= e(asset('admin/documents.js')) ?>" defer></script>
