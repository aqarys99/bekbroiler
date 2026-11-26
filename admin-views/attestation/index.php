<?php /** @var array $rows */ $base = admin_url('/attestation'); $k = 'att'; ?>
<div class="page-head">
  <div><h1><?= e(t('attestation.title')) ?></h1><p><?= e(t('attestation.lead')) ?></p></div>
  <a class="btn btn--md" href="<?= e($base . '/new') ?>" data-testid="att-add"><?= e(t('attestation.add')) ?></a>
</div>
<div class="table-card" data-testid="att-list">
  <?php foreach ($rows as $n => $r): $id = (int)$r['id']; ?>
  <div class="video-row" data-testid="att-row" data-slug="<?= e($r['slug']) ?>">
    <div class="video-row__main">
      <a class="video-row__title" href="<?= e($base . '/' . $id) ?>" data-testid="att-row-title"><b><?= e($r['title']) ?></b></a>
      <div class="video-row__meta">
        <span data-testid="att-row-files"><?= e(t('attestation.files_count', ['n' => (int)$r['docs_count']])) ?></span>
        <?php if (!$r['in_group']): ?><span class="lang-badge" data-testid="att-row-nomenu"><?= e(t('attestation.not_in_menu')) ?></span><?php endif; ?>
        <?php if ((int)$r['is_published'] === 1): ?><a href="<?= e(url('/' . $r['slug'])) ?>" target="_blank" rel="noopener" data-testid="att-row-open"><?= e(t('attestation.open_site')) ?></a><?php endif; ?>
      </div>
    </div>
    <?php $on = (int)$r['is_published']; include __DIR__ . '/../partials/section-toggle.php'; ?>
    <div class="doc-row__actions">
      <?php foreach (['up' => ['<path d="m6 15 6-6 6 6"/>', $n === 0], 'down' => ['<path d="m6 9 6 6 6-6"/>', $n === count($rows) - 1]] as $dir => [$path, $off]): ?>
      <form method="post" action="<?= e($base . '/' . $id . '/move') ?>" data-once>
        <?= csrf_field() ?><input type="hidden" name="dir" value="<?= $dir ?>">
        <button type="submit" class="icon-btn" aria-label="<?= e(t('sections.' . $dir)) ?>" title="<?= e(t('sections.' . $dir)) ?>"<?= $off ? ' disabled' : '' ?> data-testid="att-row-<?= $dir ?>"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $path ?></svg></button>
      </form>
      <?php endforeach; ?>
      <a class="btn btn--sm" href="<?= e($base . '/' . $id) ?>" data-testid="att-row-edit"><?= e(t('attestation.edit')) ?></a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?><p class="empty" data-testid="att-empty"><?= e(t('attestation.empty')) ?></p><?php endif; ?>
</div>
