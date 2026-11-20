// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, dbQuery, installViaUI, trackPageErrors, expectNoPageErrors } = require('../helpers');

test.beforeEach(({ page }) => trackPageErrors(page));
test.afterEach(() => expectNoPageErrors());

test('I-19: название из установщика лежит в settings.site_name и показано на / и /admin', async ({ page }) => {
  resetApp();
  const name = 'Детский сад «Ромашка» № 7';
  await installViaUI(page, { siteName: name });
  await expect(page.getByTestId('install-done')).toBeVisible();
  expect(JSON.parse(dbQuery("SELECT value FROM settings WHERE key='site_name'")[0].value)).toBe(name);
  for (const p of ['/', '/admin']) {
    await page.goto(p);
    await expect(page.getByTestId('stub-site-name')).toHaveText(name);
  }
});
