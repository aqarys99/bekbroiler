<?php /** @var ?array $item @var string $key @var array $rows */ ?>
<div class="page-head">
  <div>
    <h1><?= e(t('attestation.choose.title')) ?></h1>
    <p><?= e($item !== null ? t('attestation.choose.lead_item', ['title' => $item['title']]) : t('attestation.choose.lead')) ?></p>
  </div>
  <a class="btn btn--md btn--ghost" href="<?= e(admin_url('/attestation/new')) ?>" data-testid="att-choose-new"><?= e(t('attestation.add')) ?></a>
</div>
<div class="table-card" data-testid="att-choose-list">
  <?php foreach ($rows as $p): $href = admin_url('/attestation/' . (int)$p['id']) . ($key !== '' ? '?' . http_build_query(['checklist' => $key]) : '') . '#att-add'; ?>
  <div class="video-row" data-testid="att-choose-row">
    <div class="video-row__main"><b class="video-row__title"><?= e($p['title']) ?></b><div class="video-row__meta"><span><?= e(t('attestation.files_count', ['n' => (int)$p['docs_count']])) ?></span></div></div>
    <a class="btn btn--sm" href="<?= e($href) ?>" data-testid="att-choose-page-<?= (int)$p['id'] ?>"><?= e(t('documents.choose.pick')) ?></a>
  </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="empty" data-testid="att-choose-empty"><?= e(t('attestation.empty')) ?></p><?php endif; ?>
</div>
