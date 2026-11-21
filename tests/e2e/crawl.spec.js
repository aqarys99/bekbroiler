// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, login, trackPageErrors, expectNoPageErrors } = require('./helpers');
const { OWNER, EDITOR, createUserUI, OWNER_ONLY_URLS } = require('./auth-helpers');

test.afterEach(() => expectNoPageErrors());

/** Обойти все внутренние ссылки админки (BFS), кроме выхода. */
async function crawl(page, { expect403 = [] } = {}) {
  const queue = ['/admin'];
  for (const u of expect403) queue.push(u);
  const seen = new Set();
  const visited = [];
  while (queue.length) {
    const url = queue.shift();
    if (seen.has(url)) continue;
    seen.add(url);
    const res = await page.goto(url);
    const status = res.status();
    if (expect403.includes(url)) {
      expect(status, url).toBe(403);
      await expect(page.getByTestId('error-403')).toBeVisible();
      await expect(page.getByTestId('error-403-home')).toBeVisible();
      continue;
    }
    expect(status, `страница ${url}`).toBeLessThan(400);
    visited.push(url);
    // все интерактивные элементы имеют data-testid
    const missing = await page.evaluate(() => Array.from(document.querySelectorAll('a, button, input:not([type=hidden]), select, textarea'))
      .filter((el) => !el.getAttribute('data-testid')).map((el) => el.outerHTML.slice(0, 120)));
    expect(missing, `нет data-testid на ${url}`).toEqual([]);
    // нет href="#"
    expect(await page.locator('a[href="#"]').count(), `href="#" на ${url}`).toBe(0);
    // ссылки того же origin
    const hrefs = await page.locator('a[href]').evaluateAll((els) => els.map((e) => ({ href: /** @type {HTMLAnchorElement} */ (e).href, target: e.getAttribute('target') })));
    for (const h of hrefs) {
      const u = new URL(h.href);
      if (u.origin !== new URL(page.url()).origin) continue;
      const p = u.pathname + u.search;
      if (u.pathname === '/admin/logout') continue;
      if (!u.pathname.startsWith('/admin')) {
        const r = await page.request.get(p);
        expect(r.status(), `внешняя для админки ссылка ${p} на ${url}`).toBeLessThan(400);
        continue;
      }
      if (!seen.has(p)) queue.push(p);
    }
    // нажать все не-разрушающие кнопки
    const btns = page.locator('button[type=button]');
    const n = await btns.count();
    for (let i = 0; i < n; i++) {
      await btns.nth(i).click();
      expect(new URL(page.url()).pathname + new URL(page.url()).search, `кнопка #${i} на ${url} увела со страницы`).toBe(url);
    }
  }
  return visited;
}

test('crawl: owner обходит все ссылки и кнопки админки', async ({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
  await login(page, OWNER);
  await createUserUI(page, { name: 'Третий Сотрудник', login: 'third' });
  await createUserUI(page, { name: 'Четвёртый Сотрудник', login: 'fourth' });
  const visited = await crawl(page);
  for (const p of ['/admin', '/admin/users', '/admin/users/new', '/admin/profile']) expect(visited).toContain(p);
  expect(visited.filter((u) => /\/edit$/.test(u))).toHaveLength(4);
  expect(visited.filter((u) => /\/password$/.test(u))).toHaveLength(3);
});

test('crawl: editor обходит админку; owner-разделы -> 403 с error-403 и error-403-home', async ({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
  await login(page, EDITOR);
  const visited = await crawl(page, { expect403: OWNER_ONLY_URLS });
  for (const p of ['/admin', '/admin/profile']) expect(visited).toContain(p);
  expect(visited.some((u) => u.startsWith('/admin/users'))).toBe(false);
});
