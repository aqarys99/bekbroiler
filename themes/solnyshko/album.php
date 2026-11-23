<?php /** @var array $album @var string $heading @var string $text */ ?>
<section class="page">
  <div class="container">
    <p class="back"><a href="<?= e(surl('/fotogalereya')) ?>">← <?= e(tt('gallery.back')) ?></a></p>
    <h1 data-testid="album-title"><?= e($heading) ?></h1>
    <?php if ($text !== ''): ?><p class="lead"><?= e($text) ?></p><?php endif; ?>
    <?php if (!$album['photos']): ?><p class="empty"><?= e(tt('empty')) ?></p><?php endif; ?>
    <div class="photo-grid" data-testid="album-photos">
      <?php foreach ($album['photos'] as $ph): $cap = trim((string)($ph['caption'] ?? '')) !== '' ? (string)$ph['caption'] : (string)$ph['alt']; ?>
        <a class="ph" href="<?= e(media_url($ph, 'large', 'jpg')) ?>" data-lb="album" data-caption="<?= e($cap) ?>"><?= media_picture($ph, 'thumb', ['alt' => $cap, 'sizes' => '(max-width: 600px) 50vw, 260px']) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
