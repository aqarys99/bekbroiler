<?php
/** Список файлов страницы. @var array $row @var array $docs */
$base = admin_url('/documents');
$pageBase = admin_url('/pages/' . (int)$row['id']);
$ic = static fn(string $d): string => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$badge = static fn(string $ext): string => match ($ext) { 'PDF' => 'doc-badge--pdf', 'XLS', 'XLSX' => 'doc-badge--xls', default => 'doc-badge--other' };
?>
<section class="card page-docs" id="att-files" data-att-list data-order-url="<?= e($pageBase . '/documents/order') ?>" data-csrf="<?= e(csrf_token()) ?>" data-failed="<?= e(t('page_docs.order_failed')) ?>" data-testid="att-file-section">
  <div class="page-docs__head"><div><h2><?= e(t('attestation.list.title')) ?></h2><p class="muted"><?= e(t('page_docs.lead')) ?></p></div></div>
  <div class="table-card" data-role="list" data-testid="att-file-list">
    <?php foreach ($docs as $d): $id = (int)$d['id']; ?>
    <div class="doc-row" draggable="true" data-id="<?= $id ?>" data-testid="att-file-row">
      <span class="doc-drag" aria-hidden="true" title="<?= e(t('page_docs.drag')) ?>">⋮⋮</span>
      <span class="doc-badge <?= e($badge($d['ext'])) ?>" data-testid="att-file-type"><?= e($d['ext']) ?></span>
      <div class="doc-row__main">
        <a class="doc-row__title" href="<?= e($d['url']) ?>" target="_blank" rel="noopener" data-testid="att-file-title"><?= e($d['title']) ?></a>
        <div class="doc-row__meta">
          <?php if ($d['required']): ?><span class="lang-badge lang-badge--ok"><?= e(t('documents.required')) ?></span><?php endif; ?>
          <span data-testid="att-file-meta"><?= e($d['meta']) ?></span>
          <?php if ((int)$d['is_published'] !== 1): ?><span class="lang-badge"><?= e(t('sections.hidden')) ?></span><?php endif; ?>
        </div>
      </div>
      <?php $pill = ['ok' => 'pill-state--published', 'warn' => 'pill-state--warn', 'expired' => 'pill-state--danger'][$d['state']]; ?>
      <span class="pill-state <?= $pill ?>"><?= e($d['status_label']) ?></span>
      <div class="doc-row__actions">
        <a class="btn btn--sm btn--ghost" href="<?= e($base . '/' . $id . '/edit') ?>" data-testid="att-file-edit"><?= e(t('attestation.row.more')) ?></a>
        <?php foreach (['up' => ['<path d="m6 15 6-6 6 6"/>', $d['first']], 'down' => ['<path d="m6 9 6 6 6-6"/>', $d['last']]] as $dir => [$path, $off]): ?>
        <form method="post" action="<?= e($base . '/' . $id . '/move') ?>" data-once>
          <?= csrf_field() ?><input type="hidden" name="dir" value="<?= $dir ?>"><input type="hidden" name="back_page" value="<?= (int)$row['id'] ?>">
          <button type="submit" class="icon-btn" aria-label="<?= e(t('documents.action.' . $dir)) ?>" title="<?= e(t('documents.action.' . $dir)) ?>"<?= $off ? ' disabled' : '' ?> data-testid="att-file-<?= $dir ?>"><?= $ic($path) ?></button>
        </form>
        <?php endforeach; ?>
        <form method="post" action="<?= e($base . '/' . $id . '/replace') ?>" enctype="multipart/form-data" data-replace-form>
          <?= csrf_field() ?><input type="hidden" name="back_page" value="<?= (int)$row['id'] ?>">
          <input type="file" name="file" accept=".pdf,.doc,.docx,.xls,.xlsx" hidden data-replace-input data-testid="att-file-replace-input" tabindex="-1">
          <button type="button" class="icon-btn" aria-label="<?= e(t('documents.action.replace')) ?>" title="<?= e(t('documents.action.replace')) ?>" data-replace-open data-testid="att-file-replace"><?= $ic('<path d="M4 7h13l-3-3M20 17H7l3 3"/>') ?></button>
        </form>
        <form method="post" action="<?= e($base . '/' . $id . '/delete') ?>" data-confirm="<?= e(t('documents.delete.confirm', ['title' => $d['title']])) ?>" data-once>
          <?= csrf_field() ?><input type="hidden" name="back_page" value="<?= (int)$row['id'] ?>">
          <button type="submit" class="icon-btn icon-btn--danger" aria-label="<?= e(t('documents.action.delete')) ?>" title="<?= e(t('documents.action.delete')) ?>" data-testid="att-file-delete"><?= $ic('<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>') ?></button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    <p class="empty" data-testid="att-file-empty"<?= $docs ? ' hidden' : '' ?>><?= e(t('page_docs.empty')) ?></p>
  </div>
</section>
