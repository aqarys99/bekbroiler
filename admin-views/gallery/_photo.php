<?php
/** Плитка фото в альбоме. @var int $albumId @var array $p @var bool $isCover @var bool $first @var bool $last */
$mid = (int)$p['id'];
$act = admin_url('/gallery/' . $albumId . '/photo/' . $mid) . '/';
?>
<li class="gphoto<?= $isCover ? ' is-cover' : '' ?>" data-testid="gallery-photo" data-media-id="<?= $mid ?>" draggable="true">
  <form method="post" action="<?= e($act . 'caption') ?>" class="gphoto__form">
    <?= csrf_field() ?>
    <div class="gphoto__img">
      <?= media_picture($p, 'thumb', ['sizes' => '200px', 'alt' => (string)($p['alt'] ?? '')]) ?>
      <?php if ($isCover): ?><span class="gphoto__badge" data-testid="gallery-cover-badge"><?= e(t('gallery.cover')) ?></span><?php endif; ?>
    </div>
    <div class="gphoto__caption">
      <input class="input" type="text" name="caption" maxlength="200" value="<?= e((string)$p['caption']) ?>" placeholder="<?= e(t('gallery.photo.caption')) ?>" aria-label="<?= e(t('gallery.photo.caption')) ?>" data-testid="gallery-photo-caption">
      <button type="submit" class="btn--text" data-testid="gallery-photo-caption-save"><?= e(t('gallery.photo.caption_save')) ?></button>
    </div>
    <div class="gphoto__actions">
      <button type="submit" formaction="<?= e($act . 'move') ?>" name="dir" value="left" class="btn--icon" aria-label="<?= e(t('gallery.photo.left')) ?>" title="<?= e(t('gallery.photo.left')) ?>" data-testid="gallery-photo-left" data-role="left"<?= $first ? ' disabled' : '' ?>>←</button>
      <button type="submit" formaction="<?= e($act . 'move') ?>" name="dir" value="right" class="btn--icon" aria-label="<?= e(t('gallery.photo.right')) ?>" title="<?= e(t('gallery.photo.right')) ?>" data-testid="gallery-photo-right" data-role="right"<?= $last ? ' disabled' : '' ?>>→</button>
      <?php if (!$isCover): ?><button type="submit" formaction="<?= e($act . 'cover') ?>" class="btn--text" data-testid="gallery-photo-cover"><?= e(t('gallery.cover.make')) ?></button><?php endif; ?>
      <button type="submit" formaction="<?= e($act . 'remove') ?>" class="btn--text is-danger" data-confirm-click="<?= e(t('gallery.photo.remove.confirm')) ?>" data-testid="gallery-photo-remove"><?= e(t('gallery.photo.remove')) ?></button>
    </div>
  </form>
</li>
