<?php /** @var array $breadcrumbs */ ?>
<header class="topbar">
  <nav class="crumbs" aria-label="<?= e(t('admin.breadcrumbs')) ?>" data-testid="header-breadcrumbs">
    <ol>
      <?php foreach ($breadcrumbs as $i => $c): $last = $i === count($breadcrumbs) - 1; ?>
      <li><?php if (!$last && !empty($c['url'])): ?><a href="<?= e($c['url']) ?>" data-testid="breadcrumb-link"><?= e($c['label']) ?></a><?php else: ?><span<?= $last ? ' aria-current="page"' : '' ?>><?= e($c['label']) ?></span><?php endif; ?></li>
      <?php endforeach; ?>
    </ol>
  </nav>
  <a class="btn btn--outline" href="<?= e(url('/')) ?>" target="_blank" rel="noopener" data-testid="header-open-site"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></svg><?= e(t('admin.open_site')) ?></a>
</header>
