<?php /** @var array{items:array,total:int,page:int,pages:int} $news */ ?>
<section class="page">
  <div class="container">
    <div class="section-head"><span class="eyebrow"><?= e(tt('home.news_eyebrow')) ?></span><h1><?= e(tt('nav.news')) ?></h1></div>
    <?php if (!$news['items']): ?><p class="empty"><?= e(tt('empty')) ?></p><?php endif; ?>
    <div class="grid grid-3" data-testid="news-list">
      <?php foreach ($news['items'] as $n): include \App\Site\Theme::find('_news-card'); endforeach; ?>
    </div>
    <?php if ($news['pages'] > 1): ?>
      <nav class="pager" aria-label="<?= e(tt('pager.label')) ?>" data-testid="news-pager">
        <?php if ($news['page'] > 1): ?><a rel="prev" href="<?= e(surl('/novosti', $news['page'] > 2 ? ['page' => $news['page'] - 1] : [])) ?>">← <?= e(tt('pager.prev')) ?></a><?php endif; ?>
        <?php for ($i = 1; $i <= $news['pages']; $i++): ?>
          <a href="<?= e(surl('/novosti', $i > 1 ? ['page' => $i] : [])) ?>"<?= $i === $news['page'] ? ' aria-current="page"' : '' ?>><?= $i ?></a>
        <?php endfor; ?>
        <?php if ($news['page'] < $news['pages']): ?><a rel="next" href="<?= e(surl('/novosti', ['page' => $news['page'] + 1])) ?>"><?= e(tt('pager.next')) ?> →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </div>
</section>
