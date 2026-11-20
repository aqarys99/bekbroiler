// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, readLog, trackPageErrors, expectNoPageErrors } = require('../helpers');

test.beforeEach(({ page }) => trackPageErrors(page, { allow500: true, ignoreConsole: /status of [45]\d\d/ }));
test.afterEach(() => expectNoPageErrors());

test('I-20: исключение -> 500 без деталей и запись в лог; 404 и 405 в общем стиле', async ({ page }) => {
  resetApp({ installed: true });
  const r500 = await page.goto('/__test/error');
  expect(r500.status()).toBe(500);
  await expect(page.getByTestId('error-500')).toBeVisible();
  const html = await page.content();
  expect(html).not.toContain('Test error');
  expect(html).not.toContain('RuntimeException');
  const log = readLog();
  expect(log).toContain('RuntimeException: Test error');
  expect(log).toContain('/__test/error');

  const r404 = await page.goto('/net');
  expect(r404.status()).toBe(404);
  await expect(page.getByTestId('error-404')).toBeVisible();

  const r405 = await page.request.post('/');
  expect(r405.status()).toBe(405);
  expect(r405.headers()['allow']).toBe('GET, HEAD');
  expect(await r405.text()).toContain('error-405');
  expect(readLog()).not.toContain('/net');
});
