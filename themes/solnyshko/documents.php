<?php /** @var array $groups Document::publishedByPage() */ ?>
<section class="page">
  <div class="container">
    <div class="section-head"><span class="eyebrow"><?= e(tt('documents.eyebrow')) ?></span><h1><?= e(tt('nav.documents')) ?></h1><p><?= e(tt('documents.lead')) ?></p></div>
    <?php if (!$groups): ?><p class="empty"><?= e(tt('empty')) ?></p><?php endif; ?>
    <?php foreach ($groups as $g): ?>
      <h2 class="sub" data-testid="documents-category"><a href="<?= e(surl('/' . $g['page']['slug'])) ?>"><?= e(loc($g['page'], 'title')) ?></a></h2>
      <div class="grid grid-2">
        <?php foreach ($g['documents'] as $d):
            $isPdf = $d['can_view'];
            $img = in_array($d['ext'], ['JPG', 'JPEG', 'PNG'], true); ?>
          <a class="card doc" href="<?= e($isPdf ? surl('/dokumenty/' . (int)$d['id']) : $d['url']) ?>" data-testid="documents-item"
             <?= $isPdf ? '' : ($img ? 'target="_blank" rel="noopener"' : 'download') ?>>
            <span class="icon i-y ext" aria-hidden="true"><?= e($d['ext']) ?></span>
            <span>
              <b><?= e(loc($d, 'title')) ?></b>
              <small><?= e($d['ext']) ?><?= !empty($d['doc_date']) ? ' · ' . e(date('d.m.Y', (int)strtotime((string)$d['doc_date']))) : '' ?> · <?= e($isPdf || $img ? tt('documents.open') : tt('documents.download')) ?></small>
            </span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>
