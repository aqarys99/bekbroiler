<?php /** @var array $items @var bool $zip @var int $size @var int $keep */
$fmt = static function (int $ts): string {
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Asia/Almaty'))->format('d.m.Y H:i');
};
?>
<div class="page-head">
  <h1><?= e(t('backups.title')) ?></h1>
</div>
<p class="muted"><?= e(t('backups.lead')) ?></p>

<div class="alert alert--info" role="note" data-testid="backups-warning"><?= e(t('backups.warn')) ?></div>

<section class="table-card" style="padding:20px;margin:16px 0" aria-labelledby="bk-archive">
  <h2 id="bk-archive" style="margin-top:0"><?= e(t('backups.archive.title')) ?></h2>
  <p><?= e(t('backups.archive.text', ['size' => format_bytes($size)])) ?></p>
  <?php if (!$zip): ?><div class="alert alert--danger" role="alert" data-testid="backups-nozip"><?= e(t('backups.archive.nozip')) ?></div><?php endif; ?>
  <form method="post" action="<?= e(admin_url('/backups/archive')) ?>" data-once>
    <?= csrf_field() ?>
    <button type="submit" class="btn btn--md" data-testid="backups-archive"><?= e(t('backups.archive.btn')) ?></button>
  </form>
</section>

<section class="table-card" style="padding:20px;margin:16px 0" aria-labelledby="bk-auto">
  <div class="page-head" style="margin-bottom:8px">
    <h2 id="bk-auto" style="margin:0"><?= e(t('backups.auto.title')) ?></h2>
    <form method="post" action="<?= e(admin_url('/backups/create')) ?>" data-once>
      <?= csrf_field() ?>
      <button type="submit" class="btn btn--md btn--ghost" data-testid="backups-create"><?= e(t('backups.create')) ?></button>
    </form>
  </div>
  <p class="muted"><?= e(t('backups.auto.text', ['keep' => $keep])) ?></p>
  <?php if (!$items): ?>
  <p data-testid="backups-empty"><?= e(t('backups.empty')) ?></p>
  <?php else: ?>
  <table class="table" data-testid="backups-list">
    <thead><tr>
      <th scope="col"><?= e(t('backups.col.date')) ?></th>
      <th scope="col"><?= e(t('backups.col.size')) ?></th>
      <th scope="col"><?= e(t('backups.col.actions')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $b): ?>
      <tr data-testid="backups-row">
        <td data-label="<?= e(t('backups.col.date')) ?>"><?= e($fmt((int)$b['time'])) ?></td>
        <td data-label="<?= e(t('backups.col.size')) ?>"><?= e(format_bytes((int)$b['size'])) ?></td>
        <td class="cell-actions"><div class="row-actions">
          <a class="btn btn--sm" href="<?= e(admin_url('/backups/download/' . rawurlencode($b['name']))) ?>" data-testid="backups-download"><?= e(t('backups.download')) ?></a>
          <form method="post" action="<?= e(admin_url('/backups/delete/' . rawurlencode($b['name']))) ?>" data-confirm="<?= e(t('backups.delete.confirm')) ?>" data-once>
            <?= csrf_field() ?>
            <button type="submit" class="btn btn--sm is-danger" data-testid="backups-delete"><?= e(t('backups.delete')) ?></button>
          </form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="table-card" style="padding:20px;margin:16px 0" aria-labelledby="bk-restore">
  <h2 id="bk-restore" style="margin-top:0"><?= e(t('backups.restore.title')) ?></h2>
  <p style="margin:0"><?= e(t('backups.restore.text')) ?></p>
</section>
