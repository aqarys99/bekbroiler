// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, dbQuery, installViaUI, trackPageErrors, expectNoPageErrors } = require('../helpers');

test.beforeEach(({ page }) => trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ }));
test.afterEach(() => expectNoPageErrors());

// I-18: владелец из install.php входит тем же логином (без учёта регистра) и паролем, роль owner.
for (const login of ['admin', 'ADMIN', 'Admin']) {
  test(`I-18: владелец из установщика входит как «${login}»`, async ({ page }) => {
    resetApp();
    await installViaUI(page);
    await expect(page.getByTestId('install-done')).toBeVisible();
    expect(dbQuery('SELECT role FROM users')[0].role).toBe('owner');
    await page.goto('/admin/login');
    await page.getByTestId('login-login').fill(login);
    await page.getByTestId('login-password').fill('Secret-pass-2026');
    await page.getByTestId('login-submit').click();
    await expect(page).toHaveURL(/\/admin$/);
    await expect(page.getByTestId('nav-users')).toBeVisible();
    await expect(page.getByTestId('user-card')).toContainText('Ответственный за сайт');
  });
}
