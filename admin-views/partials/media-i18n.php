<?php
/**
 * Тексты для uploader.js и media-picker.js (без инлайнового JS: уходят в data-i18n).
 * Ожидает $maxLabel (строка, напр. «20 МБ»). Результат: $mediaI18nJson.
 *
 * @var string $maxLabel
 */
$mediaI18nJson = json_encode([
    'wait' => t('media.status.wait'),
    'compress' => t('media.status.compress'),
    'upload' => t('media.status.upload'),
    'done' => t('media.status.done'),
    'error' => t('media.status.error'),
    'retry' => t('media.queue.retry'),
    'clear' => t('media.queue.clear'),
    'summary' => t('media.queue.summary'),
    'saved' => t('media.queue.saved'),
    'leave' => t('media.queue.leave_warning'),
    'dropTitle' => t('media.dropzone.title'),
    'dropHint' => t('media.dropzone.hint', ['max' => $maxLabel]),
    'err_not_image' => t('media.error.not_image'),
    'err_too_large' => t('media.error.too_large', ['max' => $maxLabel]),
    'err_heic_unsupported' => t('media.error.heic_unsupported'),
    'err_broken' => t('media.error.broken'),
    'err_no_file' => t('media.error.no_file'),
    'err_session' => t('media.error.session'),
    'err_csrf' => t('media.error.csrf'),
    'err_network' => t('media.error.network'),
    'loginUrl' => admin_url('/login'),
    'pTitle' => t('picker.title'),
    'pTabLibrary' => t('picker.tab.library'),
    'pTabUpload' => t('picker.tab.upload'),
    'pSearch' => t('picker.search'),
    'pMore' => t('picker.more'),
    'pLoadFailed' => t('picker.load_failed'),
    'pEmpty' => t('picker.empty'),
    'pGoUpload' => t('picker.go_upload'),
    'pConfirm' => t('picker.confirm'),
    'pConfirmN' => t('picker.confirm_n'),
    'pCancel' => t('picker.cancel'),
    'pClose' => t('picker.close'),
    'pClear' => t('picker.clear'),
    'pNone' => t('picker.none'),
    'pDeleted' => t('picker.deleted'),
    'pSelected' => t('picker.selected'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
