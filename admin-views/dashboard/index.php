<?php
/**
 * Главная админки.
 * @var array $user @var array $check @var array $checkRows @var array $trans @var array $disk @var array $latest @var array $actions
 */
$icons = [
  'plus' => '<path d="M12 5v14M5 12h14"/>',
  'x' => '<path d="M6 6l12 12M18 6 6 18"/>',
  'warn' => '<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>',
  'ok' => '<path d="m5 12 5 5 9-10"/>',
  'image' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-8 8"/>',
  'video' => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3"/>',
  'news' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h10M7 16h6"/>',
  'doc' => '<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
];
$svg = static fn(string $n, int $size = 14, string $sw = '2.4', string $stroke = 'currentColor'): string =>
  '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="' . $stroke . '" stroke-width="' . $sw . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$n] ?? '') . '</svg>';
$newsUrl = admin_url('/news/new');
$pillClass = ['published' => 'pill-state--published', 'scheduled' => 'pill-state--scheduled', 'draft' => 'pill-state--draft'];
?>
<div class="dash">
  <div class="page-head">
    <div data-testid="dashboard-greeting">
      <h1><?= e(t('dashboard.greeting', ['name' => $user['name']])) ?></h1>
      <p data-testid="dashboard-subtitle"><?= e(t('dashboard.text')) ?></p>
    </div>
    <a class="btn btn--md btn--create" href="<?= e($newsUrl) ?>" data-testid="dashboard-add-news"><?= $svg('plus', 18, '2') ?><?= e(t('dashboard.add_news')) ?></a>
  </div>

  <div class="dash__cols">
    <section class="card dash__check" data-testid="dashboard-checklist">
      <div class="dash__row-head">
        <h2><?= e(t('dashboard.check.title')) ?></h2>
        <span class="dash__muted" data-testid="dashboard-checklist-count"><?= t('dashboard.check.count', ['done' => '<b>' . (int)$check['done'] . '</b>', 'total' => (int)$check['total']]) ?></span>
      </div>
      <div class="bar" role="img" aria-label="<?= e(t('dashboard.check.aria', ['p' => $check['percent']])) ?>" data-testid="dashboard-checklist-bar"><div class="bar__fill" style="width:<?= (int)$check['percent'] ?>%"></div></div>
      <?php if (!$checkRows): ?>
      <p class="dash__muted" data-testid="dashboard-checklist-empty"><?= e(t('dashboard.check.empty')) ?></p>
      <?php else: ?>
      <div class="dash__list" data-testid="dashboard-checklist-list">
        <?php foreach ($checkRows as $it):
          $st = $it['state'];
          $pill = ['missing' => ['pill-state--danger', 'x', t('dashboard.check.missing')],
                   'expired' => ['pill-state--danger', 'x', t('dashboard.check.expired', ['date' => $it['date']])],
                   'warn'    => ['pill-state--warn', 'warn', t('dashboard.check.warn', ['date' => $it['date']])],
                   'ok'      => ['pill-state--published', 'ok', t('dashboard.check.ok')]][$st];
          $btn = $st === 'missing' ? t('dashboard.check.upload') : ($st === 'ok' ? '' : t('dashboard.check.update'));
        ?>
        <div class="dash__item" data-testid="dashboard-check-item" data-state="<?= e($st) ?>" data-key="<?= e($it['key']) ?>">
          <span class="dash__item-title"><?= e($it['title']) ?></span>
          <span class="pill-state dash__pill <?= e($pill[0]) ?>" data-testid="dashboard-check-status"><?= $svg($pill[1]) ?><?= e($pill[2]) ?></span>
          <?php if ($btn !== ''): ?>
          <a class="btn btn--sm" href="<?= e($it['url']) ?>" data-testid="dashboard-check-action"><?= e($btn) ?></a>
          <?php else: ?><span class="dash__item-gap"></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <a class="dash__link" href="<?= e(admin_url('/documents')) ?>" data-testid="dashboard-checklist-all"><?= e(t('dashboard.check.all', ['n' => $check['total']])) ?></a>
      <?php endif; ?>
    </section>

    <div class="dash__side">
      <section class="card" data-testid="dashboard-actions">
        <h2><?= e(t('dashboard.actions')) ?></h2>
        <div class="dash__actions">
          <?php foreach ($actions as $a): ?>
          <a class="dash__action" href="<?= e($a['url']) ?>" data-testid="dashboard-action-<?= e($a['key']) ?>"><?= $svg($a['icon'], 24, '1.8', '#1F5BD8') ?><?= e($a['label']) ?></a>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="card dash__box" data-testid="dashboard-kk">
        <div class="dash__title"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1F5BD8" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg><h2><?= e(t('dashboard.kk.title')) ?></h2></div>
        <?php if ($trans['total'] === 0): ?>
        <p class="dash__muted dash__small" data-testid="dashboard-kk-empty"><?= e(t('dashboard.kk.empty')) ?></p>
        <?php else: ?>
        <p class="dash__muted dash__small" data-testid="dashboard-kk-text"><?= t('dashboard.kk.text', ['done' => '<b>' . (int)$trans['translated'] . '</b>', 'total' => '<b>' . (int)$trans['total'] . '</b>']) ?></p>
        <div class="bar bar--sm" role="img" aria-label="<?= e(t('dashboard.kk.aria', ['p' => $trans['percent']])) ?>" data-testid="dashboard-kk-bar"><div class="bar__fill bar__fill--kk" style="width:<?= (int)$trans['percent'] ?>%"></div></div>
        <a class="dash__link dash__small" href="<?= e(admin_url('/news') . '?kk=missing') ?>" data-testid="dashboard-kk-more"><?= e(t('dashboard.kk.more')) ?></a>
        <?php endif; ?>
      </section>

      <section class="card dash__box" data-testid="dashboard-disk">
        <h2><?= e(t('dashboard.disk.title')) ?></h2>
        <?php if ($disk['files'] === 0): ?>
        <p class="dash__muted dash__small" data-testid="dashboard-disk-empty"><?= e(t('dashboard.disk.empty')) ?></p>
        <?php else: ?>
        <p class="dash__muted dash__small" data-testid="dashboard-disk-text"><?php
          $used = '<b>' . e(format_bytes((int)$disk['used'])) . '</b>';
          echo $disk['total'] === null
            ? t('dashboard.disk.text_no_total', ['used' => $used])
            : t('dashboard.disk.text', ['used' => $used, 'total' => e(format_bytes((int)$disk['total']))]);
        ?></p>
        <?php if ($disk['total'] !== null): ?>
        <div class="bar bar--sm" role="img" aria-label="<?= e(t('dashboard.disk.aria', ['p' => $disk['percent']])) ?>" data-testid="dashboard-disk-bar"><div class="bar__fill" style="width:<?= (int)$disk['percent'] ?>%"></div></div>
        <?php endif; ?>
        <span class="dash__muted dash__tiny" data-testid="dashboard-disk-by"><?= e(t('dashboard.disk.by', [
          'image' => format_bytes($disk['by']['image']), 'video' => format_bytes($disk['by']['video']), 'file' => format_bytes($disk['by']['file']),
        ])) ?></span>
        <?php endif; ?>
      </section>
    </div>
  </div>

  <section class="card" data-testid="dashboard-news">
    <div class="dash__row-head dash__row-head--center">
      <h2><?= e(t('dashboard.news.title')) ?></h2>
      <a class="dash__link dash__small" href="<?= e(admin_url('/news')) ?>" data-testid="dashboard-news-all"><?= e(t('dashboard.news.all')) ?></a>
    </div>
    <?php if (!$latest): ?>
    <div class="dash__empty" data-testid="dashboard-news-empty">
      <p class="dash__muted"><?= e(t('dashboard.news.empty')) ?></p>
      <a class="btn btn--sm" href="<?= e($newsUrl) ?>" data-testid="dashboard-news-create"><?= e(t('dashboard.news.create')) ?></a>
    </div>
    <?php else: ?>
    <div class="dash__list" data-testid="dashboard-news-list">
      <?php foreach ($latest as $n): ?>
      <div class="dash__item" data-testid="dashboard-news-item" data-state="<?= e($n['state']) ?>">
        <a class="dash__item-title dash__news-title" href="<?= e($n['edit_url']) ?>" data-testid="dashboard-news-title"><?= e($n['title']) ?></a>
        <span class="dash__muted dash__date" data-testid="dashboard-news-date"><?= $n['state'] === 'draft' ? '—' : e($n['date']) ?></span>
        <span class="pill-state <?= e($pillClass[$n['state']] ?? 'pill-state--draft') ?>" data-testid="dashboard-news-state"><?= e(t('dashboard.state.' . $n['state'])) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>
