// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, login, trackPageErrors, expectNoPageErrors } = require('./helpers');
const { OWNER, EDITOR, createUserUI, OWNER_ONLY_URLS } = require('./auth-helpers');
const { upload, seed, setUsages } = require('./media-helpers');
const { createNews, createScheduled, setClock, bulkNews, NOW } = require('./news-helpers');

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
      if (!(await btns.nth(i).isVisible()) || !(await btns.nth(i).isEnabled())) continue;
      await btns.nth(i).click();
      expect(new URL(page.url()).pathname + new URL(page.url()).search, `кнопка #${i} на ${url} увела со страницы`).toBe(url);
      // окно выбора из медиатеки (кнопки «Выбрать фото», «Вставить фото») закрываем, чтобы не мешало следующим кнопкам
      if (await page.getByTestId('media-picker-modal').count()) {
        await page.keyboard.press('Escape');
        await expect(page.getByTestId('media-picker-modal')).toHaveCount(0);
      }
    }
  }
  return visited;
}

test('crawl: owner обходит все ссылки и кнопки админки', async ({ page }) => {
  test.setTimeout(180000);
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
  await login(page, OWNER);
  await createUserUI(page, { name: 'Третий Сотрудник', login: 'third' });
  await createUserUI(page, { name: 'Четвёртый Сотрудник', login: 'fourth' });
  // медиатека: 49 файлов (две страницы), один «используется»
  const ids = await seed(page, 49);
  await setUsages(page, { [ids[0]]: [{ label: 'Новость «Тест»', url: '/admin/media' }] });
  // новости: опубликованная с обложкой, черновик, запланированная, 25 штук для пагинации
  await setClock(page, NOW);
  await createNews(page, { title_ru: 'Опубликованная', cover_media_id: ids[1], photos: [ids[2]], title_kk: 'Жарияланған' });
  await createNews(page, { title_ru: 'Черновик', publish_mode: 'draft' });
  await createScheduled(page, '2026-11-30', '09:00', { title_ru: 'Запланированная' });
  bulkNews(25);
  const visited = await crawl(page);
  for (const p of ['/admin', '/admin/users', '/admin/users/new', '/admin/profile', '/admin/media', '/admin/media?page=2', '/admin/news', '/admin/news/new', '/admin/news?page=2', '/admin/news?status=draft', '/admin/news?status=scheduled', '/admin/news?status=published']) expect(visited).toContain(p);
  expect(visited.filter((u) => /^\/admin\/news\/\d+\/edit$/.test(u)).length).toBeGreaterThan(0);
  expect(visited.filter((u) => /^\/admin\/news\/\d+\/preview$/.test(u)).length).toBeGreaterThan(0);
  expect(visited.filter((u) => /^\/admin\/media\/\d+/.test(u)).length).toBeGreaterThan(0);
  expect(visited.filter((u) => /^\/admin\/users\/\d+\/edit$/.test(u))).toHaveLength(4);
  expect(visited.filter((u) => /\/password$/.test(u))).toHaveLength(3);
});

test('crawl: editor обходит админку; owner-разделы -> 403 с error-403 и error-403-home', async ({ page }) => {
  test.setTimeout(120000);
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
  await login(page, EDITOR);
  const m = await upload(page);
  await createNews(page, { title_ru: 'От редактора', cover_media_id: m.id });
  const visited = await crawl(page, { expect403: OWNER_ONLY_URLS });
  for (const p of ['/admin', '/admin/profile', '/admin/media', '/admin/media/' + m.id, '/admin/news', '/admin/news/new']) expect(visited.some((u) => u.startsWith(p))).toBe(true);
  expect(visited.some((u) => /^\/admin\/news\/\d+\/edit$/.test(u))).toBe(true);
  expect(visited.some((u) => u.startsWith('/admin/users'))).toBe(false);
});
