<?php
/** @var array $rows @var string $base */
$total = count($rows);
?>
<div class="page-head">
  <div><h1><?= e(t('staff.title')) ?></h1><p><?= e(t('staff.lead')) ?></p></div>
  <a class="btn btn--md" href="<?= e($base . '/new') ?>" data-testid="staff-add"><?= e(t('staff.new')) ?></a>
</div>
<?php if (!$rows): ?>
<p class="empty" data-testid="staff-empty"><?= e(t('staff.empty')) ?></p>
<?php else: ?>
<div class="table-card" data-testid="staff-list">
  <?php foreach ($rows as $i => $r): $id = (int)$r['id']; ?>
  <div class="video-row" data-testid="staff-row" data-id="<?= $id ?>">
    <span class="video-thumb sec-thumb" data-testid="staff-row-photo"><?php if ($r['photo'] !== null): ?><?= media_picture($r['photo'], 'thumb') ?><?php else: ?><span aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string)$r['full_name'], 0, 1))) ?></span><?php endif; ?></span>
    <div class="video-row__main">
      <b class="video-row__title" data-testid="staff-row-name"><?= e($r['full_name']) ?></b>
      <div class="video-row__meta">
        <span data-testid="staff-row-meta"><?= e(implode(' · ', array_filter([
            (string)$r['position'],
            $r['category'] !== '' ? t('staff.cat.' . $r['category']) : '',
            $r['experience_years'] !== null ? t('staff.experience_n', ['n' => (int)$r['experience_years']]) : '',
        ]))) ?></span>
      </div>
    </div>
    <?php $on = (int)$r['is_published']; $k = 'staff'; include __DIR__ . '/../partials/section-toggle.php'; ?>
    <?php $confirm = t('staff.delete.confirm', ['title' => $r['full_name']]); include __DIR__ . '/../partials/section-actions.php'; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
