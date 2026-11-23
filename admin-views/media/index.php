<?php
/**
 * @var array $items @var array $counts @var string $q @var string $type @var array $types @var int $page @var int $pages
 * @var int $total @var bool $hasFilter @var array $disk @var string $diskLine @var int $maxBytes @var string $maxLabel @var array $upload
 */
include __DIR__ . '/../partials/media-i18n.php';
$base = admin_url('/media');
$qs = static function (array $extra) use ($q, $type): string {
    $p = $extra + ['q' => $q, 'type' => $type];
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== 'all' && $v !== 1);
    return $p ? '?' . http_build_query($p) : '';
};
$backParam = $qs(['page' => $page]);
$backQuery = '?back=' . rawurlencode($base . $backParam);
?>
<div class="page-head">
  <div>
    <h1><?= e(t('media.title')) ?></h1>
    <p><?= e(t('media.subtitle')) ?></p>
  </div>
</div>

<section class="card stack" data-uploader
  data-url="<?= e($upload['url']) ?>" data-max-bytes="<?= (int)$upload['maxBytes'] ?>" data-max-side="<?= (int)$upload['maxSide'] ?>"
  data-quality="<?= e((string)$upload['quality']) ?>" data-csrf="<?= e($upload['csrf']) ?>" data-i18n="<?= e($mediaI18nJson) ?>">
  <button type="button" class="dropzone" data-role="dropzone" data-testid="media-dropzone">
    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#1F5BD8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
    <b class="dropzone__title"><?= e(t('media.dropzone.title')) ?></b>
    <span class="dropzone__hint"><?= e(t('media.dropzone.hint', ['max' => $maxLabel])) ?></span>
  </button>
  <input type="file" hidden multiple accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.heic,.heif" data-role="input" data-testid="media-file-input" tabindex="-1">

  <div class="note-ok">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>
    <span><?= e(t('media.privacy_note')) ?></span>
  </div>

  <div class="queue" data-role="queue" data-testid="media-queue" hidden>
    <div class="queue__head">
      <span><b data-role="summary" data-testid="media-queue-summary"></b> <span class="queue__saved" data-role="saved" hidden></span></span>
      <button type="button" class="btn--text" data-role="clear" data-testid="media-queue-clear"><?= e(t('media.queue.clear')) ?></button>
    </div>
    <div data-role="list" aria-live="polite"></div>
  </div>
</section>

<section class="card stack">
  <form class="toolbar" method="get" action="<?= e($base) ?>" role="search">
    <div class="field">
      <label for="media-q"><?= e(t('media.search.placeholder')) ?></label>
      <input class="input" type="search" id="media-q" name="q" maxlength="100" value="<?= e($q) ?>" placeholder="<?= e(t('media.search.placeholder')) ?>" data-testid="media-search">
    </div>
    <div class="field field--type">
      <label for="media-type"><?= e(t('media.filter.type')) ?></label>
      <select class="input select" id="media-type" name="type" data-autosubmit data-testid="media-filter-type">
        <?php foreach ($types as $tp): ?>
        <option value="<?= e($tp) ?>"<?= $type === $tp ? ' selected' : '' ?>><?= e(t('media.type.' . $tp)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn--md" data-testid="media-search-submit"><?= e(t('media.search.submit')) ?></button>
    <?php if ($hasFilter): ?>
    <a class="btn btn--md btn--ghost" href="<?= e($base) ?>" data-testid="media-search-reset"><?= e(t('media.search.reset')) ?></a>
    <?php endif; ?>
  </form>

  <p class="disk-line" data-testid="media-disk-usage" data-role="disk"><?= e($diskLine) ?></p>

  <p class="empty" data-testid="media-empty" data-role="empty"<?= $items ? ' hidden' : '' ?>><?= e(t($hasFilter ? 'media.not_found' : 'media.empty')) ?></p>

  <ul class="media-grid" data-testid="media-grid" data-role="grid"<?= $items ? '' : ' hidden' ?>>
    <?php foreach ($items as $row): $id = (int)$row['id']; ?>
    <li>
      <a class="media-item" href="<?= e(admin_url('/media/' . $id) . $backQuery) ?>" data-testid="media-item" title="<?= e($row['original_name']) ?>">
        <span class="media-item__img"><?php if (in_array($row['type'], ['file', 'video'], true)): ?><span class="doc-badge doc-badge--other"><?= e(strtoupper(pathinfo((string)$row['path'], PATHINFO_EXTENSION))) ?></span><?php else: ?><?= media_picture($row, 'thumb', ['sizes' => '200px']) ?><?php endif; ?></span>
        <span class="media-item__name"><?= e($row['original_name']) ?></span>
        <span class="media-item__meta"><?= $row['width'] !== null ? e((int)$row['width'] . ' × ' . (int)$row['height'] . ' px') : e(format_bytes((int)$row['size'])) ?></span>
        <?php if (($counts[$id] ?? 0) > 0): ?><span class="media-item__used" data-testid="media-item-used"><?= e(t('media.used_badge')) ?></span><?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($pages > 1): ?>
  <nav class="pager" aria-label="<?= e(t('media.page.info', ['page' => $page, 'pages' => $pages])) ?>">
    <?php if ($page > 1): ?><a href="<?= e($base . $qs(['page' => $page - 1])) ?>" rel="prev" data-testid="media-page-prev"><?= e(t('media.page.prev')) ?></a><?php endif; ?>
    <span><?= e(t('media.page.info', ['page' => $page, 'pages' => $pages])) ?></span>
    <?php if ($page < $pages): ?><a href="<?= e($base . $qs(['page' => $page + 1])) ?>" rel="next" data-testid="media-page-next"><?= e(t('media.page.next')) ?></a><?php endif; ?>
  </nav>
  <?php endif; ?>
</section>
<script src="<?= e(asset('admin/uploader.js')) ?>" defer></script>
