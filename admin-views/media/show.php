<?php
/**
 * @var array $media @var array $variants @var array $usages @var bool $usagesFailed @var bool $inUse @var bool $missingFiles
 * @var ?string $author @var string $backUrl @var string $backParam @var array $errors @var array $old
 */
$id = (int)$media['id'];
$altErr = $errors['alt'] ?? null;
$alt = $old['alt'] ?? (string)$media['alt'];
$uploaded = $media['created_at'] ? date('d.m.Y H:i', strtotime((string)$media['created_at'] . ' UTC') ?: 0) : '—';
$formAction = admin_url('/media/' . $id);
?>
<div class="page-head">
  <div>
    <a class="back-link" href="<?= e($backUrl) ?>" data-testid="media-back"><?= e(t('media.back')) ?></a>
    <h1><?= e($media['original_name']) ?></h1>
  </div>
</div>

<div class="media-show">
  <div class="stack">
    <section class="card stack">
      <div class="media-preview<?= $missingFiles ? ' media-preview--missing' : '' ?>" data-testid="media-preview">
        <?php if ($missingFiles): ?>
        <span><?= e(t('media.missing_file')) ?></span>
        <?php else: ?>
        <?php if (in_array($media['type'], ['file', 'video'], true)): ?><span class="doc-badge doc-badge--other"><?= e(strtoupper(pathinfo((string)$media['path'], PATHINFO_EXTENSION))) ?></span><?php else: ?>
        <?= media_picture($media, 'medium', ['sizes' => '(min-width: 900px) 600px, 100vw']) ?>
        <?php endif; ?>
        <?php endif; ?>
      </div>
      <?php if (in_array($media['type'], ['file', 'video'], true)): ?>
      <a class="btn btn--md btn--ghost" href="<?= e(url('/uploads/' . $media['path'])) ?>" target="_blank" rel="noopener" data-testid="media-open"><?= e(t('media.open')) ?></a>
      <?php elseif (media_url($media, 'large') !== ''): ?>
      <a class="btn btn--md btn--ghost" href="<?= e(media_url($media, 'large')) ?>" target="_blank" rel="noopener" data-testid="media-open"><?= e(t('media.open')) ?></a>
      <?php endif; ?>
    </section>

    <section class="card" data-testid="media-info">
      <dl class="info-list">
        <dt><?= e(t('media.info.name')) ?></dt><dd><?= e($media['original_name']) ?></dd>
        <dt><?= e(t('media.info.type')) ?></dt><dd><?= e(t('media.type.' . $media['type'])) ?></dd>
        <dt><?= e(t('media.info.dimensions')) ?></dt><dd><?= $media['width'] !== null ? e((int)$media['width'] . ' × ' . (int)$media['height'] . ' px') : '—' ?></dd>
        <dt><?= e(t('media.info.uploaded')) ?></dt><dd><?= e($uploaded) ?>, <?= e($author !== null && $author !== '' ? $author : '—') ?></dd>
        <dt><?= e(t('media.info.total')) ?></dt><dd><?= e(format_bytes((int)$media['size'])) ?></dd>
      </dl>
      <?php if ($variants): ?>
      <div class="vtable-wrap">
        <table class="vtable">
          <thead><tr><th scope="col"></th><th scope="col">WebP</th><th scope="col">JPEG</th></tr></thead>
          <tbody>
            <?php foreach (['thumb', 'medium', 'large'] as $size): if (!isset($variants[$size])) { continue; } $v = $variants[$size]; ?>
            <tr>
              <th scope="row"><?= e(t('media.info.variant.' . $size)) ?></th>
              <td><?= e((int)$v['w'] . ' × ' . (int)$v['h'] . ' px · ' . format_bytes((int)($v['webp_bytes'] ?? 0))) ?></td>
              <td><?= e((int)$v['w'] . ' × ' . (int)$v['h'] . ' px · ' . format_bytes((int)($v['jpg_bytes'] ?? 0))) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <div class="stack">
    <section class="card">
      <form class="form" method="post" action="<?= e($formAction) ?>" novalidate data-once>
        <?= csrf_field() ?>
        <?php if ($backParam !== ''): ?><input type="hidden" name="back" value="<?= e($backParam) ?>"><?php endif; ?>
        <div class="field">
          <label for="f-alt"><?= e(t('media.alt.label')) ?></label>
          <textarea class="input textarea" id="f-alt" name="alt" maxlength="300" rows="3" data-testid="media-alt"<?= $altErr ? ' aria-invalid="true"' : '' ?> aria-describedby="f-alt-hint<?= $altErr ? ' f-alt-error' : '' ?>"><?= e($alt) ?></textarea>
          <span class="field__hint" id="f-alt-hint"><?= e(t('media.alt.hint')) ?></span>
          <?php if ($altErr): ?><span class="field__error" id="f-alt-error"><?= e($altErr) ?></span><?php endif; ?>
        </div>
        <div class="actions"><button type="submit" class="btn btn--md" data-testid="media-save"><?= e(t('media.save')) ?></button></div>
      </form>
    </section>

    <section class="card stack" data-testid="media-usages">
      <h2 class="mt-0"><?= e(t('media.usages.title')) ?></h2>
      <?php if ($usagesFailed): ?>
      <p class="alert alert--danger" role="alert"><?= e(t('media.usages.failed')) ?></p>
      <?php elseif (!$usages): ?>
      <p class="muted"><?= e(t('media.usages.none')) ?></p>
      <?php else: ?>
      <ul class="usage-list">
        <?php foreach ($usages as $u): ?>
        <li><a href="<?= e($u['url']) ?>" data-testid="media-usage-item"><?= e($u['label']) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>

    <section class="card stack">
      <form method="post" action="<?= e($formAction . '/delete') ?>" data-confirm="<?= e(t('media.delete.confirm', ['name' => $media['original_name']])) ?>" data-once>
        <?= csrf_field() ?>
        <?php if ($backParam !== ''): ?><input type="hidden" name="back" value="<?= e($backParam) ?>"><?php endif; ?>
        <button type="submit" class="btn btn--md btn--danger" data-testid="media-delete"<?= $inUse ? ' disabled' : '' ?>><?= e(t('media.delete')) ?></button>
      </form>
      <?php if ($inUse): ?>
      <p class="field__error" data-testid="media-delete-blocked"><?= e(t('media.delete.blocked')) ?></p>
      <?php endif; ?>
    </section>
  </div>
</div>
