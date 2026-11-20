<?php
/** @var string $content @var string $title */
$wide = isset($requirements);
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('admin/admin.css')) ?>">
</head>
<body>
<main class="auth">
  <div class="auth__card<?= $wide ? ' auth__card--wide' : '' ?>">
    <div class="auth__head">
      <svg width="56" height="56" viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="18" fill="#FDF0E1"/><circle cx="18" cy="18" r="8" fill="#F2A516"/></svg>
    </div>
    <?= $content ?>
  </div>
</main>
</body>
</html>
