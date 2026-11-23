<?php /** @var array $rows */ $base = admin_url('/pages'); $k = 'pages'; ?>
<div class="page-head"><div><h1><?= e(t('pages.title')) ?></h1><p><?= e(t('pages.lead')) ?></p></div></div>
<div class="table-card" data-testid="pages-list">
  <?php foreach ($rows as $r): $id = (int)$r['id']; ?>
  <div class="video-row" data-testid="pages-row" data-slug="<?= e($r['slug']) ?>">
    <div class="video-row__main">
      <b class="video-row__title" data-testid="pages-row-title"><?= e($r['title']) ?></b>
      <div class="video-row__meta">
        <span>/<?= e($r['slug']) ?></span>
        <span data-testid="pages-row-kk"><?= trim((string)$r['body_kk']) === '' ? e(t('pages.kk_missing')) : e(t('pages.kk_ok')) ?></span>
      </div>
    </div>
    <?php $on = (int)$r['is_published']; include __DIR__ . '/../partials/section-toggle.php'; ?>
    <div class="doc-row__actions">
      <a class="btn btn--sm" href="<?= e($base . '/' . $id) ?>" data-testid="pages-row-edit"><?= e(t('pages.edit')) ?></a>
    </div>
  </div>
  <?php endforeach; ?>
</div>
