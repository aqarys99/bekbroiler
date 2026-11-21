// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, dbQuery, login, logout, execSql, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { OWNER, EDITOR, userId, newSession, createUserUI } = require('../auth-helpers');

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
});
test.afterEach(() => expectNoPageErrors());

const rows = () => dbQuery('SELECT action, user_id, entity, entity_id FROM audit_log ORDER BY id')
  .map((r) => [r.action, r.user_id === null ? null : Number(r.user_id), r.entity, r.entity_id === null ? null : Number(r.entity_id)]);

test('I-15: все действия с пользователями пишутся в audit_log с автором', async ({ page, browser }) => {
  await login(page, OWNER);
  const owner = userId('owner');
  await createUserUI(page, { name: 'Аудит Один', login: 'audit1' });
  const a1 = userId('audit1');
  await page.goto(`/admin/users/${a1}/edit`);
  await page.getByTestId('user-form-name').fill('Аудит Два');
  await page.getByTestId('user-form-submit').click();
  await page.goto(`/admin/users/${a1}/edit`);
  await page.getByTestId('user-form-role').selectOption('owner');
  await page.getByTestId('user-form-submit').click();
  await page.goto('/admin/users');
  page.on('dialog', (d) => d.accept());
  await page.locator(`[data-user-id="${a1}"]`).getByTestId('users-block').click();
  await expect(page.getByTestId('admin-flash')).toBeVisible();
  await page.locator(`[data-user-id="${a1}"]`).getByTestId('users-unblock').click();
  await expect(page.getByTestId('admin-flash')).toContainText('разблокирован');
  await page.goto(`/admin/users/${a1}/password`);
  await page.getByTestId('user-password-new').fill('Reset-pass-2026');
  await page.getByTestId('user-password-submit').click();
  await expect(page.getByTestId('admin-flash')).toBeVisible();
  const have = rows();
  for (const exp of [['create', owner, 'user', a1], ['update', owner, 'user', a1], ['role_change', owner, 'user', a1],
    ['block', owner, 'user', a1], ['unblock', owner, 'user', a1], ['password_reset', owner, 'user', a1], ['login', owner, 'user', owner]]) {
    expect(have, exp.join()).toContainEqual(exp);
  }
  // смена своего пароля, вход по remember, выход, неудачный вход
  const s = await newSession(browser, EDITOR);
  const ed = userId('editor');
  await s.page.goto('/admin/profile');
  await s.page.getByTestId('profile-current-password').fill(EDITOR.password);
  await s.page.getByTestId('profile-new-password').fill('New-editor-pass-1');
  await s.page.getByTestId('profile-new-password-confirm').fill('New-editor-pass-1');
  await s.page.getByTestId('profile-password-submit').click();
  await expect(s.page.getByTestId('admin-flash')).toBeVisible();
  await logout(s.page);
  await s.ctx.close();
  const r = rows();
  expect(r).toContainEqual(['password_change', ed, 'user', ed]);
  expect(r).toContainEqual(['logout', ed, 'user', ed]);
  await logout(page);
  await page.getByTestId('login-login').fill('owner');
  await page.getByTestId('login-password').fill('Wrong-pass-123');
  await page.getByTestId('login-submit').click();
  expect(rows()).toContainEqual(['login_failed', null, 'user', owner]);
});

test('I-15: ошибки валидации и сохранение без изменений не пишут в журнал', async ({ page }) => {
  await login(page, OWNER);
  const n = rows().length;
  await page.goto('/admin/users/new');
  await page.getByTestId('user-form-name').fill('Ошибка');
  await page.getByTestId('user-form-login').fill('x');
  await page.getByTestId('user-form-password').fill('Pass-word-2026');
  await page.getByTestId('user-form-submit').click();
  await expect(page.locator('.field__error').first()).toBeVisible();
  await page.goto(`/admin/users/${userId('editor')}/edit`);
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByTestId('admin-flash')).toBeVisible();
  expect(rows()).toHaveLength(n);
  void execSql;
});
