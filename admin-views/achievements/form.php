<?php
/** @var ?array $row @var array $old @var array $errors @var string $base @var array $mf */
$k = 'achievements';
?>
<div class="page-head"><h1><?= e($row === null ? t('achievements.new') : $row['title']) ?></h1></div>
<div class="card card--form">
  <form class="form" method="post" action="<?= e($row === null ? $base : $base . '/' . (int)$row['id']) ?>" novalidate data-once data-testid="achievements-form">
    <?= csrf_field() ?>
    <?php foreach ([
        ['name' => 'year', 'label' => t('achievements.field.year'), 'testid' => 'achievements-form-year', 'required' => true, 'numeric' => true, 'max' => 4],
        ['name' => 'title', 'label' => t('achievements.field.title'), 'testid' => 'achievements-form-title', 'required' => true, 'max' => 150],
        ['name' => 'title_kk', 'label' => t('achievements.field.title_kk'), 'testid' => 'achievements-form-title-kk', 'max' => 150],
        ['name' => 'description', 'label' => t('achievements.field.description'), 'testid' => 'achievements-form-description', 'area' => true, 'max' => 1000],
        ['name' => 'description_kk', 'label' => t('achievements.field.description_kk'), 'testid' => 'achievements-form-description-kk', 'area' => true, 'max' => 1000],
    ] as $fd) { include __DIR__ . '/../partials/section-field.php'; } ?>
    <?php include __DIR__ . '/../partials/media-field.php'; ?>
    <?php if (!empty($errors['media_id'])): ?><span class="field__error" data-testid="achievements-form-photo-error"><?= e($errors['media_id']) ?></span><?php endif; ?>
    <?php include __DIR__ . '/../partials/section-form-end.php'; ?>
  </form>
</div>
