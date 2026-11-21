// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, login, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { OWNER, EDITOR } = require('../auth-helpers');

// Полный обход ссылок и кнопок — в tests/e2e/crawl.spec.js. Здесь — состав меню.
test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
});
test.afterEach(() => expectNoPageErrors());

test('I-23: в меню owner только существующие разделы, каждая ссылка открывается', async ({ page }) => {
  await login(page, OWNER);
  const hrefs = await page.locator('nav.nav a.nav__item').evaluateAll((els) => els.map((e) => e.getAttribute('href')));
  expect(hrefs.length).toBeGreaterThanOrEqual(2);
  for (const h of hrefs) {
    const r = await page.goto(h);
    expect(r.status(), h).toBe(200);
  }
  await expect(page.getByText(/Новости|Документы|Фотогалерея|Видео|Педагоги|Резервная копия/)).toHaveCount(0);
});

test('I-23: в меню editor нет разделов owner, остальные ссылки открываются', async ({ page }) => {
  await login(page, EDITOR);
  const hrefs = await page.locator('nav.nav a.nav__item').evaluateAll((els) => els.map((e) => e.getAttribute('href')));
  expect(hrefs).toContain('/admin');
  expect(hrefs).not.toContain('/admin/users');
  for (const h of hrefs) expect((await page.goto(h)).status(), h).toBe(200);
});
