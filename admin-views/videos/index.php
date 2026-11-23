<?php
/**
 * @var array $videos @var int $edit @var ?string $ytError @var string $ytUrl @var string $ytTitle @var bool $needTitle
 * @var array $editErrors @var array $editOld @var string $maxLabel @var bool $ffmpeg @var array $mfBase @var string $base
 */
$icon = static fn(string $d): string => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$pEdit = '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>';
$pDel = '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>';
$pUp = '<path d="m6 15 6-6 6 6"/>';
$pDown = '<path d="m6 9 6 6 6-6"/>';
$pVideo = '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3"/>';
$errBox = static fn(?string $m, string $id, string $tid): string => $m ? '<span class="field__error" id="' . e($id) . '" data-testid="' . e($tid) . '">' . e($m) . '</span>' : '';
$total = count($videos);
?>
<div class="page-head">
  <div>
    <h1><?= e(t('videos.title')) ?></h1>
    <p><?= e(t('videos.lead')) ?></p>
  </div>
</div>

<div class="video-add">
  <section class="card stack" data-testid="videos-youtube-card">
    <div class="video-add__head">
      <h2><?= e(t('videos.yt.title')) ?></h2>
      <span class="lang-badge lang-badge--ok"><?= e(t('videos.yt.recommended')) ?></span>
    </div>
    <p class="muted"><?= e(t('videos.yt.text')) ?></p>
    <form method="post" action="<?= e($base . '/youtube') ?>" class="stack" data-once novalidate data-testid="videos-youtube-form">
      <?= csrf_field() ?>
      <div class="field">
        <label for="videos-url"><?= e(t('videos.yt.label')) ?></label>
        <div class="video-add__row">
          <input class="input" type="url" id="videos-url" name="url" value="<?= e($ytUrl) ?>" placeholder="https://youtu.be/…" data-testid="videos-url"<?= $ytError !== null && !$needTitle ? ' aria-invalid="true" aria-describedby="videos-url-error"' : '' ?>>
          <button type="submit" class="btn btn--md" data-testid="videos-add"><?= e(t('videos.yt.add')) ?></button>
        </div>
        <?php if (!$needTitle): ?><?= $errBox($ytError, 'videos-url-error', 'videos-url-error') ?><?php endif; ?>
        <span class="field__hint"><?= e(t('videos.yt.hint')) ?></span>
      </div>
      <?php if ($needTitle): ?>
      <div class="field">
        <label for="videos-yt-title"><?= e(t('videos.yt.title_label')) ?></label>
        <input class="input" type="text" id="videos-yt-title" name="title" maxlength="200" value="<?= e($ytTitle) ?>" data-testid="videos-yt-title" aria-invalid="true" aria-describedby="videos-title-error" autofocus>
        <?= $errBox($ytError, 'videos-title-error', 'videos-title-error') ?>
      </div>
      <?php endif; ?>
    </form>
  </section>

  <section class="card stack" data-testid="videos-upload-card" data-video-uploader
    data-init="<?= e($base . '/upload/init') ?>" data-status="<?= e($base . '/upload/status') ?>" data-chunk="<?= e($base . '/upload/chunk') ?>" data-finish="<?= e($base . '/upload/finish') ?>"
    data-csrf="<?= e(csrf_token()) ?>" data-max-bytes="<?= (int)\App\Core\VideoUploader::maxBytes() ?>"
    data-i18n="<?= e(json_encode([
        'progress' => t('videos.file.progress'), 'finishing' => t('videos.file.finishing'), 'resume' => t('videos.file.resume'),
        'too_large' => t('media.error.video_too_large', ['max' => $maxLabel]), 'type' => t('media.error.video_type'), 'network' => t('media.error.network'),
    ], JSON_UNESCAPED_UNICODE)) ?>">
    <h2><?= e(t('videos.file.title')) ?></h2>
    <p class="muted"><?= e(t('videos.file.text', ['max' => $maxLabel])) ?><?= $ffmpeg ? ' ' . e(t('videos.file.ffmpeg')) : '' ?></p>
    <button type="button" class="doc-drop" data-role="open" data-testid="videos-file-open">
      <?= $icon('<path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>') ?>
      <b><?= e(t('videos.file.pick')) ?></b>
    </button>
    <input type="file" hidden accept="video/mp4,.mp4" data-role="input" data-testid="videos-file-input" tabindex="-1">
    <div class="video-progress" data-role="progress" hidden data-testid="videos-upload-progress">
      <div class="video-progress__name" data-role="name"></div>
      <div class="video-progress__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-role="bar"><div data-role="fill"></div></div>
      <div class="video-progress__text" data-role="text" data-testid="videos-upload-text"></div>
      <div class="video-progress__actions">
        <button type="button" class="btn btn--sm" data-role="retry" hidden data-testid="videos-upload-retry"><?= e(t('videos.file.retry')) ?></button>
        <button type="button" class="btn btn--sm btn--ghost" data-role="cancel" data-testid="videos-upload-cancel"><?= e(t('videos.file.cancel')) ?></button>
      </div>
    </div>
    <p class="field__error" role="alert" data-role="error" data-testid="videos-upload-error" hidden></p>
  </section>
</div>

<section class="stack" data-testid="videos-list-section">
  <h2 data-testid="videos-count"><?= e(t('videos.all')) ?> · <?= $total ?></h2>
  <?php if (!$videos): ?>
  <p class="empty" data-testid="videos-empty"><?= e(t('videos.empty')) ?></p>
  <?php else: ?>
  <div class="table-card" data-testid="videos-list">
    <?php foreach ($videos as $i => $v): $id = (int)$v['id']; ?>
    <?php
      $dur = \App\Models\Video::formatDuration($v['duration'] !== null ? (int)$v['duration'] : null);
      $added = date('d.m.Y', (int)strtotime((string)$v['created_at'] . ' UTC'));
      $meta = ($dur !== '' ? $dur . ' · ' : '') . t('videos.meta.added', ['date' => $added]);
      if ($v['status'] === 'processing') { $pill = ['pill-state--scheduled', t('videos.status.processing')]; }
      elseif ($v['status'] === 'failed') { $pill = ['pill-state--danger', t('videos.status.failed')]; }
      elseif ((int)$v['is_published'] === 1) { $pill = ['pill-state--published', t('videos.status.published')]; }
      else { $pill = ['pill-state--draft', t('videos.status.hidden')]; }
    ?>
    <?php if ($edit === $id): ?>
    <?php
      $o = $editOld ?: $v; $vals = ['title' => (string)($o['title'] ?? ''), 'title_kk' => (string)($o['title_kk'] ?? ''), 'is_published' => !empty($o['is_published'])];
      $posterId = $editOld ? (\App\Models\Media::parseIds($editOld['poster_media_id'] ?? [])[0] ?? null) : ($v['poster_media_id'] !== null ? (int)$v['poster_media_id'] : null);
    ?>
    <form class="video-row video-row--edit" method="post" action="<?= e($base . '/' . $id) ?>" data-once novalidate data-testid="videos-edit-form" id="video-<?= $id ?>">
      <?= csrf_field() ?>
      <div class="field">
        <label for="videos-title"><?= e(t('videos.edit.title_ru')) ?> <span aria-hidden="true">*</span></label>
        <input class="input" type="text" id="videos-title" name="title" maxlength="200" value="<?= e($vals['title']) ?>" data-testid="videos-title"<?= !empty($editErrors['title']) ? ' aria-invalid="true" aria-describedby="videos-edit-title-error"' : '' ?>>
        <?= $errBox($editErrors['title'] ?? null, 'videos-edit-title-error', 'videos-field-error') ?>
      </div>
      <div class="field">
        <label for="videos-title-kk"><?= e(t('videos.edit.title_kk')) ?> <span class="muted"><?= e(t('videos.edit.later')) ?></span></label>
        <input class="input" type="text" id="videos-title-kk" name="title_kk" maxlength="200" value="<?= e($vals['title_kk']) ?>" placeholder="Атауы" data-testid="videos-title-kk"<?= !empty($editErrors['title_kk']) ? ' aria-invalid="true" aria-describedby="videos-edit-titlekk-error"' : '' ?>>
        <?= $errBox($editErrors['title_kk'] ?? null, 'videos-edit-titlekk-error', 'videos-field-error') ?>
      </div>
      <?php
        $mf = $mfBase + ['name' => 'poster_media_id', 'label' => t('videos.edit.poster'), 'multiple' => false,
            'values' => \App\Models\Media::pickerValues($posterId !== null ? [$posterId] : []),
            'openLabel' => t('videos.edit.poster_choose'), 'hint' => t('videos.edit.poster_hint'), 'error' => $editErrors['poster'] ?? null];
        include __DIR__ . '/../partials/media-field.php';
      ?>
      <?= $errBox($editErrors['poster'] ?? null, 'videos-edit-poster-error', 'videos-field-error') ?>
      <label class="check"><input type="checkbox" name="is_published" value="1"<?= $vals['is_published'] ? ' checked' : '' ?> data-testid="videos-published"> <?= e(t('videos.edit.show')) ?></label>
      <div class="video-row__edit-actions">
        <button type="submit" class="btn btn--md" data-testid="videos-save"><?= e(t('videos.edit.save')) ?></button>
        <a class="btn btn--md btn--ghost" href="<?= e($base) ?>" data-testid="videos-edit-cancel"><?= e(t('videos.edit.cancel')) ?></a>
      </div>
    </form>
    <?php else: ?>
    <div class="video-row" data-testid="videos-row" data-video-id="<?= $id ?>">
      <span class="video-thumb" data-testid="videos-row-poster">
        <?php if ($v['poster_url'] !== ''): ?><img src="<?= e($v['poster_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><?= $icon($pVideo) ?><?php endif; ?>
      </span>
      <div class="video-row__main">
        <b class="video-row__title" data-testid="videos-row-title"><?= e($v['title']) ?></b>
        <div class="video-row__meta">
          <span class="src-tag src-tag--<?= e($v['source']) ?>" data-testid="videos-row-source"><?= e(t('videos.source.' . $v['source'])) ?></span>
          <span data-testid="videos-row-meta"><?= e($meta) ?></span>
        </div>
        <?php if ($v['status'] === 'processing'): ?><div class="video-progress__bar video-progress__bar--busy" data-testid="videos-row-processing"><div></div></div><?php endif; ?>
      </div>
      <?php if ($v['status'] === 'ready'): ?>
      <form method="post" action="<?= e($base . '/' . $id . '/publish') ?>" data-once>
        <?= csrf_field() ?><input type="hidden" name="is_published" value="<?= (int)$v['is_published'] === 1 ? '0' : '1' ?>">
        <button type="submit" class="pill-state pill-state--btn <?= e($pill[0]) ?>" title="<?= e(t((int)$v['is_published'] === 1 ? 'videos.action.toggle_hide' : 'videos.action.toggle_show')) ?>" data-testid="videos-row-toggle"><?= e($pill[1]) ?></button>
      </form>
      <?php else: ?>
      <span class="pill-state <?= e($pill[0]) ?>" data-testid="videos-row-status"><?= e($pill[1]) ?></span>
      <?php endif; ?>
      <div class="doc-row__actions">
        <form method="post" action="<?= e($base . '/' . $id . '/move') ?>" data-once>
          <?= csrf_field() ?><input type="hidden" name="dir" value="up">
          <button type="submit" class="icon-btn" aria-label="<?= e(t('videos.action.up')) ?>" title="<?= e(t('videos.action.up')) ?>"<?= $i === 0 ? ' disabled' : '' ?> data-testid="videos-row-up"><?= $icon($pUp) ?></button>
        </form>
        <form method="post" action="<?= e($base . '/' . $id . '/move') ?>" data-once>
          <?= csrf_field() ?><input type="hidden" name="dir" value="down">
          <button type="submit" class="icon-btn" aria-label="<?= e(t('videos.action.down')) ?>" title="<?= e(t('videos.action.down')) ?>"<?= $i === $total - 1 ? ' disabled' : '' ?> data-testid="videos-row-down"><?= $icon($pDown) ?></button>
        </form>
        <a class="icon-btn" href="<?= e($base . '?edit=' . $id . '#video-' . $id) ?>" aria-label="<?= e(t('videos.action.edit')) ?>" title="<?= e(t('videos.action.edit')) ?>" data-testid="videos-row-edit"><?= $icon($pEdit) ?></a>
        <form method="post" action="<?= e($base . '/' . $id . '/delete') ?>" data-confirm="<?= e(t('videos.delete.confirm', ['title' => $v['title']])) ?>" data-once>
          <?= csrf_field() ?>
          <button type="submit" class="icon-btn icon-btn--danger" aria-label="<?= e(t('videos.action.delete')) ?>" title="<?= e(t('videos.action.delete')) ?>" data-testid="videos-row-delete"><?= $icon($pDel) ?></button>
        </form>
      </div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<script src="<?= e(asset('admin/videos.js')) ?>" defer></script>
