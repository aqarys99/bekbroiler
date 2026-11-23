<?php
/** @var ?array $row @var array $old @var array $errors @var string $base @var array $mf */
$k = 'staff';
$flds = [
    ['name' => 'full_name', 'label' => t('staff.field.full_name'), 'testid' => 'staff-form-name', 'required' => true, 'max' => 150],
    ['name' => 'full_name_kk', 'label' => t('staff.field.full_name_kk'), 'testid' => 'staff-form-name-kk', 'max' => 150],
    ['name' => 'position', 'label' => t('staff.field.position'), 'testid' => 'staff-form-position', 'required' => true, 'max' => 150],
    ['name' => 'position_kk', 'label' => t('staff.field.position_kk'), 'testid' => 'staff-form-position-kk', 'max' => 150],
];
?>
<div class="page-head"><h1><?= e($row === null ? t('staff.new') : $row['full_name']) ?></h1></div>
<div class="card card--form">
  <form class="form" method="post" action="<?= e($row === null ? $base : $base . '/' . (int)$row['id']) ?>" novalidate data-once data-testid="staff-form">
    <?= csrf_field() ?>
    <?php foreach ($flds as $fd) { include __DIR__ . '/../partials/section-field.php'; } ?>
    <div class="field" data-field="category">
      <label for="f-category"><?= e(t('staff.field.category')) ?></label>
      <select class="input" id="f-category" name="category" data-testid="staff-form-category">
        <option value=""><?= e(t('staff.cat.none')) ?></option>
        <?php foreach (\App\Models\Staff::CATEGORIES as $c): ?>
        <option value="<?= e($c) ?>"<?= ($old['category'] ?? '') === $c ? ' selected' : '' ?>><?= e(t('staff.cat.' . $c)) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($errors['category'])): ?><span class="field__error" data-testid="staff-form-category-error"><?= e($errors['category']) ?></span><?php endif; ?>
    </div>
    <?php
    foreach ([
        ['name' => 'education', 'label' => t('staff.field.education'), 'testid' => 'staff-form-education', 'max' => 300],
        ['name' => 'education_kk', 'label' => t('staff.field.education_kk'), 'testid' => 'staff-form-education-kk', 'max' => 300],
        ['name' => 'experience_years', 'label' => t('staff.field.experience'), 'testid' => 'staff-form-experience', 'numeric' => true, 'max' => 2],
    ] as $fd) { include __DIR__ . '/../partials/section-field.php'; }
    include __DIR__ . '/../partials/media-field.php';
    if (!empty($errors['photo_media_id'])): ?><span class="field__error" data-testid="staff-form-photo-error"><?= e($errors['photo_media_id']) ?></span><?php endif;
    include __DIR__ . '/../partials/section-form-end.php'; ?>
  </form>
</div>
