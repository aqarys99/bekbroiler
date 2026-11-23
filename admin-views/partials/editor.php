<?php
/**
 * Текстовый редактор (WYSIWYG на contenteditable, public/assets/admin/editor.js).
 * Ожидает $ed: name (имя скрытой textarea), labelId (id подписи), testid, html (уже санитизированный HTML), [placeholder].
 * Без JS виден обычный textarea. Кнопки видео нет (фича 07).
 *
 * @var array $ed
 */
$btn = static function (string $cmd, string $testid, string $label, string $inner, bool $pressed = true): string {
    return '<button type="button" class="editor__btn" data-cmd="' . e($cmd) . '" data-testid="' . e($testid) . '" aria-label="' . e($label) . '" title="' . e($label) . '"'
        . ($pressed ? ' aria-pressed="false"' : '') . '>' . $inner . '</button>';
};
$svg = static fn(string $d): string => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
?>
<div class="editor" data-editor>
  <div class="editor__tb" role="toolbar" aria-label="<?= e(t('news.tb.toolbar')) ?>" aria-controls="<?= e($ed['labelId']) ?>-area">
    <?= $btn('bold', 'news-tb-bold', t('news.tb.bold'), '<b aria-hidden="true">Ж</b>') ?>
    <?= $btn('italic', 'news-tb-italic', t('news.tb.italic'), '<i aria-hidden="true">К</i>') ?>
    <?= $btn('heading', 'news-tb-heading', t('news.tb.heading'), '<b aria-hidden="true">H</b>') ?>
    <?= $btn('list', 'news-tb-list', t('news.tb.list'), $svg('<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>')) ?>
    <?= $btn('link', 'news-tb-link', t('news.tb.link'), $svg('<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>'), false) ?>
    <?= $btn('photo', 'news-tb-photo', t('news.tb.photo'), $svg('<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-8 8"/>'), false) ?>
  </div>
  <div class="editor__area" id="<?= e($ed['labelId']) ?>-area" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="<?= e($ed['labelId']) ?>"
    data-placeholder="<?= e($ed['placeholder'] ?? '') ?>" data-testid="<?= e($ed['testid']) ?>" spellcheck="true"><?= $ed['html'] ?></div>
  <textarea class="editor__source" name="<?= e($ed['name']) ?>" rows="8" aria-labelledby="<?= e($ed['labelId']) ?>"><?= e($ed['html']) ?></textarea>
</div>
