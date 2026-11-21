// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, execSql, login, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { OWNER, EDITOR, userId, tokens, newSession } = require('../auth-helpers');

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
});
test.afterEach(() => expectNoPageErrors());

test('I-22: блокировка завершает открытую сессию и remember-токены; разблокированный входит прежним паролем', async ({ page, browser }) => {
  const s = await newSession(browser, EDITOR, { remember: true });
  await login(page, OWNER);
  await page.goto('/admin/users');
  page.on('dialog', (d) => d.accept());
  await page.locator(`[data-user-id="${userId('editor')}"]`).getByTestId('users-block').click();
  await expect(page.getByTestId('admin-flash')).toBeVisible();
  expect(tokens()).toBe(0);
  await s.page.goto('/admin');
  await expect(s.page.getByTestId('login-form')).toBeVisible();
  await s.ctx.clearCookies({ name: 'sadik_sid' });
  await s.page.goto('/admin');
  await expect(s.page.getByTestId('login-form')).toBeVisible();
  await page.locator(`[data-user-id="${userId('editor')}"]`).getByTestId('users-unblock').click();
  await expect(page.getByTestId('admin-flash')).toContainText('разблокирован');
  await login(s.page, EDITOR);
  await expect(s.page.getByTestId('dashboard-greeting')).toBeVisible();
  await s.ctx.close();
});

test('I-22: сброс пароля owner-ом завершает сессию и токены сотрудника', async ({ page, browser }) => {
  const s = await newSession(browser, EDITOR, { remember: true });
  await login(page, OWNER);
  await page.goto(`/admin/users/${userId('editor')}/password`);
  await page.getByTestId('user-password-new').fill('Fresh-editor-pass-1');
  await page.getByTestId('user-password-submit').click();
  await expect(page.getByTestId('admin-flash')).toBeVisible();
  expect(tokens()).toBe(0);
  await s.page.goto('/admin');
  await expect(s.page.getByTestId('login-form')).toBeVisible();
  await s.ctx.close();
});

test('I-22: смена своего пароля завершает другие сессии и токены, текущая остаётся', async ({ page, browser }) => {
  const other = await newSession(browser, EDITOR, { remember: true });
  await login(page, EDITOR, { remember: true });
  await page.goto('/admin/profile');
  await page.getByTestId('profile-current-password').fill(EDITOR.password);
  await page.getByTestId('profile-new-password').fill('New-editor-pass-1');
  await page.getByTestId('profile-new-password-confirm').fill('New-editor-pass-1');
  await page.getByTestId('profile-password-submit').click();
  await expect(page.getByTestId('admin-flash')).toHaveText('Пароль изменён');
  expect(tokens()).toBe(0);
  await other.ctx.clearCookies({ name: 'sadik_sid' });
  await other.page.goto('/admin');
  await expect(other.page.getByTestId('login-form')).toBeVisible();
  await page.goto('/admin');
  await expect(page.getByTestId('dashboard-greeting')).toBeVisible();
  await other.ctx.close();
});

test('I-22: понижение owner до editor даёт 403 в разделах owner со следующего запроса', async ({ page, browser }) => {
  execSql("UPDATE users SET role='owner' WHERE login='editor'");
  const s = await newSession(browser, EDITOR);
  await s.page.goto('/admin/users');
  await expect(s.page.getByTestId('users-add')).toBeVisible();
  await login(page, OWNER);
  await page.goto(`/admin/users/${userId('editor')}/edit`);
  await page.getByTestId('user-form-role').selectOption('editor');
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByTestId('admin-flash')).toHaveText('Изменения сохранены');
  const r = await s.page.goto('/admin/users');
  expect(r.status()).toBe(403);
  await s.page.goto('/admin');
  await expect(s.page.getByTestId('nav-users')).toHaveCount(0);
  await s.ctx.close();
});
