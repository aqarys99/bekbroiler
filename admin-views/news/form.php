<?php
/**
 * Редактор новости (создание и правка).
 * (внимание: media-field.php перезаписывает $v, $m, $state — свои переменные здесь не называть так)
 * @var ?array $news @var ?int $id @var array $values @var array $errors @var string $state new|draft|scheduled|published
 * @var bool $conflict @var string $conflictUrl @var string $activeTab @var string $formAction @var string $autosaveUrl @var string $slugUrl
 * @var string $previewUrl @var ?string $deleteUrl @var string $listUrl @var bool $autosave @var bool $showSaveDraft @var array $submitLabels
 * @var string $pubCurrent @var string $tzHint @var string $minDate @var string $slugBase @var int $maxPhotos @var array $mfCover @var array $mfPhotos
 */
$fv = $values;
$mode = (string)$fv['publish_mode'];
$errFor = static function (string $name) use ($errors): string {
    $m = $errors[$name] ?? null;
    return $m ? '<span class="field__error" id="err-' . e($name) . '" data-testid="news-field-error">' . e($m) . '</span>' : '';
};
$inv = static fn(string $name): string => !empty($errors[$name]) ? ' aria-invalid="true"' : '';
$desc = static fn(string $name, string $extra = ''): string => ' aria-describedby="' . e(trim($extra . (!empty($errors[$name]) ? ' err-' . $name : ''))) . '"';
$titleRuLen = mb_strlen((string)$fv['title_ru']);
$stateLabels = ['ru' => t('news.tab.filled'), 'empty' => t('news.tab.empty')];
$ruFilled = trim((string)$fv['title_ru']) !== '';
$strip = static fn(string $h): string => trim(preg_replace('~\s+~u', ' ', strip_tags($h)) ?? '');
$bodyEmpty = static fn(string $h): bool => $h === '' || ($strip($h) === '' && stripos($h, '<img') === false);
$kkFilled = trim((string)$fv['title_kk']) !== ''
    && (!$bodyEmpty((string)$fv['body_kk']) || $bodyEmpty((string)$fv['body_ru']))
    && !(trim((string)$fv['title_kk']) === trim((string)$fv['title_ru']) && trim((string)$fv['body_kk']) === trim((string)$fv['body_ru']));
$ruEmptyAll = trim((string)$fv['title_ru']) === '' && trim((string)$fv['excerpt_ru']) === '' && $bodyEmpty((string)$fv['body_ru']);
$saveDraftVisible = $showSaveDraft && $mode !== 'draft';
$cfg = [
    'autosave'      => $autosave,
    'autosaveUrl'   => $autosaveUrl,
    'slugUrl'       => $slugUrl,
    'state'         => $state,
    'tab'           => $activeTab,
    'conflict'      => $conflict,
    'conflictUrl'   => $conflictUrl,
    'slugBase'      => $slugBase,
    'delay'         => 5000,
    'minGap'        => 15000,
    'labels'        => $submitLabels,
    'deleteConfirm' => t('news.delete.confirm', ['title' => ':title']),
    'i18n'          => [
        'filled'       => t('news.tab.filled'),
        'empty'        => t('news.tab.empty'),
        'counter'      => t('news.field.title_counter', ['n' => ':n']),
        'saved'        => t('news.autosave.saved', ['time' => ':time']),
        'saving'       => t('news.autosave.saving'),
        'dirty'        => t('news.autosave.dirty'),
        'failed'       => t('news.autosave.failed'),
        'invalid'      => t('news.autosave.invalid'),
        'needTitle'    => t('news.autosave.need_title'),
        'session'      => t('news.autosave.session'),
        'notDraft'     => t('news.autosave.not_draft'),
        'copyConfirm'  => t('news.copy_from_ru.confirm'),
        'slugTaken'    => t('news.error.slug_taken', ['title' => ':title']),
        'slugAuto'     => t('news.slug.auto_hint'),
        'slugWarn'     => t('news.slug.change_warning'),
        'linkPrompt'   => t('news.tb.link_prompt'),
        'linkBad'      => t('news.tb.link_bad'),
        'errTitle'     => t('news.error.title_required'),
    ],
];
$cfgJson = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
$svgEye = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
$pubOpts = [
    'draft' => ['news-pub-draft', t('news.pub.draft'), t('news.pub.draft_hint')],
    'now'   => ['news-pub-now', t('news.pub.now'), t('news.pub.now_hint')],
    'later' => ['news-pub-later', t('news.pub.later'), t('news.pub.later_hint')],
];
?>
<form id="news-form" class="newsform" method="post" action="<?= e($formAction) ?>" novalidate data-once data-news-form data-tab="<?= e($activeTab) ?>"
  data-config="<?= e($cfgJson) ?>" data-testid="news-form">
  <?= csrf_field() ?>
  <input type="hidden" name="form_key" value="<?= e((string)($fv['form_key'] ?? '')) ?>">
  <input type="hidden" name="id" value="<?= $id !== null ? (int)$id : '' ?>">
  <input type="hidden" name="version" value="<?= $fv['version'] !== null ? (int)$fv['version'] : '' ?>">
  <input type="hidden" name="slug_auto" value="<?= e((string)$fv['slug_auto']) ?>">

  <div class="news-actions" data-testid="news-actions">
    <?php if ($autosave): ?>
    <span class="news-actions__status" id="news-autosave-status" role="status" aria-live="polite" data-testid="news-autosave-status"></span>
    <?php endif; ?>
    <button type="submit" class="btn btn--outline" formaction="<?= e($previewUrl) ?>" formtarget="_blank" formnovalidate data-testid="news-preview"><?= $svgEye ?><?= e(t('news.btn.preview')) ?></button>
    <?php if ($showSaveDraft): ?>
    <button type="submit" class="btn btn--outline" name="intent" value="draft" data-testid="news-save-draft"<?= $saveDraftVisible ? '' : ' hidden' ?>><?= e(t('news.btn.save_draft')) ?></button>
    <?php endif; ?>
    <button type="submit" class="btn btn--md btn--create" name="intent" value="submit" data-testid="news-submit"><?= e($submitLabels[$mode] ?? $submitLabels['now']) ?></button>
  </div>

  <?php if ($conflict): ?>
  <div class="alert alert--danger news-conflict" role="alert" data-testid="news-conflict">
    <span><?= e(t('news.conflict')) ?></span>
    <a href="<?= e($conflictUrl) ?>" data-testid="news-conflict-open"><?= e(t('news.conflict.reload')) ?></a>
  </div>
  <?php endif; ?>
  <?php if ($errors): ?>
  <div class="alert alert--danger" role="alert" data-testid="form-error"><?= e(t('news.error.form')) ?></div>
  <?php endif; ?>

  <div class="news-grid">
    <div class="news-col">
      <section class="card news-card news-card--tabs" aria-label="<?= e(t('news.form.lang_tabs')) ?>">
        <div class="news-tabs" role="tablist" aria-label="<?= e(t('news.form.lang_tabs')) ?>">
          <button type="button" class="news-tab" role="tab" id="tab-ru" aria-controls="panel-ru" aria-selected="<?= $activeTab === 'ru' ? 'true' : 'false' ?>" tabindex="<?= $activeTab === 'ru' ? '0' : '-1' ?>" data-tab="ru" data-testid="news-tab-ru"><?= e(t('news.tab.ru')) ?>
            <span class="lang-badge <?= $ruFilled ? 'lang-badge--ok' : 'lang-badge--warn' ?>" data-testid="news-tab-ru-state"><?= e($ruFilled ? $stateLabels['ru'] : $stateLabels['empty']) ?></span></button>
          <button type="button" class="news-tab" role="tab" id="tab-kk" aria-controls="panel-kk" aria-selected="<?= $activeTab === 'kk' ? 'true' : 'false' ?>" tabindex="<?= $activeTab === 'kk' ? '0' : '-1' ?>" data-tab="kk" data-testid="news-tab-kk"><?= e(t('news.tab.kk')) ?>
            <span class="lang-badge <?= $kkFilled ? 'lang-badge--ok' : 'lang-badge--warn' ?>" data-testid="news-tab-kk-state"><?= e($kkFilled ? $stateLabels['ru'] : $stateLabels['empty']) ?></span></button>
        </div>

        <div class="news-panel" id="panel-ru" role="tabpanel" aria-labelledby="tab-ru" data-panel="ru" data-testid="news-panel-ru">
          <div class="field" data-field="title_ru">
            <label for="f-title_ru"><?= e(t('news.field.title')) ?> <span class="req-star" aria-hidden="true">*</span></label>
            <input class="input input--title" type="text" id="f-title_ru" name="title_ru" maxlength="120" value="<?= e($fv['title_ru']) ?>" autocomplete="off"<?= $id === null ? ' autofocus' : '' ?>
              data-testid="news-title-ru"<?= $inv('title_ru') ?><?= $desc('title_ru', 'title-ru-counter') ?>>
            <span class="field__hint" id="title-ru-counter" data-testid="news-title-ru-counter"><?= e(t('news.field.title_counter', ['n' => $titleRuLen])) ?></span>
            <?= $errFor('title_ru') ?>
          </div>
          <div class="field" data-field="excerpt_ru">
            <label for="f-excerpt_ru"><?= e(t('news.field.excerpt')) ?></label>
            <textarea class="input textarea" id="f-excerpt_ru" name="excerpt_ru" rows="2" maxlength="300" data-testid="news-excerpt-ru"<?= $inv('excerpt_ru') ?><?= $desc('excerpt_ru', 'excerpt-ru-hint') ?>><?= e($fv['excerpt_ru']) ?></textarea>
            <span class="field__hint" id="excerpt-ru-hint"><?= e(t('news.field.excerpt_hint')) ?></span>
            <?= $errFor('excerpt_ru') ?>
          </div>
          <div class="field" data-field="body_ru">
            <span class="field__label" id="b-ru"><?= e(t('news.field.body')) ?></span>
            <?php $ed = ['name' => 'body_ru', 'labelId' => 'b-ru', 'testid' => 'news-body-ru', 'html' => (string)$fv['body_ru']]; include __DIR__ . '/../partials/editor.php'; ?>
            <?= $errFor('body_ru') ?>
          </div>
        </div>

        <div class="news-panel" id="panel-kk" role="tabpanel" aria-labelledby="tab-kk" data-panel="kk" data-testid="news-panel-kk" lang="kk">
          <div class="kk-bar<?= $kkFilled ? '' : ' is-missing' ?>" data-kk-bar>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" data-kk-icon<?= $kkFilled ? ' hidden' : '' ?>><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
            <span class="kk-bar__text" data-testid="news-kk-hint"<?= $kkFilled ? ' hidden' : '' ?>><?= e(t('news.kk_hint')) ?></span>
            <button type="button" class="btn btn--ghost btn--kk" data-testid="news-copy-from-ru"<?= $ruEmptyAll ? ' disabled' : '' ?>><?= e(t('news.copy_from_ru')) ?></button>
          </div>
          <div class="field" data-field="title_kk">
            <label for="f-title_kk"><?= e(t('news.field.title_kk')) ?></label>
            <input class="input input--title" type="text" id="f-title_kk" name="title_kk" maxlength="120" value="<?= e($fv['title_kk']) ?>" placeholder="<?= e(t('news.placeholder.title_kk')) ?>" autocomplete="off"
              data-testid="news-title-kk"<?= $inv('title_kk') ?><?= $desc('title_kk') ?>>
            <?= $errFor('title_kk') ?>
          </div>
          <div class="field" data-field="excerpt_kk">
            <label for="f-excerpt_kk"><?= e(t('news.field.excerpt_kk')) ?></label>
            <textarea class="input textarea" id="f-excerpt_kk" name="excerpt_kk" rows="2" maxlength="300" placeholder="<?= e(t('news.placeholder.excerpt_kk')) ?>" data-testid="news-excerpt-kk"<?= $inv('excerpt_kk') ?><?= $desc('excerpt_kk') ?>><?= e($fv['excerpt_kk']) ?></textarea>
            <?= $errFor('excerpt_kk') ?>
          </div>
          <div class="field" data-field="body_kk">
            <span class="field__label" id="b-kk"><?= e(t('news.field.body_kk')) ?></span>
            <?php $ed = ['name' => 'body_kk', 'labelId' => 'b-kk', 'testid' => 'news-body-kk', 'html' => (string)$fv['body_kk'], 'placeholder' => t('news.placeholder.body_kk')]; include __DIR__ . '/../partials/editor.php'; ?>
            <?= $errFor('body_kk') ?>
          </div>
        </div>
      </section>

      <section class="card news-card" data-testid="news-photos" data-field="photos">
        <div class="news-card__head">
          <h2><?= e(t('news.photos.title')) ?></h2>
          <span class="muted news-card__sub"><?= e(t('news.photos.reorder_hint')) ?></span>
        </div>
        <?php
        $mf = $mfPhotos + ['hideLabel' => true, 'openLabel' => t('news.photos.add'), 'openClass' => 'mpick__add'];
        include __DIR__ . '/../partials/media-field.php';
        ?>
        <?= $errFor('photos') ?>
        <p class="field__hint news-card__note"><?= e(t('news.photos.note')) ?></p>
      </section>
    </div>

    <aside class="news-side">
      <section class="card news-card">
        <fieldset class="news-pub" data-field="publish_date">
          <legend><?= e(t('news.pub.legend')) ?></legend>
          <?php if ($pubCurrent !== ''): ?><p class="news-pub__current" data-testid="news-pub-current"><?= e($pubCurrent) ?></p><?php endif; ?>
          <?php foreach ($pubOpts as $key => [$tid, $label, $hint]): ?>
          <label class="news-pub__opt<?= $mode === $key ? ' is-on' : '' ?>">
            <input type="radio" name="publish_mode" value="<?= e($key) ?>"<?= $mode === $key ? ' checked' : '' ?> data-testid="<?= e($tid) ?>">
            <span class="news-pub__text"><b><?= e($label) ?></b><span><?= e($hint) ?></span></span>
          </label>
          <?php endforeach; ?>
          <div class="news-pub__when" data-when>
            <div class="news-pub__row">
              <label class="news-pub__f"><?= e(t('news.pub.date')) ?>
                <input class="input" type="date" name="publish_date" min="<?= e($minDate) ?>" value="<?= e($fv['publish_date']) ?>" data-testid="news-pub-date"<?= $inv('publish_date') ?><?= $desc('publish_date', 'news-pub-tz') ?>></label>
              <label class="news-pub__f"><?= e(t('news.pub.time')) ?>
                <input class="input" type="time" name="publish_time" value="<?= e($fv['publish_time']) ?>" data-testid="news-pub-time"></label>
            </div>
            <span class="field__hint" id="news-pub-tz" data-testid="news-pub-tz"><?= e($tzHint) ?></span>
          </div>
          <?= $errFor('publish_date') ?>
        </fieldset>
      </section>

      <section class="card news-card" data-testid="news-cover" data-field="cover_media_id">
        <h2><?= e(t('news.cover.title')) ?></h2>
        <?php
        $mf = $mfCover + ['hideLabel' => true, 'openLabel' => t('news.cover.choose'), 'openClass' => 'mpick__cover', 'hint' => t('news.cover.formats')];
        include __DIR__ . '/../partials/media-field.php';
        ?>
        <?= $errFor('cover_media_id') ?>
      </section>

      <section class="card news-card" data-field="slug">
        <label class="news-card__title" for="f-slug"><?= e(t('news.slug.label')) ?></label>
        <input class="input" type="text" id="f-slug" name="slug" value="<?= e($fv['slug']) ?>" autocomplete="off" autocapitalize="none" spellcheck="false"
          data-testid="news-slug"<?= $inv('slug') ?><?= $desc('slug', 'news-slug-preview news-slug-hint') ?>>
        <span class="field__hint news-slug-preview" id="news-slug-preview" data-testid="news-slug-preview"><?= e($slugBase . $fv['slug']) ?></span>
        <span class="field__hint" id="news-slug-hint" data-testid="news-slug-hint"><?= e(t('news.slug.auto_hint')) ?></span>
        <?= $errFor('slug') ?>
      </section>

      <button type="submit" class="news-delete" form="news-delete-form" data-testid="news-delete"<?= $deleteUrl === null ? ' hidden' : '' ?>><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg><?= e(t('news.delete')) ?></button>
    </aside>
  </div>
</form>

<form id="news-delete-form" method="post" action="<?= e($deleteUrl ?? '') ?>" data-confirm="<?= e(t('news.delete.confirm', ['title' => (string)($fv['title_ru'] ?? '')])) ?>" data-once>
  <?= csrf_field() ?>
</form>

<template id="news-conflict-tpl">
  <div class="alert alert--danger news-conflict" role="alert" data-testid="news-conflict">
    <span><?= e(t('news.conflict')) ?></span>
    <a href="<?= e($conflictUrl) ?>" data-testid="news-conflict-open"><?= e(t('news.conflict.reload')) ?></a>
  </div>
</template>

<?php if (once('news-form-scripts')): ?>
<script src="<?= e(asset('admin/editor.js')) ?>" defer></script>
<script src="<?= e(asset('admin/news-form.js')) ?>" defer></script>
<?php endif; ?>
