<?php
/** Таблетка «На сайте / Скрыто». @var string $base @var int $id @var int $on @var string $k */
?>
<form method="post" action="<?= e($base . '/' . $id . '/publish') ?>" data-once>
  <?= csrf_field() ?><input type="hidden" name="is_published" value="<?= $on === 1 ? '0' : '1' ?>">
  <button type="submit" class="pill-state pill-state--btn <?= $on === 1 ? 'pill-state--published' : 'pill-state--draft' ?>" title="<?= e(t($on === 1 ? 'sections.toggle_hide' : 'sections.toggle_show')) ?>" data-testid="<?= e($k) ?>-row-toggle"><?= e(t($on === 1 ? 'sections.on_site' : 'sections.hidden')) ?></button>
</form>
