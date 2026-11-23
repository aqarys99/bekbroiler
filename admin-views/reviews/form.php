<?php
/** @var ?array $row @var array $old @var array $errors @var string $base */
$k = 'reviews';
?>
<div class="page-head"><h1><?= e($row === null ? t('reviews.new') : $row['author']) ?></h1></div>
<div class="card card--form">
  <form class="form" method="post" action="<?= e($row === null ? $base : $base . '/' . (int)$row['id']) ?>" novalidate data-once data-testid="reviews-form">
    <?= csrf_field() ?>
    <?php foreach ([
        ['name' => 'author', 'label' => t('reviews.field.author'), 'testid' => 'reviews-form-author', 'required' => true, 'max' => 150, 'hint' => t('reviews.field.author_hint')],
        ['name' => 'author_kk', 'label' => t('reviews.field.author_kk'), 'testid' => 'reviews-form-author-kk', 'max' => 150],
        ['name' => 'text', 'label' => t('reviews.field.text'), 'testid' => 'reviews-form-text', 'required' => true, 'area' => true, 'max' => 1000],
        ['name' => 'text_kk', 'label' => t('reviews.field.text_kk'), 'testid' => 'reviews-form-text-kk', 'area' => true, 'max' => 1000],
    ] as $fd) { include __DIR__ . '/../partials/section-field.php'; } ?>
    <?php include __DIR__ . '/../partials/section-form-end.php'; ?>
  </form>
</div>
