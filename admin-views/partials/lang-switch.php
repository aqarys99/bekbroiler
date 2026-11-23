<?php
/** Переключатель языка интерфейса RU / ҚАЗ. @var string $langNext */
$cur = \App\Core\Locale::current();
$next = $langNext ?? (string)($_SERVER['REQUEST_URI'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    $next = '';
}
$opts = ['ru' => 'RU', 'kk' => 'ҚАЗ'];
?>
<form class="langsw" method="post" action="<?= e(admin_url('/lang')) ?>" role="group" aria-label="<?= e(t('admin.lang')) ?>" data-testid="lang-switch">
  <?= csrf_field() ?>
  <input type="hidden" name="next" value="<?= e($next) ?>">
  <?php foreach ($opts as $code => $label): ?>
  <button type="submit" name="lang" value="<?= e($code) ?>" aria-pressed="<?= $cur === $code ? 'true' : 'false' ?>" data-testid="lang-<?= e($code) ?>"><?= e($label) ?></button>
  <?php endforeach; ?>
</form>
