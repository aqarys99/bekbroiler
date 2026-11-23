<?php
/**
 * Поле «Выбрать из медиатеки». Подключается в формах фич 04/06/09/10:
 *   $mf = ['name' => 'cover_media_id', 'label' => '...', 'multiple' => false,
 *          'values' => Media::pickerValues($ids), 'upload' => [...], 'pickerUrl' => '...', 'maxLabel' => '...'];
 *   include __DIR__ . '/../partials/media-field.php';
 * Скрипты (uploader.js, media-picker.js) подключаются один раз на странице: $mfScripts = true (по умолчанию).
 * Скрытые input'ы создаёт media-picker.js: name (одиночный) или name[] (множественный).
 *
 * @var array $mf
 */
$multiple = !empty($mf['multiple']);
$maxLabel = (string)$mf['maxLabel'];
include __DIR__ . '/media-i18n.php';
$state = [];
foreach ($mf['values'] as $v) {
    $m = $v['media'];
    $state[] = $m === null
        ? ['id' => (int)$v['id'], 'deleted' => true]
        : ['id' => (int)$v['id'], 'deleted' => false] + \App\Models\Media::pickerItem($m);
}
$up = $mf['upload'];
$fid = 'mf-' . preg_replace('/[^a-z0-9_-]/i', '', (string)$mf['name']);
?>
<div class="field mpick" data-media-picker data-testid="media-picker-field" data-name="<?= e($mf['name']) ?>" data-multiple="<?= $multiple ? '1' : '0' ?>"
  data-picker-url="<?= e($mf['pickerUrl']) ?>" data-url="<?= e($up['url']) ?>" data-max-bytes="<?= (int)$up['maxBytes'] ?>" data-max-side="<?= (int)$up['maxSide'] ?>"
  data-quality="<?= e((string)$up['quality']) ?>" data-csrf="<?= e($up['csrf']) ?>" data-i18n="<?= e($mediaI18nJson) ?>" data-state="<?= e(json_encode($state, JSON_UNESCAPED_UNICODE)) ?>">
  <span class="field__label" id="<?= e($fid) ?>-label"><?= e($mf['label']) ?></span>
  <div class="mpick__preview" data-role="preview" data-testid="media-picker-preview" aria-labelledby="<?= e($fid) ?>-label"></div>
  <div data-role="inputs"></div>
  <button type="button" class="btn btn--md btn--ghost" data-role="open" data-testid="media-picker-open"><?= e(t('picker.open')) ?></button>
</div>
<?php if (($mfScripts ?? true) !== false): ?>
<script src="<?= e(asset('admin/uploader.js')) ?>" defer></script>
<script src="<?= e(asset('admin/media-picker.js')) ?>" defer></script>
<?php endif; ?>
