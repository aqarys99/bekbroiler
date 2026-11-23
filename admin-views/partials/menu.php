<?php /** @var array $menu @var array $user @var string $siteName */
$words = preg_split('/\s+/u', trim((string)$user['name'])) ?: [];
$initials = '';
foreach (array_slice(array_filter($words, 'strlen'), 0, 2) as $w) {
    $initials .= mb_strtoupper(mb_substr($w, 0, 1));
}
?>
<nav class="nav" id="admin-nav" data-admin-nav aria-label="<?= e(t('admin.menu')) ?>">
  <a class="nav__brand" href="<?= e(admin_url()) ?>" data-testid="nav-brand">
    <svg width="36" height="36" viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="18" fill="#FDF0E1"/><circle cx="18" cy="18" r="8" fill="#F2A516"/></svg>
    <span class="nav__brand-text"><b class="nav__brand-name"><?= e($siteName) ?></b><span class="nav__brand-sub"><?= e(t('admin.panel')) ?></span></span>
  </a>
  <?php foreach ($menu as $group): ?>
  <span class="nav__group"><?= e($group['label']) ?></span>
  <?php foreach ($group['items'] as $item): ?>
  <a class="nav__item" href="<?= e($item['url']) ?>" data-testid="<?= e($item['testid']) ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>><?php $icon = $item['icon']; include __DIR__ . '/icon.php'; ?><?= e($item['label']) ?></a>
  <?php endforeach; ?>
  <?php endforeach; ?>
  <div class="nav__foot">
    <a class="nav__user" href="<?= e(admin_url('/profile')) ?>" data-testid="user-card">
      <span class="avatar" aria-hidden="true"><?= e($initials) ?></span>
      <span class="nav__user-text"><b class="nav__user-name"><?= e($user['name']) ?></b><span class="nav__user-role"><?= e(t('role.' . $user['role'])) ?></span></span>
    </a>
    <form method="post" action="<?= e(admin_url('/logout')) ?>">
      <?= csrf_field() ?>
      <button type="submit" class="linkbtn" data-testid="nav-logout"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l5-5-5-5M15 12H3"/></svg><?= e(t('admin.logout')) ?></button>
    </form>
  </div>
</nav>
<div class="nav-backdrop" data-testid="nav-backdrop" data-nav-backdrop hidden></div>
