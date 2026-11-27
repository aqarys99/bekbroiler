<?php
/** @var array $f @var array $errors @var string $activeTab @var string $diskLine @var array $logoField @var array $heroField */
$val = static fn(string $k): string => (string)($f[$k] ?? '');
$err = static function (string $k) use ($errors): string {
    return isset($errors[$k]) ? '<span class="field__error" data-testid="settings-' . e(str_replace('_', '-', $k)) . '-error">' . e($errors[$k]) . '</span>' : '';
};
$text = static function (string $k, string $label, array $o = []) use ($f, $errors, $val, $err): void {
    $tid = 'settings-' . str_replace('_', '-', $k);
    $inv = isset($errors[$k]);
    ?><div class="field" data-field="<?= e($k) ?>">
      <label for="s-<?= e($k) ?>"><?= e($label) ?></label>
      <input class="input" id="s-<?= e($k) ?>" name="<?= e($k) ?>" type="<?= e($o['type'] ?? 'text') ?>" value="<?= e($val($k)) ?>"<?= isset($o['max']) ? ' maxlength="' . (int)$o['max'] . '"' : '' ?><?= isset($o['placeholder']) ? ' placeholder="' . e($o['placeholder']) . '"' : '' ?><?= isset($o['inputmode']) ? ' inputmode="' . e($o['inputmode']) . '"' : '' ?><?= $inv ? ' aria-invalid="true"' : '' ?> data-testid="<?= e($tid) ?>">
      <?php if (!empty($o['hint'])): ?><span class="field__hint"><?= e($o['hint']) ?></span><?php endif; ?>
      <?= $err($k) ?>
    </div><?php
};
$tabs = ['main' => t('settings.tab.main'), 'look' => t('settings.tab.look'), 'contacts' => t('settings.tab.contacts')];
$themes = [
    'sun' => ['#FFF8EC', '#FB8500'],
    'official' => ['#F3F5F9', '#1F4E9C'],
    'nature' => ['#F1F8F2', '#2E8B57'],
];
$colorNames = ['#C2410C' => 'orange', '#1F5BD8' => 'blue', '#2E8B57' => 'green', '#0F8B8D' => 'teal', '#D0447A' => 'pink', '#6A4BC4' => 'violet'];
$curColor = strtoupper($val('color') ?: '#C2410C');
$isPreset = isset($colorNames[$curColor]);
?>
<div class="page-head"><h1><?= e(t('settings.heading')) ?></h1></div>
<div class="tabs tabs--settings" role="tablist" aria-label="<?= e(t('settings.tabs')) ?>" data-settings-tabs data-active="<?= e($activeTab) ?>">
  <?php foreach ($tabs as $id => $label): ?>
  <button type="button" class="tab" role="tab" id="tab-<?= e($id) ?>" aria-controls="panel-<?= e($id) ?>" aria-selected="<?= $id === $activeTab ? 'true' : 'false' ?>" data-tab="<?= e($id) ?>" data-testid="settings-tab-<?= e($id) ?>"><?= e($label) ?></button>
  <?php endforeach; ?>
</div>

<form class="form settings" id="settings-form" method="post" action="<?= e(admin_url('/settings')) ?>" novalidate data-once data-testid="settings-form">
  <?= csrf_field() ?>

  <div class="settings__panel" role="tabpanel" id="panel-main" aria-labelledby="tab-main" data-panel="main">
    <section class="card settings__sec">
      <h2><?= e(t('settings.sec.name')) ?></h2>
      <?php
      $text('site_name', t('settings.site_name'), ['max' => 150, 'hint' => t('settings.site_name.hint')]);
      $text('full_name', t('settings.full_name'), ['max' => 200]);
      $text('full_name_kk', t('settings.full_name_kk'), ['max' => 200]);
      $text('director', t('settings.director'), ['max' => 150]);
      $text('director_kk', t('settings.director_kk'), ['max' => 150]);
      ?>
    </section>
    <section class="card settings__sec">
      <h2><?= e(t('settings.sec.about')) ?></h2>
      <div class="settings__row">
        <?php $text('founded_year', t('settings.founded_year'), ['max' => 4, 'inputmode' => 'numeric']); $text('capacity', t('settings.capacity'), ['max' => 4, 'inputmode' => 'numeric']); ?>
      </div>
      <fieldset class="field">
        <legend class="field__label"><?= e(t('settings.teach_langs')) ?></legend>
        <label class="check"><input type="checkbox" name="teach_langs[]" value="ru"<?= in_array('ru', (array)($f['teach_langs'] ?? []), true) ? ' checked' : '' ?> data-testid="settings-teach-ru"> <?= e(t('settings.lang.ru')) ?></label>
        <label class="check"><input type="checkbox" name="teach_langs[]" value="kk"<?= in_array('kk', (array)($f['teach_langs'] ?? []), true) ? ' checked' : '' ?> data-testid="settings-teach-kk"> <?= e(t('settings.lang.kk')) ?></label>
      </fieldset>
      <?php $text('work_hours', t('settings.work_hours'), ['max' => 200, 'placeholder' => t('settings.work_hours.ph')]); ?>
    </section>
    <section class="card settings__sec">
      <h2><?= e(t('settings.sec.legal')) ?></h2>
      <div class="settings__row">
        <?php $text('license_number', t('settings.license_number'), ['max' => 60]); $text('license_date', t('settings.license_date'), ['type' => 'date']); ?>
      </div>
      <?php $text('bin', t('settings.bin'), ['max' => 12, 'inputmode' => 'numeric', 'hint' => t('settings.bin.hint')]); ?>
    </section>
    <section class="card settings__sec">
      <h2><?= e(t('settings.sec.disk')) ?></h2>
      <p class="field__hint" data-testid="settings-disk-line"><?= e($diskLine) ?></p>
      <?php $text('disk_quota_mb', t('settings.disk_quota'), ['inputmode' => 'decimal', 'max' => 12, 'hint' => t('settings.disk_quota.hint')]); ?>
    </section>
  </div>

  <div class="settings__panel" role="tabpanel" id="panel-look" aria-labelledby="tab-look" data-panel="look">
    <section class="card settings__sec">
      <h2><?= e(t('settings.sec.theme')) ?></h2>
      <p class="field__hint"><?= e(t('settings.theme.hint')) ?></p>
      <div class="theme-grid" role="radiogroup" aria-label="<?= e(t('settings.sec.theme')) ?>">
        <?php foreach ($themes as $id => [$bg, $main]): $sel = $val('theme') === $id; ?>
        <label class="theme-card<?= $sel ? ' is-on' : '' ?>">
          <input type="radio" class="sr-only" name="theme" value="<?= e($id) ?>"<?= $sel ? ' checked' : '' ?> data-testid="settings-theme-<?= e($id) ?>">
          <span class="theme-card__pic" style="background:<?= e($bg) ?>"><span class="theme-card__body"><i style="width:60%;height:12px;background:<?= e($main) ?>"></i><i style="width:85%"></i><i style="width:70%"></i><i style="margin-top:auto;width:40%;height:18px;border-radius:999px;background:<?= e($main) ?>"></i></span></span>
          <span class="theme-card__meta"><span><b><?= e(t('settings.theme.' . $id)) ?></b><br><span class="field__hint"><?= e(t('settings.theme.' . $id . '.desc')) ?></span></span><span class="badge-sel"><?= e(t('settings.theme.selected')) ?></span></span>
        </label>
        <?php endforeach; ?>
      </div>
      <?= $err('theme') ?>
      <div class="settings__row">
        <fieldset class="field" data-color-field>
          <legend class="field__label"><?= e(t('settings.color')) ?></legend>
          <div class="swatches">
            <?php foreach ($colorNames as $hex => $nm): ?>
            <label class="swatch" title="<?= e(t('settings.color.' . $nm)) ?>"><input type="radio" class="sr-only" name="color_preset" value="<?= e($hex) ?>"<?= $isPreset && $curColor === $hex ? ' checked' : '' ?> aria-label="<?= e(t('settings.color.' . $nm)) ?>" data-testid="settings-color-<?= e($nm) ?>"><span style="background:<?= e($hex) ?>"></span></label>
            <?php endforeach; ?>
          </div>
          <label for="s-color_custom" class="field__hint"><?= e(t('settings.color.custom')) ?></label>
          <input class="input input--short" id="s-color_custom" name="color_custom" maxlength="7" placeholder="#1F5BD8" value="<?= e($isPreset ? '' : $curColor) ?>"<?= isset($errors['color']) ? ' aria-invalid="true"' : '' ?> data-testid="settings-color-custom">
          <?= $err('color') ?>
        </fieldset>
        <div class="field">
          <?php $mf = $logoField; $mf['hint'] = t('settings.logo.hint'); $mf['openLabel'] = t('settings.logo.change'); include __DIR__ . '/../partials/media-field.php'; ?>
          <?= $err('logo_media_id') ?>
        </div>
        <div class="field">
          <?php $mf = $heroField; $mf['hint'] = t('settings.hero.hint'); $mf['openLabel'] = t('settings.hero.change'); include __DIR__ . '/../partials/media-field.php'; ?>
          <?= $err('hero_media_id') ?>
        </div>
      </div>
    </section>
    <section class="card settings__sec">
      <h2><?= e(t('settings.sec.langs')) ?></h2>
      <div class="settings__line">
        <div><b><?= e(t('settings.lang.ru')) ?></b><br><span class="field__hint"><?= e(t('settings.lang.ru.hint')) ?></span></div>
        <span class="pill pill--ok"><?= e(t('settings.lang.on')) ?></span>
      </div>
      <div class="settings__line">
        <div><b><?= e(t('settings.lang.kk')) ?></b><br><span class="field__hint"><?= e(t('settings.lang.kk.hint')) ?></span></div>
        <label class="check"><input type="checkbox" name="kk_enabled" value="1"<?= !empty($f['kk_enabled']) ? ' checked' : '' ?> data-testid="settings-kk-enabled"> <?= e(t('settings.lang.kk.show')) ?></label>
      </div>
      <div class="settings__line">
        <label for="s-default_lang"><b><?= e(t('settings.default_lang')) ?></b><br><span class="field__hint"><?= e(t('settings.default_lang.hint')) ?></span></label>
        <select class="input input--short" id="s-default_lang" name="default_lang" data-testid="settings-default-lang">
          <option value="ru"<?= $val('default_lang') !== 'kk' ? ' selected' : '' ?>><?= e(t('settings.lang.ru')) ?></option>
          <option value="kk"<?= $val('default_lang') === 'kk' ? ' selected' : '' ?>><?= e(t('settings.lang.kk')) ?></option>
        </select>
      </div>
      <?= $err('default_lang') ?>
    </section>
    <section class="card settings__sec settings__line">
      <div><h2><?= e(t('settings.a11y')) ?></h2><span class="field__hint"><?= e(t('settings.a11y.hint')) ?></span></div>
      <label class="check"><input type="checkbox" name="a11y_enabled" value="1"<?= !empty($f['a11y_enabled']) ? ' checked' : '' ?> data-testid="settings-a11y"> <?= e(t('settings.a11y.show')) ?></label>
    </section>
  </div>

  <div class="settings__panel" role="tabpanel" id="panel-contacts" aria-labelledby="tab-contacts" data-panel="contacts">
    <section class="card settings__sec">
      <h2><?= e(t('settings.sec.contacts')) ?></h2>
      <?php
      $text('address', t('settings.address'), ['max' => 250]);
      $text('address_kk', t('settings.address_kk'), ['max' => 250]);
      ?>
      <div class="field" data-field="phones">
        <label for="s-phones"><?= e(t('settings.phones')) ?></label>
        <textarea class="input input--area" id="s-phones" name="phones" rows="3"<?= isset($errors['phones']) ? ' aria-invalid="true"' : '' ?> data-testid="settings-phones"><?= e($val('phones')) ?></textarea>
        <span class="field__hint"><?= e(t('settings.phones.hint')) ?></span>
        <?= $err('phones') ?>
      </div>
      <?php
      $text('email', t('settings.email'), ['type' => 'email', 'max' => 150]);
      $text('whatsapp', t('settings.whatsapp'), ['max' => 25, 'hint' => t('settings.whatsapp.hint')]);
      ?>
    </section>
    <section class="card settings__sec">
      <h2><?= e(t('settings.sec.social')) ?></h2>
      <?php
      foreach (['instagram', 'facebook', 'telegram', 'twogis'] as $s) {
          $text($s, t('settings.' . $s), ['max' => 300, 'placeholder' => 'https://', 'inputmode' => 'url']);
      }
      ?>
    </section>
    <section class="card settings__sec">
      <h2><?= e(t('settings.sec.map')) ?></h2>
      <div class="field" data-field="map_code">
        <label for="s-map_code"><?= e(t('settings.map')) ?></label>
        <textarea class="input input--area" id="s-map_code" name="map_code" rows="4" maxlength="3000" spellcheck="false"<?= isset($errors['map_code']) ? ' aria-invalid="true"' : '' ?> data-testid="settings-map-code"><?= e($val('map_code')) ?></textarea>
        <span class="field__hint"><?= e(t('settings.map.hint')) ?></span>
        <?= $err('map_code') ?>
      </div>
    </section>
  </div>

  <div class="actions">
    <button type="submit" class="btn btn--md" data-testid="settings-submit"><?= e(t('settings.save')) ?></button>
  </div>
</form>
<script src="<?= e(asset('admin/settings.js')) ?>" defer></script>
