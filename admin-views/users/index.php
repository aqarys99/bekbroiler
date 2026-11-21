<?php /** @var array $users @var ?string $generalError */ ?>
<div class="page-head">
  <h1><?= e(t('users.title')) ?></h1>
  <a class="btn btn--md" href="<?= e(admin_url('/users/new')) ?>" data-testid="users-add"><?= e(t('users.add')) ?></a>
</div>

<?php if ($generalError): ?>
<div class="alert alert--danger" role="alert"><?= e($generalError) ?></div>
<?php endif; ?>

<div class="table-card">
  <table class="table">
    <thead>
      <tr>
        <th scope="col"><?= e(t('users.col.name')) ?></th>
        <th scope="col"><?= e(t('users.col.login')) ?></th>
        <th scope="col"><?= e(t('users.col.role')) ?></th>
        <th scope="col"><?= e(t('users.col.status')) ?></th>
        <th scope="col"><?= e(t('users.col.last_login')) ?></th>
        <th scope="col"><?= e(t('users.col.actions')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): $id = (int)$u['id']; $active = (int)$u['is_active'] === 1; ?>
      <tr data-testid="users-row" data-user-id="<?= $id ?>">
        <td data-label="<?= e(t('users.col.name')) ?>"><span><b><?= e($u['name']) ?></b><?php if ($u['is_self']): ?><span class="badge badge--self" data-testid="users-self-badge"><?= e(t('users.self')) ?></span><?php endif; ?></span></td>
        <td data-label="<?= e(t('users.col.login')) ?>"><?= e($u['login']) ?></td>
        <td data-label="<?= e(t('users.col.role')) ?>"><span class="badge badge--<?= $u['role'] === 'owner' ? 'owner' : 'editor' ?>"><?= e(t('role.' . ($u['role'] === 'owner' ? 'owner' : 'editor'))) ?></span></td>
        <td data-label="<?= e(t('users.col.status')) ?>"><span class="badge badge--<?= $active ? 'ok' : 'danger' ?>"><?= e(t($active ? 'users.status.active' : 'users.status.blocked')) ?></span></td>
        <td data-label="<?= e(t('users.col.last_login')) ?>"><?= $u['last_login_fmt'] !== null ? e($u['last_login_fmt']) : '<span class="muted">' . e(t('users.never')) . '</span>' ?></td>
        <td class="cell-actions">
          <div class="row-actions">
            <a class="btn btn--sm" href="<?= e(admin_url('/users/' . $id . '/edit')) ?>" data-testid="users-edit"><?= e(t('users.edit')) ?></a>
            <?php if (!$u['is_self']): ?>
            <a class="btn btn--sm" href="<?= e(admin_url('/users/' . $id . '/password')) ?>" data-testid="users-reset-password"><?= e(t('users.reset_password')) ?></a>
            <?php if ($active): ?>
            <form method="post" action="<?= e(admin_url('/users/' . $id . '/block')) ?>" data-confirm="<?= e(t('users.block.confirm', ['name' => $u['name']])) ?>" data-once>
              <?= csrf_field() ?>
              <button type="submit" class="btn btn--sm is-danger" data-testid="users-block"><?= e(t('users.block')) ?></button>
            </form>
            <?php else: ?>
            <form method="post" action="<?= e(admin_url('/users/' . $id . '/unblock')) ?>" data-once>
              <?= csrf_field() ?>
              <button type="submit" class="btn btn--sm" data-testid="users-unblock"><?= e(t('users.unblock')) ?></button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
