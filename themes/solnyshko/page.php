<?php
/** Текстовая страница (о нас, программа, режим, питание, условия). @var array $row @var string $heading @var string $body @var array $docs */
use App\Site\Ctx;
?>
<section class="page">
  <div class="container narrow">
    <h1 data-testid="page-title"><?= e($heading) ?></h1>
    <?php if (trim(strip_tags($body, '<img><div>')) !== ''): ?><div class="prose" data-testid="page-body"><?= $body /* санитизировано при сохранении */ ?></div><?php endif; ?>
    <?php if (!empty($row['schedule'])): ?>
      <div class="schedule" data-testid="page-schedule">
        <?php foreach ($row['schedule'] as $it): ?>
          <div class="slot"><time><?= e($it['time_range']) ?></time><span><?= e(loc($it, 'text')) ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($docs || trim(strip_tags($body, '<img><div>')) === ''): ?>
    <div class="page-docs" data-testid="page-docs">
      <h2><?= e(tt('page.docs')) ?></h2>
      <?php if (!$docs): ?><p class="empty" data-testid="page-docs-empty"><?= e(tt('page.docs_empty')) ?></p><?php endif; ?>
      <div class="grid grid-2">
      <?php foreach ($docs as $d):
          $isPdf = $d['ext'] === 'PDF'; $isDocx = $d['ext'] === 'DOCX'; ?>
        <div class="card doc doc-card" data-testid="page-doc">
          <span class="icon i-y ext" aria-hidden="true" data-testid="page-doc-type"><?= e($d['ext']) ?></span>
          <span class="doc-card__main">
            <b data-testid="page-doc-title"><?= e(loc($d, 'title')) ?></b>
            <small><?= e($d['ext']) ?><?= !empty($d['doc_date']) ? ' · ' . e(date('d.m.Y', (int)strtotime((string)$d['doc_date']))) : '' ?></small>
          </span>
          <span class="doc-card__act">
            <?php if ($d['can_view']): ?>
              <a class="btn btn-primary btn-sm" href="<?= e(surl('/dokumenty/' . (int)$d['id'])) ?>" data-testid="page-doc-open"><?= e(tt('page.doc_open')) ?></a>
            <?php else: ?>
              <a class="btn btn-primary btn-sm" href="<?= e($d['url']) ?>" target="_blank" rel="noopener" data-testid="page-doc-open"><?= e(tt('page.doc_open')) ?></a>
            <?php endif; ?>
            <a class="btn btn-outline btn-sm" href="<?= e($d['url']) ?>" download data-testid="page-doc-download"><?= e(tt('page.doc_download')) ?></a>
          </span>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>
