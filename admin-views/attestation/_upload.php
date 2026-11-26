<?php
/**
 * Блок «Файлы» (создание и добавление): строки + «Добавить ещё файл» + «Выбрать несколько файлов сразу».
 * @var string $mode create|edit @var string $accept @var int $maxBytes @var string $maxLabel @var array $checkItems @var ?array $prefill @var ?array $row
 */
$cfg = [
    'mode' => $mode, 'max' => $maxBytes, 'exts' => ['pdf', 'doc', 'docx', 'xls', 'xlsx'],
    'createUrl' => admin_url('/attestation'),
    'filesUrl' => $mode === 'edit' ? admin_url('/attestation/' . (int)$row['id'] . '/files') : '',
    'doneUrl' => $mode === 'edit' ? admin_url('/attestation/' . (int)$row['id'] . '/uploaded') : '',
    'csrf' => csrf_token(),
    'i18n' => [
        'pick' => t('attestation.row.pick'), 'uploading' => t('attestation.js.uploading'), 'done' => t('attestation.js.done'),
        'error' => t('attestation.js.error'), 'retry' => t('attestation.js.retry'), 'bad_type' => t('attestation.js.bad_type'),
        'too_large' => t('attestation.js.too_large', ['max' => $maxLabel]), 'no_file' => t('attestation.js.no_file'),
        'leave' => t('attestation.js.leave'), 'network' => t('attestation.js.network'), 'waiting' => t('attestation.js.waiting'),
        'nothing' => t('attestation.js.nothing'), 'creating' => t('attestation.js.creating'), 'save' => t('attestation.js.save_again'),
    ],
];
$tplRow = static function (?array $rowPrefill = null) use ($accept, $checkItems): string {
    ob_start();
    include __DIR__ . '/_row.php';
    return (string)ob_get_clean();
};
?>
<section class="att-files" id="att-add" data-att-files data-cfg="<?= e(json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>" data-testid="att-files">
  <h2><?= e(t('attestation.files.title')) ?></h2>
  <p class="muted"><?= e(t('attestation.files.hint', ['max' => $maxLabel])) ?></p>
  <div data-role="rows" class="att-rows"><?= $tplRow($prefill) ?></div>
  <noscript><?= $tplRow() . $tplRow() . $tplRow() ?></noscript>
  <template data-role="tpl"><?= $tplRow() ?></template>
  <div class="att-add-row">
    <button type="button" class="btn btn--md btn--ghost" data-role="add" hidden data-testid="att-add-row"><?= e(t('attestation.row.add')) ?></button>
    <label class="att-multi"><span><?= e(t('attestation.row.multi')) ?></span>
      <input class="input" type="file" name="multi[]" multiple accept="<?= e($accept) ?>" data-role="multi" data-testid="att-multi"></label>
  </div>
  <p class="field__error" data-role="summary" hidden data-testid="att-summary"></p>
</section>
