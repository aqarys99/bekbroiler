<?php
/** @var string $content @var string $title @var array $menu @var array $user @var array $breadcrumbs @var ?array $flash @var string $siteName */
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('admin/admin.css')) ?>">
<script src="<?= e(asset('admin/boot.js')) ?>"></script>
<script src="<?= e(asset('admin/admin.js')) ?>" defer></script>
</head>
<body>
<div class="layout">
  <?php include __DIR__ . '/../partials/menu.php'; ?>
  <main class="main">
    <?php include __DIR__ . '/../partials/header.php'; ?>
    <div class="content">
      <?php include __DIR__ . '/../partials/flash.php'; ?>
      <?= $content ?>
    </div>
  </main>
</div>
</body>
</html>
