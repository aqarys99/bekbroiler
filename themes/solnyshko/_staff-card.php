<?php
/** Карточка педагога. @var array $s @var int $i */
$name = loc($s, 'full_name', null, false);
$initials = '';
foreach (array_slice(preg_split('/\s+/u', $name) ?: [], 0, 2) as $w) {
    $initials .= mb_strtoupper(mb_substr($w, 0, 1));
}
$pos = loc($s, 'position');
?>
<div class="card teacher" data-testid="staff-card">
  <?php if (is_array($s['photo']) && ($pic = media_picture($s['photo'], 'thumb', ['alt' => $name, 'sizes' => '96px'])) !== ''): ?>
    <div class="avatar avatar-img"><?= $pic ?></div>
  <?php else: ?>
    <div class="avatar av-<?= (int)$i % 4 ?>" aria-hidden="true"><?= e($initials) ?></div>
  <?php endif; ?>
  <h3><?= e($name) ?></h3>
  <p><?= e($pos) ?></p>
  <?php if (trim((string)$s['category']) !== ''): ?><span class="tag"><?= e($s['category']) ?></span><?php endif; ?>
  <?php if ($s['experience_years'] !== null && $s['experience_years'] !== ''): ?><span class="tag"><?= e(tt('staff.exp', ['n' => (int)$s['experience_years']])) ?></span><?php endif; ?>
  <?php if (($edu = loc($s, 'education')) !== ''): ?><p class="edu"><?= e($edu) ?></p><?php endif; ?>
</div>
