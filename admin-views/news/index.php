<?php
/**
 * Список новостей.
 * @var array $items @var array $counts @var string $q @var string $status @var int $page @var int $pages @var int $total
 * @var int $from @var int $to @var array $pageList @var bool $hasFilter @var bool $noneAtAll @var bool $kkMissing @var string $createUrl
 */
$base = admin_url('/news');
$qs = static function (array $extra) use ($q, $status, $kkMissing): string {
    $p = $extra + ['q' => $q, 'status' => $status, 'kk' => $kkMissing ? 'missing' : ''];
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== 'all' && $v !== 1);
    return $p ? '?' . http_build_query($p) : '';
};
$filters = ['all', 'published', 'draft', 'scheduled'];
$plus = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
?>
<div class="page-head">
  <div>
    <h1><?= e(t('news.title')) ?></h1>
    <p><?= e(t('news.lead')) ?></p>
  </div>
  <?php if (!$noneAtAll): ?>
  <a class="btn btn--md btn--create" href="<?= e($createUrl) ?>" data-testid="news-create"><?= $plus ?><?= e(t('news.create')) ?></a>
  <?php endif; ?>
</div>

<div class="news-toolbar">
  <form class="news-search" method="get" action="<?= e($base) ?>" role="search">
    <?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <?php if ($kkMissing): ?><input type="hidden" name="kk" value="missing"><?php endif; ?>
    <label class="news-search__box" for="news-q">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5B6372" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
      <input type="search" id="news-q" name="q" maxlength="100" value="<?= e($q) ?>" placeholder="<?= e(t('news.search')) ?>" aria-label="<?= e(t('news.search')) ?>" data-testid="news-search">
    </label>
    <?php if ($q !== ''): ?>
    <a class="btn btn--md btn--ghost" href="<?= e($base . $qs(['q' => ''])) ?>" data-testid="news-search-reset"><?= e(t('news.search_reset')) ?></a>
    <?php endif; ?>
  </form>
  <?php if ($kkMissing): ?>
  <a class="btn btn--md btn--ghost" href="<?= e($base . $qs(['kk' => ''])) ?>" data-testid="news-kk-reset"><?= e(t('news.kk_missing_reset')) ?></a>
  <?php endif; ?>
  <div class="news-filters" role="tablist" aria-label="<?= e(t('news.filter.label')) ?>">
    <?php foreach ($filters as $f): ?>
    <a class="news-filter" role="tab" href="<?= e($base . $qs(['status' => $f, 'page' => 1])) ?>" aria-selected="<?= $status === $f ? 'true' : 'false' ?>" data-testid="news-filter-<?= e($f) ?>"><?= e(t('news.filter.' . $f)) ?> <span class="news-filter__n"><?= (int)$counts[$f] ?></span></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="table-card" data-testid="news-list">
  <?php foreach ($items as $n):
    $id = (int)$n['id'];
    $thumb = $n['cover'] ? media_picture($n['cover'], 'thumb', ['sizes' => '88px']) : '';
    $statePill = ['published' => 'pill-state--published', 'scheduled' => 'pill-state--scheduled'][$n['state']] ?? 'pill-state--draft';
  ?>
  <div class="news-row" data-testid="news-row">
    <div class="news-row__thumb" data-testid="news-row-thumb">
      <?php if ($thumb !== ''): ?><?= $thumb ?><?php else: ?>
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-8 8"/></svg>
      <?php endif; ?>
    </div>
    <div class="news-row__main">
      <a class="news-row__title" href="<?= e($n['edit_url']) ?>" data-testid="news-row-title"><?= e($n['title']) ?></a>
      <div class="news-row__meta">
        <span data-testid="news-row-meta"><?= e($n['meta_date']) ?> · <?= e($n['author']) ?></span>
        <span class="lang-badge lang-badge--ok" data-testid="news-badge-ru">RU</span>
        <?php if ($n['kk_ready']): ?>
        <span class="lang-badge lang-badge--ok" data-testid="news-badge-kk"><?= e(t('news.badge.kk')) ?></span>
        <?php else: ?>
        <span class="lang-badge lang-badge--warn" data-testid="news-badge-kk"><?= e(t('news.badge.kk_missing')) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <span class="pill-state <?= $statePill ?>" data-testid="news-row-status"><?= e(t('news.state.' . $n['state'])) ?></span>
    <div class="news-row__actions">
      <a class="icon-btn" href="<?= e($n['edit_url']) ?>" aria-label="<?= e(t('news.action.edit')) ?>" title="<?= e(t('news.action.edit')) ?>" data-testid="news-row-edit"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16z"/></svg></a>
      <a class="icon-btn" href="<?= e($n['preview_url']) ?>" target="_blank" rel="noopener" aria-label="<?= e(t('news.action.preview')) ?>" title="<?= e(t('news.action.preview')) ?>" data-testid="news-row-preview"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></a>
      <form method="post" action="<?= e($base . '/' . $id . '/delete') ?>" data-confirm="<?= e(t('news.delete.confirm', ['title' => $n['title']])) ?>" data-once>
        <?= csrf_field() ?>
        <input type="hidden" name="q" value="<?= e($q) ?>">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <input type="hidden" name="page" value="<?= (int)$page ?>">
        <button type="submit" class="icon-btn icon-btn--danger" aria-label="<?= e(t('news.action.delete')) ?>" title="<?= e(t('news.action.delete')) ?>" data-testid="news-row-delete"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg></button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>

  <?php if (!$items): ?>
  <div class="news-empty" data-testid="news-empty">
    <?php if ($noneAtAll): ?>
    <p><?= e(t('news.empty.none')) ?></p>
    <a class="btn btn--md btn--create" href="<?= e($createUrl) ?>" data-testid="news-create"><?= $plus ?><?= e(t('news.create')) ?></a>
    <?php elseif ($q !== ''): ?>
    <p><?= e(t('news.empty.search', ['q' => $q])) ?></p>
    <?php else: ?>
    <p><?= e(t('news.empty.filter')) ?></p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($total > 0): ?>
  <div class="news-pager">
    <span data-testid="news-pager-summary"><?= e(t('news.pager.summary', ['from' => $from, 'to' => $to, 'total' => $total])) ?></span>
    <?php if ($pages > 1): ?>
    <nav class="news-pager__nav" aria-label="<?= e(t('news.pager.label')) ?>">
      <?php if ($page > 1): ?><a class="pager-link" href="<?= e($base . $qs(['page' => $page - 1])) ?>" rel="prev" data-testid="news-page-prev"><?= e(t('news.pager.prev')) ?></a><?php endif; ?>
      <?php foreach ($pageList as $p): ?>
        <?php if ($p === '…'): ?><span class="pager-gap" aria-hidden="true">…</span>
        <?php else: ?><a class="pager-link pager-link--num" href="<?= e($base . $qs(['page' => $p])) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?> data-testid="news-page-num"><?= (int)$p ?></a><?php endif; ?>
      <?php endforeach; ?>
      <?php if ($page < $pages): ?><a class="pager-link" href="<?= e($base . $qs(['page' => $page + 1])) ?>" rel="next" data-testid="news-page-next"><?= e(t('news.pager.next')) ?></a><?php endif; ?>
    </nav>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
