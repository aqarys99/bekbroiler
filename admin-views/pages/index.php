<?php /** @var array $rows @var string $q @var string $status */ $base = admin_url('/pages'); $k = 'pages'; ?>
<div class="page-head">
  <div><h1><?= e(t('pages.title')) ?></h1><p><?= e(t('pages.lead')) ?></p></div>
  <a class="btn btn--md" href="<?= e($base . '/new') ?>" data-testid="pages-add"><?= e(t('pages.add')) ?></a>
</div>
<form class="news-filters pages-filter" method="get" action="<?= e($base) ?>" role="search" data-testid="pages-filter">
  <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('pages.search')) ?>" aria-label="<?= e(t('pages.search')) ?>" data-testid="pages-search">
  <select class="input select" name="status" aria-label="<?= e(t('pages.status')) ?>" data-autosubmit data-testid="pages-status">
    <option value="all"<?= $status === 'all' ? ' selected' : '' ?>><?= e(t('pages.status.all')) ?></option>
    <option value="shown"<?= $status === 'shown' ? ' selected' : '' ?>><?= e(t('sections.on_site')) ?></option>
    <option value="hidden"<?= $status === 'hidden' ? ' selected' : '' ?>><?= e(t('sections.hidden')) ?></option>
  </select>
  <button type="submit" class="btn btn--md btn--ghost" data-testid="pages-search-submit"><?= e(t('pages.find')) ?></button>
</form>
<div class="table-card" data-testid="pages-list">
  <?php foreach ($rows as $r): $id = (int)$r['id']; ?>
  <div class="video-row" data-testid="pages-row" data-slug="<?= e($r['slug']) ?>">
    <div class="video-row__main">
      <b class="video-row__title" data-testid="pages-row-title"><?= e($r['title']) ?></b>
      <div class="video-row__meta">
        <span>/<?= e($r['slug']) ?></span>
        <span data-testid="pages-row-docs"><?= e(t('pages.docs_count', ['n' => (int)$r['docs_count']])) ?></span>
        <span data-testid="pages-row-kk"><?= trim((string)$r['title_kk']) === '' ? e(t('pages.kk_missing')) : e(t('pages.kk_ok')) ?></span>
      </div>
    </div>
    <?php $on = (int)$r['is_published']; include __DIR__ . '/../partials/section-toggle.php'; ?>
    <div class="doc-row__actions">
      <a class="btn btn--sm" href="<?= e($base . '/' . $id) ?>" data-testid="pages-row-edit"><?= e(t('pages.edit')) ?></a>
      <form method="post" action="<?= e($base . '/' . $id . '/delete') ?>" data-confirm="<?= e(t('pages.delete.confirm', ['title' => $r['title']])) ?>" data-once>
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--sm btn--ghost" data-testid="pages-row-delete"><?= e(t('sections.delete')) ?></button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="empty" data-testid="pages-empty"><?= e(t('pages.empty')) ?></p><?php endif; ?>
</div>
