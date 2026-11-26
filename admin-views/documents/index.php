<?php
/**
 * Документы и чек-лист аттестации.
 * @var array $docs @var array $missingRows @var array $check @var array $pages @var int $pageId @var string $base
 */
$complete = $check['done'] === $check['total'];
$badgeClass = static fn(string $ext): string => match ($ext) {
    'PDF' => 'doc-badge--pdf', 'XLS', 'XLSX' => 'doc-badge--xls', default => 'doc-badge--other',
};
$icon = static fn(string $d): string => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$pUp = '<path d="m6 15 6-6 6 6"/>';
$pDown = '<path d="m6 9 6 6 6-6"/>';
$pSwap = '<path d="M4 7h13l-3-3M20 17H7l3 3"/>';
$pDel = '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>';
$pEdit = '<path d="M4 20h4L19 9l-4-4L4 16v4zM13 7l4 4"/>';
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

<div class="doc-layout doc-layout--single">
  <div class="doc-main">
    <form class="news-filters pages-filter" method="get" action="<?= e($base) ?>" data-testid="documents-filter">
      <label class="sr-only" for="documents-page-filter"><?= e(t('documents.filter.page')) ?></label>
      <select class="input select" id="documents-page-filter" name="page" data-autosubmit data-testid="documents-filter-page">
        <option value="0"><?= e(t('documents.filter.all')) ?></option>
        <?php foreach ($pages as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $pageId === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['title']) ?></option><?php endforeach; ?>
      </select>
      <noscript><button type="submit" class="btn btn--md btn--ghost"><?= e(t('pages.find')) ?></button></noscript>
    </form>

    <div class="table-card" data-testid="documents-list">
      <?php foreach ($docs as $d): $id = (int)$d['id']; $ext = $d['ext']; ?>
      <div class="doc-row" data-testid="documents-row">
        <span class="doc-badge <?= e($badgeClass($ext)) ?>" data-testid="documents-row-type"><?= e($ext) ?></span>
        <div class="doc-row__main">
          <a class="doc-row__title" href="<?= e($d['url']) ?>" target="_blank" rel="noopener" data-testid="documents-row-title"><?= e($d['title']) ?></a>
          <div class="doc-row__meta">
            <?php if ($d['required']): ?><span class="lang-badge lang-badge--ok" data-testid="documents-row-required"><?= e(t('documents.required')) ?></span><?php endif; ?>
            <?php if ($d['page_id'] !== null): ?><a href="<?= e(admin_url('/pages/' . (int)$d['page_id'])) ?>#page-docs" data-testid="documents-row-page"><?= e($d['page_title']) ?></a><?php endif; ?>
            <span data-testid="documents-row-meta"><?= e($d['meta']) ?></span>
          </div>
        </div>
        <?php $pill = ['ok' => 'pill-state--published', 'warn' => 'pill-state--warn', 'expired' => 'pill-state--danger'][$d['state']]; ?>
        <span class="pill-state <?= $pill ?>" data-testid="documents-row-status"><?= e($d['status_label']) ?></span>
        <div class="doc-row__actions">
          <a class="icon-btn" href="<?= e($base . '/' . $id . '/edit') ?>" aria-label="<?= e(t('documents.action.edit')) ?>" title="<?= e(t('documents.action.edit')) ?>" data-testid="documents-row-edit"><?= $icon($pEdit) ?></a>
          <form method="post" action="<?= e($base . '/' . $id . '/delete') ?>" data-confirm="<?= e(t('documents.delete.confirm', ['title' => $d['title']])) ?>" data-once>
            <?= csrf_field() ?>
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

</div>
<script src="<?= e(asset('admin/documents.js')) ?>" defer></script>
