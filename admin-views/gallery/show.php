<?php
/**
 * @var array $album @var array $photos @var ?int $cover @var array $old @var array $errors @var bool $editOpen
 * @var string $maxLabel @var array $upload @var array $mf
 * (media-field.php перезаписывает $v, $m, $state — эти имена здесь не использовать)
 */
include __DIR__ . '/../partials/media-i18n.php';
$aid = (int)$album['id'];
$base = admin_url('/gallery/' . $aid);
$n = count($photos);
?>
<div class="page-head">
  <div>
    <h1><?= e(t('gallery.album', ['title' => $album['title']])) ?></h1>
    <p><?= e($album['event_date'] ? date('d.m.Y', (int)strtotime((string)$album['event_date'])) : '') ?></p>
  </div>
  <div class="gallery-tools">
    <form method="post" action="<?= e($base . '/publish') ?>" class="gallery-pub" data-testid="gallery-publish-form">
      <?= csrf_field() ?>
      <label class="check"><input type="checkbox" name="is_published" value="1"<?= (int)$album['is_published'] ? ' checked' : '' ?> data-testid="gallery-published"> <?= e(t('gallery.field.published')) ?></label>
      <button type="submit" class="btn btn--md btn--ghost" data-testid="gallery-publish-apply"><?= e(t('gallery.apply')) ?></button>
    </form>
    <form method="post" action="<?= e($base . '/delete') ?>" data-confirm="<?= e(t('gallery.delete.confirm')) ?>" data-once>
      <?= csrf_field() ?>
      <button type="submit" class="btn btn--md btn--danger" data-testid="gallery-delete"><?= e(t('gallery.delete')) ?></button>
    </form>
  </div>
</div>

<details class="card gallery-edit" data-testid="gallery-edit"<?= $editOpen ? ' open' : '' ?>>
  <summary data-testid="gallery-edit-toggle"><?= e(t('gallery.edit')) ?></summary>
  <form class="form" method="post" action="<?= e($base) ?>" novalidate data-once data-testid="gallery-form">
    <?= csrf_field() ?>
    <?php include __DIR__ . '/_fields.php'; ?>
    <input type="hidden" name="is_published" value="<?= (int)$album['is_published'] ?>">
    <div class="actions"><button type="submit" class="btn btn--md" data-testid="gallery-form-submit"><?= e(t('gallery.save')) ?></button></div>
  </form>
</details>

<section class="card stack" data-gallery data-album-id="<?= $aid ?>" data-photos-url="<?= e($base . '/photos') ?>" data-order-url="<?= e($base . '/order') ?>"
  data-csrf="<?= e($upload['csrf']) ?>" data-reorder-failed="<?= e(t('gallery.reorder_failed')) ?>" data-testid="gallery-uploader"
  data-url="<?= e($upload['url']) ?>" data-max-bytes="<?= (int)$upload['maxBytes'] ?>" data-max-side="<?= (int)$upload['maxSide'] ?>"
  data-quality="<?= e((string)$upload['quality']) ?>" data-i18n="<?= e($mediaI18nJson) ?>">
  <button type="button" class="dropzone" data-role="dropzone" data-testid="gallery-dropzone">
    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#1F5BD8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
    <b class="dropzone__title"><?= e(t('gallery.dropzone.title')) ?></b>
    <span class="dropzone__hint"><?= e(t('gallery.dropzone.hint')) ?></span>
  </button>
  <input type="file" hidden multiple accept="image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.heic,.heif" data-role="input" data-testid="gallery-file-input" tabindex="-1">
  <div class="gallery-library">
    <button type="button" class="btn btn--md btn--ghost" data-role="library" data-testid="gallery-add-library"><?= e(t('gallery.add_library')) ?></button>
  </div>

  <div class="note-ok">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>
    <span><?= e(t('gallery.privacy_note')) ?></span>
  </div>

  <div class="queue" data-role="queue" data-testid="gallery-queue" hidden>
    <div class="queue__head">
      <span><b data-role="summary" data-testid="gallery-queue-summary"></b> <span class="queue__saved" data-role="saved" hidden></span></span>
      <button type="button" class="btn--text" data-role="clear" data-testid="gallery-queue-clear"><?= e(t('media.queue.clear')) ?></button>
    </div>
    <div data-role="list" aria-live="polite"></div>
  </div>

  <div class="gallery-head" id="gallery-photos">
    <h3 data-testid="gallery-photos-title" data-role="count-title" data-tpl="<?= e(t('gallery.photos.title', ['n' => ':n'])) ?>"><?= e(t('gallery.photos.title', ['n' => $n])) ?></h3>
    <span class="muted"><?= e(t('gallery.photos.hint')) ?></span>
  </div>
  <p class="empty" data-role="empty" data-testid="gallery-photos-empty"<?= $n ? ' hidden' : '' ?>><?= e(t('gallery.photos.empty')) ?></p>
  <ul class="gphotos" data-role="photos" data-testid="gallery-photos"<?= $n ? '' : ' hidden' ?>>
    <?php foreach ($photos as $i => $p) {
        $albumId = $aid; $isCover = $cover === (int)$p['id']; $first = $i === 0; $last = $i === $n - 1;
        include __DIR__ . '/_photo.php';
    } ?>
  </ul>
</section>

<div hidden><?php include __DIR__ . '/../partials/media-field.php'; ?></div>
<script src="<?= e(asset('admin/gallery.js')) ?>" defer></script>
