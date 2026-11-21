// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, dbQuery, login, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { EDITOR, csrfOf, post } = require('../auth-helpers');

// Фичи 10 (Настройки) и 12 (Резервные копии) добавляют свои URL сюда.
const OWNER_ONLY_URLS = ['/admin/users', '/admin/users/new', '/admin/users/1/edit', '/admin/users/1/password'];

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
});
test.afterEach(() => expectNoPageErrors());

test('I-14: editor не видит разделы owner в меню и получает 403 по прямому URL (GET)', async ({ page }) => {
  await login(page, EDITOR);
  await expect(page.getByTestId('nav-users')).toHaveCount(0);
  for (const u of OWNER_ONLY_URLS) {
    const r = await page.goto(u);
    expect(r.status(), u).toBe(403);
    await expect(page.getByTestId('error-403')).toBeVisible();
  }
});

test('I-14: editor получает 403 на POST к разделам owner, БД не меняется', async ({ page }) => {
  await login(page, EDITOR);
  const csrf = await csrfOf(page, '/admin/profile');
  const before = JSON.stringify(dbQuery('SELECT id, name, login, role, is_active, password_hash FROM users ORDER BY id'));
  for (const u of ['/admin/users', '/admin/users/1', '/admin/users/1/block', '/admin/users/1/unblock', '/admin/users/1/password']) {
    const r = await post(page, u, { _csrf: csrf, name: 'X', login: 'xxx', role: 'owner', password: 'Hacked-pass-123' });
    expect(r.status(), u).toBe(403);
  }
  expect(JSON.stringify(dbQuery('SELECT id, name, login, role, is_active, password_hash FROM users ORDER BY id'))).toBe(before);
});
