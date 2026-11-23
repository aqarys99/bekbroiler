<?php
/** @var ?array $row @var array $old @var array $errors @var string $base @var array $staffOptions */
$k = 'groups';
$sel = array_map('intval', (array)($old['teacher_ids'] ?? []));
?>
<div class="page-head"><h1><?= e($row === null ? t('groups.new') : $row['name']) ?></h1></div>
<div class="card card--form">
  <form class="form" method="post" action="<?= e($row === null ? $base : $base . '/' . (int)$row['id']) ?>" novalidate data-once data-testid="groups-form">
    <?= csrf_field() ?>
    <?php foreach ([
        ['name' => 'name', 'label' => t('groups.field.name'), 'testid' => 'groups-form-name', 'required' => true, 'max' => 100],
        ['name' => 'name_kk', 'label' => t('groups.field.name_kk'), 'testid' => 'groups-form-name-kk', 'max' => 100],
        ['name' => 'age_range', 'label' => t('groups.field.age'), 'testid' => 'groups-form-age', 'max' => 50, 'hint' => t('groups.field.age_hint')],
        ['name' => 'age_range_kk', 'label' => t('groups.field.age_kk'), 'testid' => 'groups-form-age-kk', 'max' => 50],
        ['name' => 'children_count', 'label' => t('groups.field.children'), 'testid' => 'groups-form-children', 'numeric' => true, 'max' => 4],
        ['name' => 'groups_count', 'label' => t('groups.field.count'), 'testid' => 'groups-form-count', 'numeric' => true, 'max' => 3],
    ] as $fd) { include __DIR__ . '/../partials/section-field.php'; } ?>
    <fieldset class="field" data-field="teacher_ids">
      <legend class="field__label"><?= e(t('groups.field.teachers')) ?></legend>
      <?php if (!$staffOptions): ?>
      <p class="muted" data-testid="groups-no-staff"><?= e(t('groups.no_staff')) ?> <a href="<?= e(admin_url('/staff/new')) ?>"><?= e(t('staff.new')) ?></a></p>
      <?php endif; ?>
      <?php foreach ($staffOptions as $sid => $name): ?>
      <label class="check"><input type="checkbox" name="teacher_ids[]" value="<?= (int)$sid ?>"<?= in_array((int)$sid, $sel, true) ? ' checked' : '' ?> data-testid="groups-form-teacher-<?= (int)$sid ?>"> <?= e($name) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <?php include __DIR__ . '/../partials/section-form-end.php'; ?>
  </form>
</div>
