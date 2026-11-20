// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const {
  STORAGE, resetApp, dbQuery, installViaUI, validPost, csrfFromInstallPage, trackPageErrors, expectNoPageErrors,
} = require('../helpers');

test.beforeEach(({ page }) => trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ }));
test.afterEach(() => expectNoPageErrors());

test('I-21: до установки всё ведёт на install.php; после — 403, второй владелец и перезапись конфига невозможны', async ({ page }) => {
  resetApp();
  for (const p of ['/', '/admin', '/anything']) {
    const r = await page.request.get(p, { maxRedirects: 0 });
    expect(r.status()).toBe(302);
    expect(r.headers()['location']).toBe('/install.php');
  }
  await page.goto('/admin');
  await expect(page).toHaveURL(/\/install\.php$/);
  await installViaUI(page);
  await expect(page.getByTestId('install-done')).toBeVisible();

  const cfg = path.join(STORAGE, 'config.php');
  const mtime = fs.statSync(cfg).mtimeMs;
  const hash = dbQuery('SELECT password_hash AS h FROM users')[0].h;

  expect((await page.goto('/install.php')).status()).toBe(403);
  await expect(page.getByTestId('install-locked')).toBeVisible();
  const csrf = await csrfFromInstallPage(page).catch(() => '0'.repeat(64));
  const post = await page.request.post('/install.php', { form: validPost(csrf, { login: 'hacker' }) });
  expect(post.status()).toBe(403);

  expect(dbQuery('SELECT login FROM users')).toEqual([{ login: 'admin' }]);
  expect(dbQuery('SELECT password_hash AS h FROM users')[0].h).toBe(hash);
  expect(fs.statSync(cfg).mtimeMs).toBe(mtime);
});
