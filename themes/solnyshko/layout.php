<?php
/**
 * Каркас страницы темы. Данные: $site (name, logo, flags, pages, contacts, kk, a11y_enabled, switch, year),
 * $meta (title, description, canonical, alternates, og_type, og_image, active, noindex), $content.
 *
 * @var array $site
 * @var array $meta
 * @var string $content
 */
use App\Site\Ctx;
use App\Site\Theme;

$lang = Ctx::$lang;
$f = $site['flags'];
$navMain = $site['nav']['main'];
$navFoot = $site['nav']['footer'];
$navAttr = static fn(array $it): string => ($it['blank'] ? ' target="_blank" rel="noopener"' : '');
?>
<!doctype html>
<html lang="<?= e($lang) ?>"<?= Ctx::$a11y ? ' class="a11y"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:,">
<title><?= e($meta['title']) ?></title>
<?php if ($meta['description'] !== ''): ?><meta name="description" content="<?= e($meta['description']) ?>">
<?php endif; ?>
<?php if (!empty($meta['noindex'])): ?><meta name="robots" content="noindex">
<?php else: ?><link rel="canonical" href="<?= e($meta['canonical']) ?>">
<?php endif; ?>
<?php foreach ($meta['alternates'] as $l => $href): ?><link rel="alternate" hreflang="<?= e($l) ?>" href="<?= e($href) ?>">
<?php endforeach; ?>
<?php if ($meta['alternates']): ?><link rel="alternate" hreflang="x-default" href="<?= e($meta['alternates']['ru']) ?>">
<?php endif; ?>
<meta property="og:site_name" content="<?= e($site['name']) ?>">
<meta property="og:type" content="<?= e($meta['og_type']) ?>">
<meta property="og:title" content="<?= e($meta['title']) ?>">
<meta property="og:description" content="<?= e($meta['description']) ?>">
<meta property="og:url" content="<?= e($meta['canonical']) ?>">
<meta property="og:locale" content="<?= $lang === 'kk' ? 'kk_KZ' : 'ru_RU' ?>">
<?php if ($meta['og_image'] !== ''): ?><meta property="og:image" content="<?= e($meta['og_image']) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
<?php foreach (Theme::cssUrls() as $css): ?><link rel="stylesheet" href="<?= e($css) ?>">
<?php endforeach; ?>
<?php if (($vars = Theme::cssVars()) !== ''): ?><style><?= $vars /* только проверенный #RRGGBB */ ?></style>
<?php endif; ?>
</head>
<body>
<a class="skip" href="#main"><?= e(tt('skip')) ?></a>
<header class="site-header">
  <div class="container nav">
    <a href="<?= e(surl('/')) ?>" class="logo" data-testid="site-logo">
      <?php if ($site['logo'] !== null && ($pic = media_picture($site['logo'], 'thumb', ['alt' => '', 'loading' => 'eager', 'class' => 'logo-img'])) !== ''): ?>
        <?= $pic ?>
      <?php else: ?>
      <svg class="logo-sun" viewBox="0 0 64 64" aria-hidden="true">
        <g stroke="var(--primary)" stroke-width="5" stroke-linecap="round">
          <line x1="32" y1="4" x2="32" y2="12"/><line x1="32" y1="52" x2="32" y2="60"/>
          <line x1="4" y1="32" x2="12" y2="32"/><line x1="52" y1="32" x2="60" y2="32"/>
          <line x1="12" y1="12" x2="17" y2="17"/><line x1="47" y1="47" x2="52" y2="52"/>
          <line x1="12" y1="52" x2="17" y2="47"/><line x1="47" y1="17" x2="52" y2="12"/>
        </g>
        <circle cx="32" cy="32" r="14" fill="var(--primary)"/>
      </svg>
      <?php endif; ?>
      <span class="logo-text" data-testid="site-name"><?= e($site['name']) ?></span>
    </a>
    <button class="burger" type="button" aria-label="<?= e(tt('menu.open')) ?>" aria-expanded="false" aria-controls="menu" data-testid="site-burger">☰</button>
    <nav aria-label="<?= e(tt('menu.label')) ?>">
      <ul class="menu" id="menu">
        <?php foreach ($navMain as $i => $it): ?>
          <?php if ($it['children']): $sid = 'submenu-' . $i; ?>
          <li class="has-sub<?= $it['active'] ? ' is-active' : '' ?>" data-testid="nav-group-<?= (int)$i ?>">
            <?php if ($it['url'] !== '' && !$it['group']): ?><a href="<?= e($it['url']) ?>"<?= $navAttr($it) ?><?= $it['active'] ? ' aria-current="page"' : '' ?>><?= e($it['label']) ?></a><?php endif; ?>
            <button type="button" class="menu-toggle" aria-expanded="false" aria-controls="<?= e($sid) ?>" data-testid="nav-toggle-<?= (int)$i ?>"<?= ($it['url'] !== '' && !$it['group']) ? ' aria-label="' . e(tt('menu.sub')) . ': ' . e($it['label']) . '"' : '' ?>><?= ($it['url'] !== '' && !$it['group']) ? '' : e($it['label']) ?></button>
            <ul class="submenu" id="<?= e($sid) ?>">
              <?php foreach ($it['children'] as $sub): ?>
                <li><a href="<?= e($sub['url']) ?>"<?= $navAttr($sub) ?><?= $sub['active'] ? ' aria-current="page"' : '' ?> data-testid="nav-<?= e($sub['key'] !== '' ? $sub['key'] : 'link') ?>"><?= e($sub['label']) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </li>
          <?php elseif ($it['url'] !== ''): ?>
          <li><a href="<?= e($it['url']) ?>"<?= $navAttr($it) ?><?= $it['active'] ? ' aria-current="page"' : '' ?> data-testid="nav-<?= e($it['key'] !== '' ? $it['key'] : 'link') ?>"><?= e($it['label']) ?></a></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </nav>
    <div class="tools">
      <?php if ($site['kk']): ?>
        <div class="lang" role="group" aria-label="<?= e(tt('lang.switch')) ?>">
          <a href="<?= e($site['switch']['ru']) ?>" hreflang="ru" lang="ru"<?= $lang === 'ru' ? ' aria-current="true"' : '' ?> data-testid="lang-ru">RU</a>
          <a href="<?= e($site['switch']['kk']) ?>" hreflang="kk" lang="kk"<?= $lang === 'kk' ? ' aria-current="true"' : '' ?> data-testid="lang-kk">ҚАЗ</a>
        </div>
      <?php endif; ?>
      <?php if ($site['a11y_enabled']): ?>
        <button type="button" class="a11y-btn" data-a11y-toggle aria-pressed="<?= Ctx::$a11y ? 'true' : 'false' ?>" data-testid="a11y-toggle" title="<?= e(tt('a11y.title')) ?>">
          <span aria-hidden="true">👁</span> <span class="a11y-btn__t"><?= e(Ctx::$a11y ? tt('a11y.off') : tt('a11y.on')) ?></span>
        </button>
      <?php endif; ?>
    </div>
  </div>
</header>

<main id="main">
<?php if (Ctx::$missing): ?>
  <div class="notice" role="note" data-testid="site-no-translation"><div class="container"><?= e(tt('missing')) ?></div></div>
<?php endif; ?>
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="container foot">
    <div>
      <b><?= e($site['name']) ?></b>
      <?php if ($site['contacts']['address'] !== ''): ?><p><?= e($site['contacts']['address']) ?></p><?php endif; ?>
      <?php foreach ((array)$site['contacts']['phones'] as $ph): ?><p><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)$ph)) ?>"><?= e($ph) ?></a></p><?php endforeach; ?>
      <?php if ($site['contacts']['email'] !== ''): ?><p><a href="mailto:<?= e($site['contacts']['email']) ?>"><?= e($site['contacts']['email']) ?></a></p><?php endif; ?>
    </div>
    <?php if ($navFoot): ?>
    <ul class="foot-nav" aria-label="<?= e(tt('footer.sections')) ?>" data-testid="site-footer-nav">
      <?php foreach ($navFoot as $it): ?>
        <?php if ($it['children']): ?>
        <li class="foot-group"><span><?= e($it['label']) ?></span><ul>
          <?php foreach ($it['children'] as $sub): ?><li><a href="<?= e($sub['url']) ?>"<?= $navAttr($sub) ?>><?= e($sub['label']) ?></a></li><?php endforeach; ?>
        </ul></li>
        <?php elseif ($it['url'] !== ''): ?>
        <li><a href="<?= e($it['url']) ?>"<?= $navAttr($it) ?>><?= e($it['label']) ?></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <div class="container foot-copy">
    <span>© <?= e($site['year']) ?> <?= e($site['name']) ?></span>
    <span><?= e(tt('footer.rights')) ?></span>
  </div>
</footer>
<script src="<?= e(Theme::assetUrl('site.js')) ?>" defer></script>
</body>
</html>
