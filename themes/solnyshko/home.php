<?php
/**
 * Главная.
 * @var array $site @var ?array $hero @var array $stats @var array $news @var array $staff @var array $groups
 * @var ?array $about @var string $aboutBody @var array $info
 */
use App\Site\Ctx;

$quick = [];
if ($site['flags']['news']) { $quick[] = ['/novosti', '📰', tt('nav.news'), tt('quick.news')]; }
if ($site['flags']['gallery']) { $quick[] = ['/fotogalereya', '📷', tt('nav.gallery'), tt('quick.gallery')]; }
if ($site['flags']['documents']) { $quick[] = ['/dokumenty', '📄', tt('nav.documents'), tt('quick.documents')]; }
if ($site['flags']['videos']) { $quick[] = ['/video', '🎬', tt('nav.videos'), tt('quick.videos')]; }
if (isset($site['pages']['rezhim'])) { $quick[] = ['/rezhim', '🕒', tt('nav.schedule'), tt('quick.schedule')]; }
$quick[] = ['/kontakty', '📍', tt('nav.contacts'), tt('quick.contacts')];
$icons = ['i-y', 'i-g', 'i-b', 'i-p'];
?>
<div class="hero">
  <div class="container">
    <div class="hero-grid">
      <div>
        <span class="eyebrow"><?= e(tt('home.eyebrow')) ?></span>
        <h1 data-testid="home-title"><?= e($site['name']) ?> <span><?= e(tt('home.tagline')) ?></span></h1>
        <p class="lead"><?= e(tt('home.lead')) ?></p>
        <div class="btns">
          <?php if ($site['flags']['documents']): ?><a href="<?= e(surl('/dokumenty')) ?>" class="btn btn-primary"><?= e(tt('home.btn_docs')) ?></a><?php endif; ?>
          <a href="<?= e(surl('/kontakty')) ?>" class="btn btn-outline"><?= e(tt('home.btn_contact')) ?></a>
        </div>
      </div>
      <div class="hero-art">
        <?php if ($hero !== null && ($pic = media_picture($hero, 'large', ['alt' => $site['name'], 'loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => '(max-width: 900px) 100vw, 520px'])) !== ''): ?>
          <div class="hero-photo" data-testid="home-hero-image"><?= $pic ?></div>
        <?php else: ?>
          <svg viewBox="0 0 400 300" width="100%" aria-hidden="true" data-testid="home-hero-art">
            <rect x="0" y="230" width="400" height="70" rx="12" fill="#86efac"/>
            <circle cx="330" cy="60" r="34" fill="var(--primary)"/>
            <rect x="90" y="120" width="200" height="120" rx="10" fill="#fde68a"/>
            <polygon points="70,125 190,50 310,125" fill="#f97316"/>
            <rect x="170" y="170" width="40" height="70" rx="6" fill="#92400e"/>
            <rect x="110" y="145" width="40" height="36" rx="6" fill="#bfdbfe"/>
            <rect x="230" y="145" width="40" height="36" rx="6" fill="#bfdbfe"/>
            <circle cx="40" cy="200" r="28" fill="#22c55e"/><rect x="36" y="200" width="8" height="35" fill="#92400e"/>
            <circle cx="360" cy="205" r="24" fill="#22c55e"/><rect x="356" y="205" width="8" height="30" fill="#92400e"/>
            <circle cx="190" cy="95" r="12" fill="#fff"/>
          </svg>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($stats): ?>
    <div class="stats" data-testid="home-stats">
      <?php foreach ($stats as $st): ?><div class="stat"><b><?= e($st['n']) ?></b><span><?= e($st['label']) ?></span></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($aboutBody !== '' || $info): ?>
<section id="about">
  <div class="container about-grid">
    <div>
      <span class="eyebrow"><?= e(tt('home.about_eyebrow')) ?></span>
      <h2><?= e(tt('home.about_title')) ?></h2>
      <div class="prose"><?= $aboutBody /* санитизировано при сохранении */ ?></div>
    </div>
    <?php if ($info): ?>
    <div class="card">
      <ul class="info-list">
        <?php foreach ($info as $row): ?><li><span><?= e($row['label']) ?></span><span><?= e($row['value']) ?></span></li><?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section id="quick">
  <div class="container">
    <div class="section-head"><h2><?= e(tt('home.quick_title')) ?></h2></div>
    <div class="grid grid-3">
      <?php foreach ($quick as $i => [$path, $ico, $label, $text]): ?>
        <a class="card quick" href="<?= e(surl($path)) ?>" data-testid="home-quick">
          <div class="icon <?= e($icons[$i % 4]) ?>" aria-hidden="true"><?= $ico ?></div>
          <h3><?= e($label) ?></h3><p><?= e($text) ?></p>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($news): ?>
<section id="news">
  <div class="container">
    <div class="section-head">
      <span class="eyebrow"><?= e(tt('home.news_eyebrow')) ?></span>
      <h2><?= e(tt('nav.news')) ?></h2>
    </div>
    <div class="grid grid-3" data-testid="home-news">
      <?php foreach ($news as $n): include \App\Site\Theme::find('_news-card'); endforeach; ?>
    </div>
    <p class="more"><a class="btn btn-outline" href="<?= e(surl('/novosti')) ?>"><?= e(tt('news.all')) ?></a></p>
  </div>
</section>
<?php endif; ?>

<?php if ($groups): ?>
<section id="groups">
  <div class="container">
    <div class="section-head"><span class="eyebrow"><?= e(tt('groups.eyebrow')) ?></span><h2><?= e(tt('nav.groups')) ?></h2></div>
    <div class="grid grid-4">
      <?php foreach ($groups as $g): include \App\Site\Theme::find('_group-card'); endforeach; ?>
    </div>
    <p class="more"><a class="btn btn-outline" href="<?= e(surl('/gruppy')) ?>"><?= e(tt('groups.all')) ?></a></p>
  </div>
</section>
<?php endif; ?>

<?php if ($staff): ?>
<section id="teachers">
  <div class="container">
    <div class="section-head"><span class="eyebrow"><?= e(tt('staff.eyebrow')) ?></span><h2><?= e(tt('staff.title')) ?></h2></div>
    <div class="grid grid-4">
      <?php foreach ($staff as $i => $s): include \App\Site\Theme::find('_staff-card'); endforeach; ?>
    </div>
    <p class="more"><a class="btn btn-outline" href="<?= e(surl('/pedagogi')) ?>"><?= e(tt('staff.all')) ?></a></p>
  </div>
</section>
<?php endif; ?>
