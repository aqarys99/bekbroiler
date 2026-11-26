<?php /** @var ?array $item @var string $key @var array $pages */ ?>
<div class="page-head">
  <div>
    <h1><?= e(t('documents.choose.title')) ?></h1>
    <p><?= e($item !== null ? t('documents.choose.lead_item', ['title' => $item['title']]) : t('documents.choose.lead')) ?></p>
  </div>
</div>
<div class="table-card" data-testid="documents-choose-list">
  <?php foreach ($pages as $p): ?>
  <div class="video-row" data-testid="documents-choose-row">
    <div class="video-row__main"><b class="video-row__title"><?= e($p['title']) ?></b><div class="video-row__meta"><span>/<?= e($p['slug']) ?></span></div></div>
    <a class="btn btn--sm" href="<?= e(\App\Controllers\Admin\DocumentsController::addUrl((int)$p['id'], $key)) ?>" data-testid="documents-choose-page-<?= (int)$p['id'] ?>"><?= e(t('documents.choose.pick')) ?></a>
  </div>
  <?php endforeach; ?>
  <?php if (!$pages): ?><p class="empty"><?= e(t('pages.empty')) ?> <a href="<?= e(admin_url('/pages/new')) ?>"><?= e(t('pages.add')) ?></a></p><?php endif; ?>
</div>
