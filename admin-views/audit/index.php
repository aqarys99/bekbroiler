<?php /** @var array $rows @var int $total @var int $page @var int $pages @var array $users @var array $entities @var array $f */
$qs = static function (array $over) use ($f): string {
    $q = array_filter($over + ['user' => $f['user'] ?: '', 'entity' => $f['entity'], 'from' => $f['from'], 'to' => $f['to']], static fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return $q ? '?' . http_build_query($q) : '';
};
$base = admin_url('/audit');
?>
<div class="page-head">
  <h1><?= e(t('audit.title')) ?></h1>
</div>
<p class="muted"><?= e(t('audit.lead')) ?></p>

<form class="toolbar" method="get" action="<?= e($base) ?>" data-testid="audit-filter">
  <div class="field field--type">
    <label for="au-user"><?= e(t('audit.filter.user')) ?></label>
    <select class="input select" id="au-user" name="user" data-testid="audit-filter-user">
      <option value=""><?= e(t('audit.filter.all')) ?></option>
      <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === (int)$f['user'] ? ' selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field field--type">
    <label for="au-entity"><?= e(t('audit.filter.entity')) ?></label>
    <select class="input select" id="au-entity" name="entity" data-testid="audit-filter-entity">
      <option value=""><?= e(t('audit.filter.all')) ?></option>
      <?php foreach ($entities as $en): ?><option value="<?= e($en['key']) ?>"<?= $en['key'] === $f['entity'] ? ' selected' : '' ?>><?= e($en['label']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="field field--type">
    <label for="au-from"><?= e(t('audit.filter.from')) ?></label>
    <input class="input" type="date" id="au-from" name="from" value="<?= e($f['from']) ?>" data-testid="audit-filter-from">
  </div>
  <div class="field field--type">
    <label for="au-to"><?= e(t('audit.filter.to')) ?></label>
    <input class="input" type="date" id="au-to" name="to" value="<?= e($f['to']) ?>" data-testid="audit-filter-to">
  </div>
  <button type="submit" class="btn btn--md" data-testid="audit-filter-apply"><?= e(t('audit.filter.apply')) ?></button>
  <a class="btn btn--md btn--ghost" href="<?= e($base) ?>" data-testid="audit-filter-reset"><?= e(t('audit.filter.reset')) ?></a>
</form>

<div class="table-card" style="margin-top:16px">
  <?php if (!$rows): ?>
  <p style="padding:20px;margin:0" data-testid="audit-empty"><?= e(t('audit.empty')) ?></p>
  <?php else: ?>
  <table class="table" data-testid="audit-list">
    <thead><tr>
      <th scope="col"><?= e(t('audit.col.when')) ?></th>
      <th scope="col"><?= e(t('audit.col.who')) ?></th>
      <th scope="col"><?= e(t('audit.col.action')) ?></th>
      <th scope="col"><?= e(t('audit.col.entity')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-testid="audit-row">
        <td data-label="<?= e(t('audit.col.when')) ?>"><?= e($r['when']) ?></td>
        <td data-label="<?= e(t('audit.col.who')) ?>"><?= e($r['who']) ?></td>
        <td data-label="<?= e(t('audit.col.action')) ?>"><?= e($r['label']) ?><?= $r['entity_id'] !== null ? ' <span class="muted">#' . (int)$r['entity_id'] . '</span>' : '' ?></td>
        <td data-label="<?= e(t('audit.col.entity')) ?>"><?= e(\App\Controllers\Admin\AuditController::entityLabel((string)$r['entity'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($total > 0): ?>
<div class="news-pager">
  <span data-testid="audit-summary"><?= e(t('audit.summary', ['total' => $total, 'page' => $page, 'pages' => $pages])) ?></span>
  <?php if ($pages > 1): ?>
  <nav class="news-pager__nav" aria-label="<?= e(t('audit.pager.label')) ?>">
    <?php if ($page > 1): ?><a class="pager-link" href="<?= e($base . $qs(['page' => $page - 1])) ?>" rel="prev" data-testid="audit-page-prev"><?= e(t('news.pager.prev')) ?></a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="pager-link" href="<?= e($base . $qs(['page' => $page + 1])) ?>" rel="next" data-testid="audit-page-next"><?= e(t('news.pager.next')) ?></a><?php endif; ?>
  </nav>
  <?php endif; ?>
</div>
<?php endif; ?>
