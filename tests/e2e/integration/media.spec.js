// @ts-check
// Связи фичи 03: I-01, I-02, I-07, I-16, I-23, I-24, I-25, I-26, I-27.
// Потребители (новости 04, альбомы 06, документы/педагоги 09) подменяются тестовым провайдером POST /__test/media-usage.
const fs = require('fs');
const { test, expect, request: pwRequest } = require('@playwright/test');
const { resetApp, login, ageSession, execSql, dbQuery, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { OWNER, EDITOR, count } = require('../auth-helpers');
const M = require('../media-helpers');
const { fx, upload, setUsages, setUsagesFailing, mediaCount, mediaRow, filesOnDisk, variantFiles, formatBytes, usedBytes } = M;

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
});
test.afterEach(() => expectNoPageErrors());

const dialogAccept = (page) => page.once('dialog', (d) => d.accept());

test('I-01: реестр готов — «используется в: новость X» в карточке и значок в сетке (провайдер news через тестовый)', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page);
  await setUsages(page, { [m.id]: [{ label: 'Новость «Осенний утренник»', url: '/admin' }] });
  await page.goto('/admin/media/' + m.id);
  await expect(page.getByTestId('media-usage-item')).toHaveText('Новость «Осенний утренник»');
  await page.goto('/admin/media');
  await expect(page.getByTestId('media-item-used')).toHaveCount(1);
});

test('I-02: используемый файл не удалить ни кнопкой, ни прямым POST; после отвязки удаление проходит, 6 файлов удалены', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page);
  await setUsages(page, { [m.id]: [{ label: 'Новость «Х»', url: '/admin' }, { label: 'Альбом «Y»', url: '/admin/profile' }] });
  await page.goto('/admin/media/' + m.id);
  await expect(page.getByTestId('media-delete')).toBeDisabled();
  await expect(page.getByTestId('media-delete-blocked')).toBeVisible();
  const token = await M.csrf(page, '/admin/media/' + m.id);
  const r = await page.request.post(`/admin/media/${m.id}/delete`, { form: { _csrf: token }, maxRedirects: 0 });
  expect(r.status()).toBe(302);
  expect(mediaCount()).toBe(1);
  expect(filesOnDisk()).toHaveLength(6);
  await page.goto('/admin/media/' + m.id);
  await expect(page.getByTestId('media-usage-item')).toHaveCount(2);
  await setUsages(page);
  await page.reload();
  await expect(page.getByTestId('media-delete')).toBeEnabled();
  dialogAccept(page);
  await page.getByTestId('media-delete').click();
  await expect(page).toHaveURL(/\/admin\/media$/);
  expect(mediaCount()).toBe(0);
  expect(filesOnDisk()).toEqual([]);
});

test('I-07: фото из альбома (тот же POST /media/upload) сразу в медиатеке; одно фото в двух местах — обе записи в media-usages', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page, fx('small.jpg'), { name: 'album-photo.jpg' });
  await page.goto('/admin/media');
  await expect(page.getByTestId('media-item').first()).toContainText('album-photo.jpg');
  await setUsages(page, { [m.id]: [{ label: 'Альбом «Лето»', url: '/admin' }, { label: 'Новость «Поход»', url: '/admin/profile' }] });
  await page.goto('/admin/media/' + m.id);
  await expect(page.getByTestId('media-usage-item')).toHaveText(['Альбом «Лето»', 'Новость «Поход»']);
});

test('I-16: загрузка и удаление меняют DiskUsage и media-disk-usage ровно на media.size', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/media');
  await expect(page.getByTestId('media-disk-usage')).toContainText('Занято 0 Б');
  const a = await upload(page, fx('small.jpg'));
  const sizeA = Number(mediaRow(a.id).size);
  const b = await upload(page, fx('orient-6.jpg'));
  const sizeB = Number(mediaRow(b.id).size);
  expect(usedBytes()).toBe(sizeA + sizeB);
  await page.goto('/admin/media');
  await expect(page.getByTestId('media-disk-usage')).toContainText(`Файлов: 2 · Занято ${formatBytes(sizeA + sizeB)}`);
  await page.goto('/admin/media/' + b.id);
  dialogAccept(page);
  await page.getByTestId('media-delete').click();
  await expect(page.getByTestId('media-disk-usage')).toContainText(`Файлов: 1 · Занято ${formatBytes(sizeA)}`);
  expect(usedBytes()).toBe(sizeA);
  // сумма на диске совпадает с media.size
  const onDisk = filesOnDisk().reduce((s, f) => s + fs.statSync(M.UPLOADS + '/' + f).size, 0);
  expect(onDisk).toBe(sizeA);
});

test('I-23: «Медиатека» в меню owner и editor; обход /admin/media, карточки и страницы 2 без 4xx/5xx и ошибок консоли', async ({ page, browser }) => {
  test.setTimeout(180000);
  await login(page, OWNER);
  const ids = await M.seed(page, 49);
  await setUsages(page, { [ids[0]]: [{ label: 'Новость «Х»', url: '/admin/media' }] });
  for (const [u, role] of [[OWNER, 'owner'], [EDITOR, 'editor']]) {
    const ctx = role === 'owner' ? page.context() : await browser.newContext();
    const p = role === 'owner' ? page : await ctx.newPage();
    if (role === 'editor') {
      trackPageErrors(p, { ignoreConsole: /status of 4\d\d/ });
      await login(p, u);
    }
    await expect(p.getByTestId('nav-media')).toBeVisible();
    const seen = new Set();
    const queue = ['/admin/media', '/admin/media?page=2', '/admin/media/' + ids[0], '/admin/media/' + ids[1]];
    while (queue.length) {
      const url = queue.shift();
      if (seen.has(url)) continue;
      seen.add(url);
      const res = await p.goto(url);
      expect(res.status(), `${role} ${url}`).toBeLessThan(400);
      const missing = await p.evaluate(() => Array.from(document.querySelectorAll('a, button, input:not([type=hidden]), select, textarea'))
        .filter((el) => !el.getAttribute('data-testid')).map((el) => el.outerHTML.slice(0, 100)));
      expect(missing, `нет data-testid на ${url}`).toEqual([]);
      expect(await p.locator('a[href="#"]').count()).toBe(0);
      const hrefs = await p.locator('a[href^="/admin/media"]').evaluateAll((els) => els.map((e) => e.getAttribute('href')));
      for (const h of hrefs) if (!seen.has(h) && seen.size < 10) queue.push(h);
    }
    if (role === 'editor') await ctx.close();
  }
});

test('I-24: XHR-загрузка после таймаута -> 401 JSON, с неверным CSRF -> 400 JSON, файл не сохранён; формы alt и удаления -> вход / 400', async ({ page, baseURL }) => {
  await login(page, OWNER);
  const m = await upload(page);
  const before = mediaCount();
  const bad = await M.apiUpload(page, fx('small2.jpg'), { token: '0'.repeat(64) });
  expect(bad.status()).toBe(400);
  expect((await bad.json())).toMatchObject({ ok: false, code: 'csrf' });
  const token = await M.csrf(page, '/admin/media/' + m.id);
  const f1 = await page.request.post('/admin/media/' + m.id, { form: { _csrf: '0'.repeat(64), alt: 'x' }, maxRedirects: 0 });
  expect(f1.status()).toBe(400);
  const f2 = await page.request.post(`/admin/media/${m.id}/delete`, { form: { _csrf: '0'.repeat(64) }, maxRedirects: 0 });
  expect(f2.status()).toBe(400);
  await ageSession(page, 7201);
  const xhr = await M.apiUpload(page, fx('small2.jpg'), { token });
  expect(xhr.status()).toBe(401);
  expect((await xhr.json()).code).toBe('session');
  for (const [u, form] of [[`/admin/media/${m.id}`, { _csrf: token, alt: 'после таймаута' }], [`/admin/media/${m.id}/delete`, { _csrf: token }]]) {
    const r = await page.request.post(u, { form, maxRedirects: 0 });
    expect(r.status(), u).toBe(302);
    expect(r.headers()['location']).toContain('/admin/login');
  }
  expect(mediaCount()).toBe(before);
  expect(mediaRow(m.id).alt).toBe('');
  expect(filesOnDisk()).toHaveLength(6);
  const anon = await pwRequest.newContext({ baseURL });
  expect((await anon.get('/admin/media/picker', { headers: { Accept: 'application/json' }, maxRedirects: 0 })).status()).toBe(401);
  await anon.dispose();
});

test('I-25: все места из всех провайдеров в карточке, значок в сетке; сбой провайдера блокирует удаление', async ({ page }) => {
  await login(page, OWNER);
  const a = await upload(page, fx('small.jpg'), { name: 'a.jpg' });
  const b = await upload(page, fx('small2.jpg'), { name: 'b.jpg' });
  await setUsages(page, { [a.id]: [{ label: 'Новость «1»', url: '/admin' }, { label: 'Альбом «2»', url: '/admin' }, { label: 'Документ «3»', url: '/admin' }] });
  await page.goto('/admin/media/' + a.id);
  await expect(page.getByTestId('media-usage-item')).toHaveCount(3);
  await page.goto('/admin/media/' + b.id);
  await expect(page.getByTestId('media-usages')).toContainText('Файл нигде не используется');
  await page.goto('/admin/media');
  await expect(page.getByTestId('media-item-used')).toHaveCount(1);
  await setUsagesFailing(page);
  const r = await page.goto('/admin/media');
  expect(r.status()).toBe(200);
  await page.goto('/admin/media/' + b.id);
  await expect(page.getByTestId('media-usages')).toContainText('Не удалось проверить использование');
  const token = await M.csrf(page, '/admin/media/' + b.id);
  await page.request.post(`/admin/media/${b.id}/delete`, { form: { _csrf: token }, maxRedirects: 0 });
  expect(mediaCount()).toBe(2);
});

test('I-26: загрузка из окна выбора — файл сразу в медиатеке и выбран в поле; сохранённое поле хранит id; удалённый файл — «Файл удалён»', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/__test/media-picker');
  const field = page.getByTestId('media-picker-field').first();
  await field.getByTestId('media-picker-open').click();
  await page.getByTestId('media-picker-tab-upload').click();
  await page.getByTestId('media-file-input').setInputFiles(fx('small.jpg'));
  await expect(page.getByTestId('media-queue-clear')).toBeEnabled({ timeout: 30000 });
  await expect(page.getByTestId('media-picker-confirm')).toBeEnabled();
  await page.getByTestId('media-picker-confirm').click();
  await expect(field.getByTestId('media-picker-preview').locator('img')).toHaveCount(1);
  await page.getByTestId('test-picker-submit').click();
  const id = mediaRow(1).id;
  expect(JSON.parse(await page.getByTestId('test-picker-result').innerText()).cover_media_id).toEqual([Number(id)]);
  await page.goto('/admin/media');
  await expect(page.getByTestId('media-item')).toHaveCount(1);
  // файл исчез — поле показывает «Файл удалён», а не ошибку
  execSql(`DELETE FROM media WHERE id=${id}`);
  const r = await page.goto(`/__test/media-picker?cover=${id}`);
  expect(r.status()).toBe(200);
  await expect(page.getByTestId('media-picker-field').first()).toContainText('Файл удалён');
});

test('I-27: фото выводятся через media_picture (picture + WebP-srcset по ширинам вариантов + JPEG, lazy, alt); смена alt видна везде; в файлах нет EXIF/GPS', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page, fx('photo-exif-gps.jpg'));
  await page.goto('/admin/media/' + m.id);
  await page.getByTestId('media-alt').fill('Дети в парке "Радуга"');
  await page.getByTestId('media-save').click();
  const html = await (await page.request.get('/admin/media/' + m.id)).text();
  const pic = /<picture[\s\S]*?<\/picture>/.exec(html)[0];
  expect(pic).toMatch(/<source[^>]+type="image\/webp"[^>]+srcset="[^"]*-thumb\.webp 300w[^"]*-medium\.webp 900w[^"]*-large\.webp 1440w/);
  expect(pic).toMatch(/<img[^>]+src="[^"]+\.jpg"/);
  expect(pic).toContain('loading="lazy"');
  expect(pic).toContain('alt="Дети в парке &quot;Радуга&quot;"');
  await page.goto('/admin/media');
  await expect(page.getByTestId('media-item').getByRole('img', { name: 'Дети в парке "Радуга"' })).toHaveCount(1);
  const j = await (await page.request.get('/admin/media/picker', { headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' } })).json();
  expect(j.items[0].alt).toBe('Дети в парке "Радуга"');
  for (const rel of variantFiles(mediaRow(m.id))) {
    const r = await page.request.get('/uploads/' + rel);
    expect(r.status()).toBe(200);
    expect(M.hasMetadata(await r.body()), rel).toBe(false);
  }
  void count; void dbQuery;
});
