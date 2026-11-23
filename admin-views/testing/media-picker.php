<?php
/** @var array $coverValues @var array $photosValues @var string $maxLabel @var array $upload @var string $pickerUrl @var ?array $result */
$common = ['upload' => $upload, 'pickerUrl' => $pickerUrl, 'maxLabel' => $maxLabel];
?>
<div class="page-head"><h1>Тест выбора фото</h1></div>
<form class="card card--form form" method="post" action="<?= e(url('/__test/media-picker')) ?>" novalidate>
  <?= csrf_field() ?>
  <?php
  $mfScripts = false;
  $mf = $common + ['name' => 'cover_media_id', 'label' => 'Обложка', 'multiple' => false, 'values' => $coverValues];
  include __DIR__ . '/../partials/media-field.php';
  $mf = $common + ['name' => 'photos', 'label' => 'Фото', 'multiple' => true, 'values' => $photosValues];
  include __DIR__ . '/../partials/media-field.php';
  ?>
  <div class="actions"><button type="submit" class="btn btn--md" data-testid="test-picker-submit">Отправить</button></div>
</form>
<?php if ($result !== null): ?>
<pre class="card" data-testid="test-picker-result"><?= e(json_encode($result, JSON_UNESCAPED_UNICODE)) ?></pre>
<?php endif; ?>
<script src="<?= e(asset('admin/uploader.js')) ?>" defer></script>
<script src="<?= e(asset('admin/media-picker.js')) ?>" defer></script>
