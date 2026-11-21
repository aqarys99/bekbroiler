<?php /** @var ?array $flash */
if (!empty($flash)):
  $err = $flash['type'] === 'error';
  $cls = $err ? 'alert--danger' : ($flash['type'] === 'info' ? 'alert--info' : 'alert--ok');
?>
<div class="alert <?= $cls ?>" role="<?= $err ? 'alert' : 'status' ?>" data-testid="admin-flash"><?= e($flash['text']) ?></div>
<?php endif; ?>
