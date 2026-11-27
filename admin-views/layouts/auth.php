<?php
/** @var string $content @var string $title */
$wide = isset($requirements);
?><!doctype html>
<html lang="<?= e(\App\Core\Locale::current()) ?>" data-units="<?= e(t('unit.b') . '|' . t('unit.kb') . '|' . t('unit.mb') . '|' . t('unit.gb')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('fonts/montserrat.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('admin/admin.css')) ?>">
<script src="<?= e(asset('admin/admin.js')) ?>" defer></script>
</head>
<body>
<main class="auth">
  <div class="auth__card<?= $wide ? ' auth__card--wide' : '' ?>">
    <div class="auth__lang">
      <?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'install.php'): ?>
      <div class="langsw" role="group" aria-label="<?= e(t('admin.lang')) ?>" data-testid="lang-switch">
        <?php foreach (['ru' => 'RU', 'kk' => 'ҚАЗ'] as $code => $label): ?>
        <a href="?lang=<?= e($code) ?>" aria-pressed="<?= \App\Core\Locale::current() === $code ? 'true' : 'false' ?>" data-testid="lang-<?= e($code) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
      <?php else: $langNext = $langNext ?? ''; include __DIR__ . '/../partials/lang-switch.php'; endif; ?>
    </div>
    <div class="auth__head">
      <svg width="56" height="56" viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="18" fill="#FDF0E1"/><circle cx="18" cy="18" r="8" fill="#F2A516"/></svg>
    </div>
    <?= $content ?>
  </div>
</main>
</body>
</html>
