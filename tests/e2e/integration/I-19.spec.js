// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, dbQuery, installViaUI, login, trackPageErrors, expectNoPageErrors } = require('../helpers');

test.beforeEach(({ page }) => trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ }));
test.afterEach(() => expectNoPageErrors());

test('I-19: название из установщика лежит в settings.site_name и показано на /, входе, в меню и title админки', async ({ page }) => {
  resetApp();
  const name = 'Детский сад «Ромашка» № 7';
  await installViaUI(page, { siteName: name });
  await expect(page.getByTestId('install-done')).toBeVisible();
  expect(JSON.parse(dbQuery("SELECT value FROM settings WHERE key='site_name'")[0].value)).toBe(name);
  await page.goto('/');
  await expect(page.getByTestId('site-name')).toHaveText(name);
  await page.goto('/admin/login');
  await expect(page.getByTestId('login-site-name')).toHaveText(name);
  await expect(page).toHaveTitle(`Вход в админку — ${name}`);
  await login(page, { login: 'admin', password: 'Secret-pass-2026' });
  await expect(page.getByTestId('nav-brand')).toContainText(name);
  await expect(page).toHaveTitle(new RegExp(`— ${name}$`));
});
