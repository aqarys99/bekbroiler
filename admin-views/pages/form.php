<?php
/** @var ?array $row @var bool $isNew @var array $docs @var array $docValues @var array $docErrors @var bool $docOpen @var array $checkItems @var string $maxLabel @var string $accept @var array $old @var array $errors @var array $rowErrors @var ?array $schedule */
$base = admin_url('/pages'); $k = 'pages';
$formAction = $isNew ? $base : $base . '/' . (int)$row['id'];
$err = static fn(string $n): string => !empty($errors[$n]) ? '<span class="field__error" data-testid="pages-' . e($n) . '-error">' . e($errors[$n]) . '</span>' : '';
$ro = trim((string)($old['body'] ?? '')) === '' ? '' : \App\Core\HtmlSanitizer::clean((string)$old['body']);
$ko = trim((string)($old['body_kk'] ?? '')) === '' ? '' : \App\Core\HtmlSanitizer::clean((string)$old['body_kk']);
?>
<div class="page-head"><div><h1><?= e($isNew ? t('pages.new') : $row['title']) ?></h1><?php if (!$isNew): ?><p>/<?= e($row['slug']) ?></p><?php endif; ?></div></div>
<div class="card card--form">
  <form class="form" method="post" action="<?= e($formAction) ?>" novalidate data-once data-testid="pages-form" data-page-form data-slug-url="<?= e($base . '/slug') ?>" data-page-id="<?= $isNew ? 0 : (int)$row['id'] ?>" data-slug-auto="<?= $isNew && trim((string)($old['slug'] ?? '')) === '' ? '1' : '0' ?>">
    <?= csrf_field() ?>
    <?php foreach ([
        ['name' => 'title', 'label' => t('pages.field.title'), 'testid' => 'pages-form-title', 'required' => true, 'max' => 150],
        ['name' => 'title_kk', 'label' => t('pages.field.title_kk'), 'testid' => 'pages-form-title-kk', 'max' => 150],
    ] as $fd) { include __DIR__ . '/../partials/section-field.php'; } ?>
    <div class="field" data-field="slug">
      <label for="f-slug"><?= e(t('pages.field.slug')) ?></label>
      <div class="slug-row"><span class="slug-row__base">/</span><input class="input" type="text" id="f-slug" name="slug" maxlength="80" value="<?= e((string)($old['slug'] ?? '')) ?>" autocomplete="off" data-testid="pages-form-slug"<?= !empty($errors['slug']) ? ' aria-invalid="true"' : '' ?>></div>
      <span class="field__hint"><?= e(t('pages.field.slug_hint')) ?></span>
      <?= $err('slug') ?>
    </div>
    <div class="field" data-field="body">
      <span class="field__label" id="p-body"><?= e(t('pages.field.body')) ?> <span class="muted"><?= e(t('pages.field.optional')) ?></span></span>
      <?php $ed = ['name' => 'body', 'labelId' => 'p-body', 'testid' => 'pages-form-body', 'html' => $ro, 'nophoto' => true]; include __DIR__ . '/../partials/editor.php'; ?>
      <?= $err('body') ?>
    </div>
    <div class="field" data-field="body_kk">
      <span class="field__label" id="p-body-kk"><?= e(t('pages.field.body_kk')) ?></span>
      <?php $ed = ['name' => 'body_kk', 'labelId' => 'p-body-kk', 'testid' => 'pages-form-body-kk', 'html' => $ko, 'nophoto' => true, 'placeholder' => t('pages.placeholder.body_kk')]; include __DIR__ . '/../partials/editor.php'; ?>
      <?= $err('body_kk') ?>
    </div>
    <?php if ($schedule !== null): ?>
    <section class="field sched" data-schedule data-testid="pages-schedule" data-i18n="<?= e(json_encode(['up' => t('sections.up'), 'down' => t('sections.down'), 'del' => t('sections.delete')], JSON_UNESCAPED_UNICODE)) ?>">
      <span class="field__label"><?= e(t('pages.schedule.title')) ?></span>
      <p class="field__hint"><?= e(t('pages.schedule.hint')) ?></p>
      <?= $err('schedule') ?>
      <div data-role="rows">
        <?php foreach ($schedule as $n => $s) { $srow = $s; $serr = $rowErrors[$n] ?? null; include __DIR__ . '/_schedule_row.php'; } ?>
      </div>
      <template data-role="tpl"><?php $srow = ['time_range' => '', 'text' => '', 'text_kk' => '']; $serr = null; include __DIR__ . '/_schedule_row.php'; ?></template>
      <button type="button" class="btn btn--md btn--ghost" data-role="add" data-testid="pages-schedule-add"><?= e(t('pages.schedule.add')) ?></button>
    </section>
    <script src="<?= e(asset('admin/sections.js')) ?>" defer></script>
    <?php endif; ?>
    <label class="check"><input type="checkbox" name="is_published" value="1"<?= !empty($old['is_published']) ? ' checked' : '' ?> data-testid="pages-form-published"> <?= e(t('sections.show_on_site')) ?></label>
    <div class="actions">
      <button type="submit" class="btn btn--md" data-testid="pages-form-submit"><?= e(t('sections.save')) ?></button>
      <a class="btn btn--md btn--ghost" href="<?= e($base) ?>" data-testid="pages-form-cancel"><?= e(t('sections.cancel')) ?></a>
    </div>
  </form>
  <?php if (!$isNew): ?>
  <form class="page-delete" method="post" action="<?= e($base . '/' . (int)$row['id'] . '/delete') ?>" data-confirm="<?= e(t('pages.delete.confirm', ['title' => $row['title']])) ?>" data-once>
    <?= csrf_field() ?>
    <button type="submit" class="btn btn--md btn--ghost btn--danger" data-testid="pages-form-delete"><?= e(t('pages.delete')) ?></button>
  </form>
  <?php endif; ?>
</div>
<?php if (!$isNew) { include __DIR__ . '/_docs.php'; } ?>
<script src="<?= e(asset('admin/pages.js')) ?>" defer></script>
<script src="<?= e(asset('admin/editor.js')) ?>" defer></script>
