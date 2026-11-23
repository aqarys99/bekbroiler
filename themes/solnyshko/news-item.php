<?php /** @var string $heading @var string $body @var ?array $cover @var array $photos @var string $date */ ?>
<article class="page">
  <div class="container narrow">
    <p class="back"><a href="<?= e(surl('/novosti')) ?>">← <?= e(tt('news.back')) ?></a></p>
    <time class="muted" datetime="<?= e(substr((string)$row['published_at'], 0, 10)) ?>"><?= e($date) ?></time>
    <h1 data-testid="news-title"><?= e($heading) ?></h1>
    <?php if ($cover !== null && ($pic = media_picture($cover, 'large', ['alt' => $heading, 'loading' => 'eager', 'sizes' => '(max-width: 800px) 100vw, 800px'])) !== ''): ?>
      <div class="news-hero" data-testid="news-cover"><?= $pic ?></div>
    <?php endif; ?>
    <div class="prose" data-testid="news-body"><?= $body /* санитизировано при сохранении */ ?></div>
    <?php if ($photos): ?>
      <h2 class="sub"><?= e(tt('news.photos')) ?></h2>
      <div class="photo-grid" data-testid="news-photos">
        <?php foreach ($photos as $ph): ?>
          <a class="ph" href="<?= e(media_url($ph, 'large', 'jpg')) ?>" data-lb="news" data-caption="<?= e((string)$ph['alt']) ?>"><?= media_picture($ph, 'thumb', ['sizes' => '(max-width: 600px) 50vw, 260px']) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</article>
