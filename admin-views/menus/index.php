<?php
/** @var string $menuKey @var array $tree @var array $pagesJson @var array $sections @var array $errors */
$base = admin_url('/menus');
$i18n = [];
foreach (['type_page', 'type_section', 'type_link', 'type_group', 'up', 'down', 'indent', 'outdent', 'expand', 'collapse', 'remove', 'title_ru', 'title_kk',
          'url', 'new_tab', 'empty', 'hidden_page', 'leave', 'need_url', 'need_title', 'nothing_selected', 'drag', 'no_pages', 'url_hint', 'default_title'] as $k) {
    $i18n[$k] = t('menus.js.' . $k);
}
$json = static fn(mixed $v): string => e(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
?>
<div class="page-head">
  <div><h1><?= e(t('menus.title')) ?></h1><p><?= e(t('menus.lead')) ?></p></div>
  <button type="submit" form="menu-form" class="btn btn--md" data-testid="menu-save"><?= e(t('menus.save')) ?></button>
</div>

<div class="news-filters" role="tablist" aria-label="<?= e(t('menus.switch')) ?>">
  <a class="news-filter" role="tab" href="<?= e($base . '?menu=main') ?>" aria-selected="<?= $menuKey === 'main' ? 'true' : 'false' ?>" data-menu-switch data-testid="menu-switch-main"><?= e(t('menus.main')) ?></a>
  <a class="news-filter" role="tab" href="<?= e($base . '?menu=footer') ?>" aria-selected="<?= $menuKey === 'footer' ? 'true' : 'false' ?>" data-menu-switch data-testid="menu-switch-footer"><?= e(t('menus.footer')) ?></a>
</div>

<?php if ($errors): ?>
<div class="alert alert--danger" role="alert" data-testid="menu-errors"><ul><?php foreach ($errors as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="menu-editor" data-menu-editor data-tree="<?= $json($tree) ?>" data-pages="<?= $json($pagesJson) ?>" data-sections="<?= $json($sections) ?>" data-i18n="<?= $json($i18n) ?>">
  <aside class="card menu-add" data-testid="menu-add">
    <h2><?= e(t('menus.add_title')) ?></h2>
    <div class="menu-tabs" role="tablist">
      <?php foreach (['pages', 'sections', 'link', 'group'] as $i => $tab): ?>
      <button type="button" class="menu-tab" role="tab" id="menu-tab-<?= $tab ?>" aria-controls="menu-panel-<?= $tab ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" data-tab="<?= $tab ?>" data-testid="menu-tab-<?= $tab ?>"><?= e(t('menus.tab.' . $tab)) ?></button>
      <?php endforeach; ?>
    </div>

    <div id="menu-panel-pages" role="tabpanel" aria-labelledby="menu-tab-pages" data-panel="pages">
      <input class="input" type="search" placeholder="<?= e(t('menus.pages.search')) ?>" aria-label="<?= e(t('menus.pages.search')) ?>" data-role="page-search" data-testid="menu-pages-search">
      <div class="menu-check-list" data-role="page-list" data-testid="menu-pages-list"></div>
      <button type="button" class="btn btn--md" data-role="add-pages" data-testid="menu-pages-add"><?= e(t('menus.add')) ?></button>
    </div>
    <div id="menu-panel-sections" role="tabpanel" aria-labelledby="menu-tab-sections" data-panel="sections" hidden>
      <p class="field__hint"><?= e(t('menus.sections.hint')) ?></p>
      <div class="menu-check-list" data-role="section-list" data-testid="menu-sections-list"></div>
      <button type="button" class="btn btn--md" data-role="add-sections" data-testid="menu-sections-add"><?= e(t('menus.add')) ?></button>
    </div>
    <div id="menu-panel-link" role="tabpanel" aria-labelledby="menu-tab-link" data-panel="link" hidden>
      <div class="field"><label for="menu-link-url"><?= e(t('menus.js.url')) ?></label><input class="input" type="text" id="menu-link-url" placeholder="https://… <?= e(t('menus.link.or')) ?> /kontakty" data-role="link-url" data-testid="menu-link-url"><span class="field__hint"><?= e(t('menus.js.url_hint')) ?></span></div>
      <div class="field"><label for="menu-link-title"><?= e(t('menus.link.title')) ?></label><input class="input" type="text" id="menu-link-title" maxlength="100" data-role="link-title" data-testid="menu-link-title"></div>
      <label class="check"><input type="checkbox" data-role="link-blank" data-testid="menu-link-blank"> <?= e(t('menus.js.new_tab')) ?></label>
      <p class="field__error" data-role="link-error" data-testid="menu-link-error" hidden></p>
      <button type="button" class="btn btn--md" data-role="add-link" data-testid="menu-link-add"><?= e(t('menus.add')) ?></button>
    </div>
    <div id="menu-panel-group" role="tabpanel" aria-labelledby="menu-tab-group" data-panel="group" hidden>
      <p class="field__hint"><?= e(t('menus.group.hint')) ?></p>
      <div class="field"><label for="menu-group-title"><?= e(t('menus.group.title')) ?></label><input class="input" type="text" id="menu-group-title" maxlength="100" data-role="group-title" data-testid="menu-group-title"></div>
      <p class="field__error" data-role="group-error" data-testid="menu-group-error" hidden></p>
      <button type="button" class="btn btn--md" data-role="add-group" data-testid="menu-group-add"><?= e(t('menus.add')) ?></button>
    </div>
  </aside>

  <section class="card menu-tree" aria-label="<?= e(t('menus.structure')) ?>">
    <h2><?= e(t('menus.structure')) ?></h2>
    <p class="field__hint"><?= e(t('menus.structure.hint')) ?></p>
    <ul class="menu-list" data-role="tree" data-testid="menu-tree"></ul>
    <p class="empty" data-role="empty" data-testid="menu-empty" hidden><?= e(t('menus.js.empty')) ?></p>
    <form id="menu-form" method="post" action="<?= e($base) ?>" data-once data-testid="menu-form">
      <?= csrf_field() ?>
      <input type="hidden" name="menu" value="<?= e($menuKey) ?>">
      <input type="hidden" name="tree" value="" data-role="tree-input">
      <button type="submit" class="btn btn--md" data-testid="menu-save-bottom"><?= e(t('menus.save')) ?></button>
    </form>
  </section>
</div>
<script src="<?= e(asset('admin/menu-editor.js')) ?>" defer></script>
