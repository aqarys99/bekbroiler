<?php
/** Карточка новости. @var array $n строка из News::published() */
use App\Site\Ctx;

$kkOk = Ctx::isKk() && !empty($n['kk_ready']);
$ntitle = $kkOk ? (string)$n['kk_title'] : (string)$n['title'];
$nexcerpt = $kkOk && trim((string)$n['kk_excerpt']) !== '' ? (string)$n['kk_excerpt'] : (string)$n['excerpt'];
$nlink = surl('/novosti/' . $n['slug']);
?>
<article class="card news-card" data-testid="news-card">
  <?php if (is_array($n['cover']) && ($pic = media_picture($n['cover'], 'medium', ['alt' => '', 'sizes' => '(max-width: 600px) 100vw, 360px'])) !== ''): ?>
    <a class="news-cover" href="<?= e($nlink) ?>" tabindex="-1" aria-hidden="true"><?= $pic ?></a>
  <?php endif; ?>
  <time class="muted" datetime="<?= e(substr((string)$n['published_at'], 0, 10)) ?>"><?= e(\App\Core\Clock::toLocal((string)$n['published_at'])->format('d.m.Y')) ?></time>
  <h3><a href="<?= e($nlink) ?>"><?= e($ntitle) ?></a></h3>
  <?php if ($nexcerpt !== ''): ?><p><?= e($nexcerpt) ?></p><?php endif; ?>
  <?php if (Ctx::isKk() && !$kkOk): ?><span class="tag"><?= e(tt('missing.short')) ?></span><?php endif; ?>
</article>
