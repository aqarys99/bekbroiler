<?php
/**
 * Предпросмотр новости: полный HTML-документ без layout админки, noindex.
 * body_html уже санитизирован (HtmlSanitizer + renderBody) и выводится без e().
 *
 * @var string $title @var string $banner @var bool $unsaved @var array $langs @var string $noTranslation @var string $date
 * @var string $state @var string $coverHtml @var array $photosHtml @var string $siteName @var string $backUrl
 */
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('admin/admin.css')) ?>">
<script src="<?= e(asset('admin/boot.js')) ?>"></script>
<script src="<?= e(asset('admin/news-preview.js')) ?>" defer></script>
</head>
<body class="pv">
<div class="pv-banner" role="status">
  <span><?= e($banner) ?></span>
  <a href="<?= e($backUrl) ?>" data-testid="news-preview-back"><?= e(t('news.preview.back')) ?></a>
</div>
<main class="pv-page" data-testid="news-preview-page">
  <div class="pv-tabs" role="tablist" aria-label="<?= e(t('news.form.lang_tabs')) ?>">
    <button type="button" class="pv-tab" role="tab" id="pv-tab-ru" aria-controls="pv-ru" aria-selected="true" data-tab="ru" data-testid="news-preview-tab-ru"><?= e(t('news.tab.ru')) ?></button>
    <button type="button" class="pv-tab" role="tab" id="pv-tab-kk" aria-controls="pv-kk" aria-selected="false" tabindex="-1" data-tab="kk" data-testid="news-preview-tab-kk"><?= e(t('news.tab.kk')) ?></button>
  </div>
  <?php foreach ($langs as $code => $l): ?>
  <article class="pv-article" id="pv-<?= e($code) ?>" role="tabpanel" aria-labelledby="pv-tab-<?= e($code) ?>" lang="<?= e($code) ?>" data-panel="<?= e($code) ?>"<?= $code === 'ru' ? '' : ' hidden' ?>>
    <?php if (!empty($l['missing'])): ?><p class="pv-missing"><?= e($noTranslation) ?></p><?php endif; ?>
    <h1><?= e($l['title']) ?></h1>
    <p class="pv-date"><?= e($date) ?></p>
    <?php if ($coverHtml !== ''): ?><div class="pv-cover"><?= $coverHtml ?></div><?php endif; ?>
    <?php if (trim((string)$l['excerpt']) !== ''): ?><p class="pv-lead"><?= e($l['excerpt']) ?></p><?php endif; ?>
    <div class="pv-body"><?= $l['body_html'] ?></div>
    <?php if ($photosHtml): ?>
    <div class="pv-photos">
      <?php foreach ($photosHtml as $p): ?><div class="pv-photo"><?= $p ?></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </article>
  <?php endforeach; ?>
</main>
</body>
</html>
