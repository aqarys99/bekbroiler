<?php
/**
 * Кнопки строки списка: вверх/вниз, правка, удаление, (переключатель показа).
 * @var string $base @var int $id @var int $i @var int $total @var string $k префикс testid @var string $confirm
 */
$ic = static fn(string $d): string => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
?>
<div class="doc-row__actions">
  <form method="post" action="<?= e($base . '/' . $id . '/move') ?>" data-once>
    <?= csrf_field() ?><input type="hidden" name="dir" value="up">
    <button type="submit" class="icon-btn" aria-label="<?= e(t('sections.up')) ?>" title="<?= e(t('sections.up')) ?>"<?= $i === 0 ? ' disabled' : '' ?> data-testid="<?= e($k) ?>-row-up"><?= $ic('<path d="m6 15 6-6 6 6"/>') ?></button>
  </form>
  <form method="post" action="<?= e($base . '/' . $id . '/move') ?>" data-once>
    <?= csrf_field() ?><input type="hidden" name="dir" value="down">
    <button type="submit" class="icon-btn" aria-label="<?= e(t('sections.down')) ?>" title="<?= e(t('sections.down')) ?>"<?= $i === $total - 1 ? ' disabled' : '' ?> data-testid="<?= e($k) ?>-row-down"><?= $ic('<path d="m6 9 6 6 6-6"/>') ?></button>
  </form>
  <a class="icon-btn" href="<?= e($base . '/' . $id) ?>" aria-label="<?= e(t('sections.edit')) ?>" title="<?= e(t('sections.edit')) ?>" data-testid="<?= e($k) ?>-row-edit"><?= $ic('<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>') ?></a>
  <form method="post" action="<?= e($base . '/' . $id . '/delete') ?>" data-confirm="<?= e($confirm) ?>" data-once>
    <?= csrf_field() ?>
    <button type="submit" class="icon-btn icon-btn--danger" aria-label="<?= e(t('sections.delete')) ?>" title="<?= e(t('sections.delete')) ?>" data-testid="<?= e($k) ?>-row-delete"><?= $ic('<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>') ?></button>
  </form>
</div>
