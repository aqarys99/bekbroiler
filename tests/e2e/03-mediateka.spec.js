// @ts-check
// Фича 03 «Медиатека»: по одному тесту на каждую строку таблицы «Элементы интерфейса» + конвейер/безопасность.
const fs = require('fs');
const { test, expect, request: pwRequest } = require('@playwright/test');
const { resetApp, login, ageSession, execSql, dbQuery, trackPageErrors, expectNoPageErrors } = require('./helpers');
const { OWNER, EDITOR, csrfOf } = require('./auth-helpers');
const M = require('./media-helpers');
const { fx, upload, seed, setUsages, setUsagesFailing, mediaCount, mediaRow, filesOnDisk, variantFiles, formatBytes, usedBytes, gotoMedia, uploadViaUI, waitQueueIdle } = M;

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d|net::ERR_FAILED/ });
});
test.afterEach(() => expectNoPageErrors());

const q = (s) => String(s).replace(/'/g, "''");
const audit = (a) => dbQuery(`SELECT entity_id FROM audit_log WHERE action='${a}'`);
const queueItems = (page) => page.getByTestId('media-queue-item');
const flash = (page) => page.getByTestId('admin-flash');

/** Задержать/посчитать запросы загрузки. */
async function delayUploads(page, ms) {
  await page.route('**/admin/media/upload', async (route) => {
    await new Promise((r) => setTimeout(r, ms));
    await route.continue();
  });
}

// ---------------------------------------------------------------- меню и гамбургер
test('nav-media: пункт виден owner и editor, открывает медиатеку, aria-current, крошки; активен и на карточке', async ({ page, browser }) => {
  await login(page, OWNER);
  await page.getByTestId('nav-media').click();
  await expect(page).toHaveURL(/\/admin\/media$/);
  await expect(page.getByTestId('nav-media')).toHaveAttribute('aria-current', 'page');
  await expect(page.getByRole('heading', { name: 'Медиатека', level: 1 })).toBeVisible();
  const m = await upload(page);
  await page.goto('/admin/media/' + m.id);
  await expect(page.getByTestId('nav-media')).toHaveAttribute('aria-current', 'page');
  const ctx = await browser.newContext();
  const p2 = await ctx.newPage();
  await login(p2, EDITOR);
  await p2.getByTestId('nav-media').click();
  await expect(p2).toHaveURL(/\/admin\/media$/);
  await ctx.close();
});

test.describe('мобильное меню (390x800)', () => {
  test.use({ viewport: { width: 390, height: 800 } });

  test('nav-toggle: мышь, Enter, Space, Esc, aria-expanded, фокус, Tab-ловушка', async ({ page }) => {
    await login(page, OWNER);
    await page.goto('/admin/media');
    const toggle = page.getByTestId('nav-toggle');
    const nav = page.locator('#admin-nav');
    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-controls', 'admin-nav');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(nav).toBeHidden();
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(nav).toBeVisible();
    await expect(page.getByTestId('nav-brand')).toBeFocused();
    for (let i = 0; i < 14; i++) {
      await page.keyboard.press('Tab');
      expect(await page.evaluate(() => !!document.activeElement && !!document.activeElement.closest('#admin-nav'))).toBe(true);
    }
    for (let i = 0; i < 14; i++) {
      await page.keyboard.press('Shift+Tab');
      expect(await page.evaluate(() => !!document.activeElement && !!document.activeElement.closest('#admin-nav'))).toBe(true);
    }
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(toggle).toBeFocused();
    await toggle.press('Enter');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await page.keyboard.press('Escape');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(toggle).toBeFocused();
    await expect(nav).toBeHidden();
    await toggle.focus();
    await toggle.press('Space');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    // переход по пункту закрывает меню
    await page.getByTestId('nav-media').click();
    await expect(page).toHaveURL(/\/admin\/media$/);
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(nav).toBeHidden();
  });

  test('nav-toggle: при расширении окна >= 900px открытое меню сбрасывается; без JS меню видно', async ({ page, browser }) => {
    await login(page, OWNER);
    await page.goto('/admin/media');
    await page.getByTestId('nav-toggle').click();
    await expect(page.locator('#admin-nav')).toBeVisible();
    await page.setViewportSize({ width: 1280, height: 800 });
    await expect(page.getByTestId('nav-toggle')).toBeHidden();
    await expect(page.locator('#admin-nav')).toBeVisible();
    await expect(page.getByTestId('nav-backdrop')).toBeHidden();
    await expect(page.getByTestId('nav-toggle')).toHaveAttribute('aria-expanded', 'false');
    const state = await page.context().storageState();
    const ctx = await browser.newContext({ javaScriptEnabled: false, storageState: state, viewport: { width: 390, height: 800 } });
    const p2 = await ctx.newPage();
    await p2.goto('/admin/media');
    await expect(p2.locator('#admin-nav')).toBeVisible();
    await ctx.close();
  });

  test('nav-backdrop: клик закрывает меню; когда меню закрыто — не виден; прокрутка под меню заблокирована', async ({ page }) => {
    await login(page, OWNER);
    await page.goto('/admin/media');
    const toggle = page.getByTestId('nav-toggle');
    await expect(page.getByTestId('nav-backdrop')).toBeHidden();
    await toggle.click();
    await expect(page.getByTestId('nav-backdrop')).toBeVisible();
    expect(await page.evaluate(() => getComputedStyle(document.body).overflow)).toBe('hidden');
    await page.getByTestId('nav-backdrop').click({ position: { x: 20, y: 300 } });
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByTestId('nav-backdrop')).toBeHidden();
    expect(await page.evaluate(() => getComputedStyle(document.body).overflow)).not.toBe('hidden');
  });
});

test('nav-toggle: на 1280px кнопка скрыта, меню видно', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 800 });
  await login(page, OWNER);
  await page.goto('/admin/media');
  await expect(page.getByTestId('nav-toggle')).toBeHidden();
  await expect(page.getByTestId('nav-media')).toBeVisible();
});

// ---------------------------------------------------------------- загрузка: зона, input, очередь
test('media-dropzone: клик открывает выбор файлов; перетаскивание подсвечивает и ставит в очередь; мимо зоны — preventDefault; 0 файлов — ничего', async ({ page }) => {
  await login(page, OWNER);
  await gotoMedia(page);
  await expect(page.getByTestId('media-dropzone')).toContainText('Перетащите фото сюда');
  await expect(page.getByTestId('media-dropzone')).toContainText('до 20');
  const [fc] = await Promise.all([page.waitForEvent('filechooser'), page.getByTestId('media-dropzone').click()]);
  expect(fc.isMultiple()).toBe(true);
  await fc.setFiles(fx('small.jpg'));
  await expect(queueItems(page)).toHaveCount(1);
  await waitQueueIdle(page);
  await expect(queueItems(page).first()).toContainText('Готово');

  const b64 = fs.readFileSync(fx('small2.jpg')).toString('base64');
  const dt = await page.evaluateHandle((b) => {
    const d = new DataTransfer();
    const bin = Uint8Array.from(atob(b), (c) => c.charCodeAt(0));
    d.items.add(new File([bin], 'dropped.jpg', { type: 'image/jpeg' }));
    return d;
  }, b64);
  await page.getByTestId('media-dropzone').dispatchEvent('dragenter', { dataTransfer: dt });
  await expect(page.getByTestId('media-dropzone')).toHaveClass(/is-drag/);
  await page.getByTestId('media-dropzone').dispatchEvent('drop', { dataTransfer: dt });
  await expect(page.getByTestId('media-dropzone')).not.toHaveClass(/is-drag/);
  await expect(queueItems(page)).toHaveCount(2);
  await waitQueueIdle(page);
  expect(mediaCount()).toBe(2);
  // 0 файлов
  const empty = await page.evaluateHandle(() => new DataTransfer());
  await page.getByTestId('media-dropzone').dispatchEvent('drop', { dataTransfer: empty });
  await expect(queueItems(page)).toHaveCount(2);
  // drop вне зоны: браузер не открывает файл
  const prevented = await page.evaluate((b) => {
    const d = new DataTransfer();
    d.items.add(new File([b], 'x.jpg', { type: 'image/jpeg' }));
    const ev = new DragEvent('drop', { dataTransfer: d, cancelable: true, bubbles: true });
    document.body.dispatchEvent(ev);
    return ev.defaultPrevented;
  }, 'x');
  expect(prevented).toBe(true);
});

test('media-file-input: скрытый, multiple, accept; 3 файла встают в очередь; повторный выбор тех же файлов добавляет ещё; input очищается; 50 файлов — по одному', async ({ page }) => {
  await login(page, OWNER);
  await gotoMedia(page);
  const input = page.getByTestId('media-file-input');
  await expect(input).toBeHidden();
  await expect(input).toHaveAttribute('multiple', '');
  expect(await input.getAttribute('accept')).toContain('image/jpeg');
  expect(await input.getAttribute('accept')).toContain('.heic');
  await input.setInputFiles([fx('small.jpg'), fx('small2.jpg'), fx('tiny.png')]);
  await waitQueueIdle(page);
  await expect(queueItems(page)).toHaveCount(3);
  expect(await input.inputValue()).toBe('');
  await input.setInputFiles([fx('small.jpg')]);
  await waitQueueIdle(page);
  await expect(queueItems(page)).toHaveCount(4);
  expect(mediaCount()).toBe(4);
});

test('media-file-input: 50 файлов в одной очереди идут строго по одному, все 50 в сетке', async ({ page }) => {
  test.setTimeout(240000);
  await login(page, OWNER);
  await gotoMedia(page);
  let active = 0;
  let maxActive = 0;
  let total = 0;
  const isUp = (r) => r.url().endsWith('/admin/media/upload');
  page.on('request', (r) => { if (isUp(r)) { active++; total++; maxActive = Math.max(maxActive, active); } });
  page.on('requestfinished', (r) => { if (isUp(r)) active--; });
  page.on('requestfailed', (r) => { if (isUp(r)) active--; });
  await page.getByTestId('media-file-input').setInputFiles(Array(50).fill(fx('tiny.png')));
  await expect(page.getByTestId('media-queue-summary')).toHaveText('Загрузка: 50 из 50 готово', { timeout: 200000 });
  expect(maxActive).toBe(1);
  expect(total).toBe(50);
  await expect(page.getByTestId('media-item')).toHaveCount(50);
  expect(mediaCount()).toBe(50);
});

test('media-queue: скрыт до выбора файла, виден после, перезагрузка очищает', async ({ page }) => {
  await login(page, OWNER);
  await gotoMedia(page);
  await expect(page.getByTestId('media-queue')).toBeHidden();
  await uploadViaUI(page, fx('small.jpg'));
  await expect(page.getByTestId('media-queue')).toBeVisible();
  await page.reload();
  await expect(page.getByTestId('media-queue')).toBeHidden();
});

test('media-queue-summary: «N из M готово», «Сэкономлено X»; ошибочные не входят; при 0 готовых «Сэкономлено» скрыто', async ({ page }) => {
  await login(page, OWNER);
  await gotoMedia(page);
  await uploadViaUI(page, fx('doc.pdf'));
  await expect(page.getByTestId('media-queue-summary')).toHaveText('Загрузка: 0 из 1 готово');
  await expect(page.getByTestId('media-queue')).not.toContainText('Сэкономлено');
  await uploadViaUI(page, [fx('photo-exif-gps.jpg'), fx('small.jpg')]);
  await expect(page.getByTestId('media-queue-summary')).toHaveText('Загрузка: 2 из 3 готово');
  await expect(page.getByTestId('media-queue')).toContainText(/Сэкономлено \d+(,\d)? (МБ|КБ)/);
});

test('media-queue-item: статусы «В очереди» → «Загружается» → «Готово», «9,5 МБ → N КБ», новый файл первым в сетке, диск обновляется', async ({ page }) => {
  test.setTimeout(120000);
  await login(page, OWNER);
  await gotoMedia(page);
  await expect(page.getByTestId('media-empty')).toBeVisible();
  await delayUploads(page, 800);
  await page.getByTestId('media-file-input').setInputFiles([fx('photo-exif-gps.jpg'), fx('small.jpg')]);
  await expect(queueItems(page).nth(1)).toContainText('В очереди');
  await expect(queueItems(page).first()).toContainText(/Загружается \d+%/);
  await waitQueueIdle(page);
  await expect(queueItems(page).first()).toContainText('Готово');
  await expect(queueItems(page).first()).toContainText(/\d,\d МБ → \d+ КБ/);
  await expect(page.getByTestId('media-grid')).toBeVisible();
  await expect(page.getByTestId('media-item').first()).toContainText('small.jpg');
  await expect(page.getByTestId('media-disk-usage')).toContainText('Файлов: 2');
  await expect(page.getByTestId('media-disk-usage')).toContainText(formatBytes(usedBytes()));
});

const ERR_CASES = [
  ['not_image (pdf, не отправляется)', 'doc.pdf', 'Это не фото', false],
  ['not_image (svg)', 'image.svg', 'Это не фото', false],
  ['too_large (>20 МБ, не отправляется)', 'big.gif', 'Файл больше', false],
  ['broken (повреждён, не декодируется браузером)', 'broken-header.jpg', 'повреждён', true],
  ['too_many_pixels (бомба)', 'bomb.png', 'Слишком большое разрешение', true],
];
for (const [title, file, text, sent] of ERR_CASES) {
  test(`media-queue-item: ошибка ${title}: текст, нет строки в БД, нет кнопки «Повторить»${sent ? '' : ', запрос не уходит'}`, async ({ page }) => {
    await login(page, OWNER);
    await gotoMedia(page);
    let requests = 0;
    await page.route('**/admin/media/upload', (route) => { requests++; return route.continue(); });
    await uploadViaUI(page, fx(file));
    await expect(queueItems(page).first()).toContainText('Ошибка');
    await expect(queueItems(page).first()).toContainText(text);
    await expect(page.getByTestId('media-queue-retry')).toBeHidden();
    if (!sent) expect(requests).toBe(0);
    expect(mediaCount()).toBe(0);
    expect(filesOnDisk()).toEqual([]);
  });
}

test('media-queue-item: ошибка одного файла не останавливает остальные; HEIC без поддержки — ошибка, без кнопки «Повторить»', async ({ page }) => {
  await login(page, OWNER);
  await gotoMedia(page);
  await uploadViaUI(page, [fx('doc.pdf'), fx('sample.heic'), fx('small.jpg')]);
  await expect(queueItems(page).nth(0)).toContainText('Ошибка');
  await expect(queueItems(page).nth(1)).toContainText('Ошибка');
  await expect(queueItems(page).nth(2)).toContainText('Готово');
  await expect(queueItems(page).nth(0).getByTestId('media-queue-retry')).toBeHidden();
  await expect(queueItems(page).nth(1).getByTestId('media-queue-retry')).toBeHidden();
  expect(mediaCount()).toBe(1);
});

test('media-queue-item: 401 — очередь останавливается, текст про сессию со ссылкой на вход', async ({ page }) => {
  await login(page, OWNER);
  await gotoMedia(page);
  await ageSession(page, 7201);
  await page.getByTestId('media-file-input').setInputFiles([fx('small.jpg'), fx('small2.jpg')]);
  await expect(queueItems(page).nth(0)).toContainText('Сессия закончилась');
  await expect(queueItems(page).nth(1)).toContainText('Сессия закончилась');
  await expect(queueItems(page).first().getByRole('link', { name: /Сессия закончилась/ })).toHaveAttribute('href', /\/admin\/login/);
  expect(mediaCount()).toBe(0);
});

test('media-queue-item: 400 csrf — «Страница устарела»; сеть — «Нет связи»', async ({ page }) => {
  await login(page, OWNER);
  await gotoMedia(page);
  await page.route('**/admin/media/upload', (route) => route.continue({ headers: { ...route.request().headers(), 'x-csrf-token': 'bad' } }));
  await uploadViaUI(page, fx('small.jpg'));
  await expect(queueItems(page).first()).toContainText('Страница устарела');
  await page.unroute('**/admin/media/upload');
  await page.route('**/admin/media/upload', (route) => route.abort());
  await uploadViaUI(page, fx('small2.jpg'));
  await expect(queueItems(page).nth(1)).toContainText('Нет связи с сервером');
  expect(mediaCount()).toBe(0);
});

test('media-queue-retry: для network/csrf есть кнопка; «Повторить» ставит в конец очереди; двойной клик — один раз', async ({ page }) => {
  await login(page, OWNER);
  await gotoMedia(page);
  await page.route('**/admin/media/upload', (route) => route.abort());
  await uploadViaUI(page, fx('small.jpg'));
  await expect(queueItems(page).first()).toContainText('Нет связи');
  await expect(page.getByTestId('media-queue-retry')).toBeVisible();
  await page.unroute('**/admin/media/upload');
  await page.getByTestId('media-queue-retry').dblclick();
  await expect(queueItems(page).first()).toContainText('Готово');
  await waitQueueIdle(page);
  expect(mediaCount()).toBe(1);
  await expect(queueItems(page)).toHaveCount(1);
});

test('media-queue-clear: disabled пока идёт загрузка; очищает очередь, файлы остаются в сетке', async ({ page }) => {
  await login(page, OWNER);
  await gotoMedia(page);
  await delayUploads(page, 1000);
  await page.getByTestId('media-file-input').setInputFiles(fx('small.jpg'));
  await expect(page.getByTestId('media-queue-clear')).toBeDisabled();
  await waitQueueIdle(page);
  await page.getByTestId('media-queue-clear').click();
  await expect(page.getByTestId('media-queue')).toBeHidden();
  await expect(page.getByTestId('media-item')).toHaveCount(1);
});

// ---------------------------------------------------------------- поиск, фильтр, список
async function seedNamed(page) {
  await login(page, OWNER);
  const a = await upload(page, fx('small.jpg'), { name: 'Осенний-Утренник.jpg' });
  const b = await upload(page, fx('small2.jpg'), { name: 'winter.jpg' });
  const c = await upload(page, fx('small.jpg'), { name: '100%_off.jpg' });
  const d = await upload(page, fx('small2.jpg'), { name: '1007off.jpg' });
  execSql(`UPDATE media SET alt='Дети на ПРОГУЛКЕ' WHERE id=${b.id}`);
  return { a, b, c, d };
}
const names = async (page) => page.getByTestId('media-item').locator('.media-item__name').allTextContents();

test('media-search: имя и alt без учёта регистра (кириллица), значение остаётся, trim, >100 символов, % и _ буквально, пустой — все', async ({ page }) => {
  await seedNamed(page);
  await gotoMedia(page);
  const search = page.getByTestId('media-search');
  await search.fill('утренн');
  await search.press('Enter');
  await expect(page).toHaveURL(/q=/);
  await expect(page.getByTestId('media-item')).toHaveCount(1);
  await expect(page.getByTestId('media-search')).toHaveValue('утренн');
  await page.getByTestId('media-search').fill('прогулке');
  await page.getByTestId('media-search').press('Enter');
  await expect(page.getByTestId('media-item')).toHaveCount(1);
  await expect(page.getByTestId('media-item')).toContainText('winter.jpg');
  await page.goto('/admin/media?q=' + encodeURIComponent('  WINTER  '));
  await expect(page.getByTestId('media-item')).toHaveCount(1);
  await page.goto('/admin/media?q=' + encodeURIComponent('100%'));
  await expect(page.getByTestId('media-item')).toHaveCount(1);
  await page.goto('/admin/media?q=' + encodeURIComponent('%_o'));
  await expect(page.getByTestId('media-item')).toHaveCount(1);
  await page.goto('/admin/media?q=' + encodeURIComponent('0_o'));
  await expect(page.getByTestId('media-item')).toHaveCount(0);
  await page.goto('/admin/media?q=' + encodeURIComponent('x'.repeat(150)));
  expect((await page.getByTestId('media-search').inputValue()).length).toBeLessThanOrEqual(100);
  await page.goto('/admin/media?q=');
  await expect(page.getByTestId('media-item')).toHaveCount(4);
});

test('media-filter-type: автоотправка, URL ?type=image, q сохраняется; неизвестный type -> Все; Видео/Документы -> media-empty', async ({ page }) => {
  await seedNamed(page);
  await page.goto('/admin/media?q=winter');
  await page.getByTestId('media-filter-type').selectOption('image');
  await expect(page).toHaveURL(/type=image/);
  await expect(page).toHaveURL(/q=winter/);
  await expect(page.getByTestId('media-filter-type')).toHaveValue('image');
  await expect(page.getByTestId('media-search')).toHaveValue('winter');
  await page.goto('/admin/media?type=zzz');
  await expect(page.getByTestId('media-filter-type')).toHaveValue('all');
  await expect(page.getByTestId('media-item')).toHaveCount(4);
  // «Видео»/«Документы» появятся в фичах 07/05: в 03 недоступный type превращается в all
  for (const t of ['video', 'file']) {
    const r = await page.goto('/admin/media?type=' + t);
    expect(r.status()).toBe(200);
    await expect(page.getByTestId('media-filter-type')).toHaveValue('all');
    await expect(page.getByTestId('media-item')).toHaveCount(4);
  }
  await expect(page.getByTestId('media-filter-type').getByRole('option')).toHaveText(['Все', 'Фото']);
});

test('media-search-submit: кнопка «Найти» работает, в том числе без JS', async ({ page, browser }) => {
  await seedNamed(page);
  await page.goto('/admin/media');
  await page.getByTestId('media-search').fill('winter');
  await page.getByTestId('media-search-submit').click();
  await expect(page.getByTestId('media-item')).toHaveCount(1);
  const ctx = await browser.newContext({ javaScriptEnabled: false, storageState: await page.context().storageState() });
  const p2 = await ctx.newPage();
  await p2.goto('/admin/media');
  await p2.getByTestId('media-search').fill('winter');
  await p2.getByTestId('media-search-submit').click();
  await expect(p2).toHaveURL(/q=winter/);
  await expect(p2.getByTestId('media-item')).toHaveCount(1);
  await ctx.close();
});

test('media-search-reset: скрыт без фильтра, виден при q или type, ведёт на /admin/media без параметров', async ({ page }) => {
  await seedNamed(page);
  await page.goto('/admin/media');
  await expect(page.getByTestId('media-search-reset')).toBeHidden();
  await page.goto('/admin/media?type=image');
  await expect(page.getByTestId('media-search-reset')).toBeVisible();
  await page.goto('/admin/media?q=winter');
  await page.getByTestId('media-search-reset').click();
  await expect(page).toHaveURL(/\/admin\/media$/);
  await expect(page.getByTestId('media-item')).toHaveCount(4);
});

test('media-disk-usage: «Файлов: 0 · Занято 0 Б»; меняется после загрузки и удаления', async ({ page }) => {
  await login(page, OWNER);
  await gotoMedia(page);
  await expect(page.getByTestId('media-disk-usage')).toContainText('Файлов: 0 · Занято 0 Б');
  await uploadViaUI(page, fx('small.jpg'));
  const size = usedBytes();
  await expect(page.getByTestId('media-disk-usage')).toContainText(`Файлов: 1 · Занято ${formatBytes(size)}`);
  await page.reload();
  await expect(page.getByTestId('media-disk-usage')).toContainText(`Занято ${formatBytes(size)}`);
  const id = mediaRow(1).id;
  await page.goto('/admin/media/' + id);
  page.once('dialog', (d) => d.accept());
  await page.getByTestId('media-delete').click();
  await expect(page.getByTestId('media-disk-usage')).toContainText('Файлов: 0 · Занято 0 Б');
});

test('media-grid: новые сверху', async ({ page }) => {
  await login(page, OWNER);
  await upload(page, fx('small.jpg'), { name: 'first.jpg' });
  await upload(page, fx('small2.jpg'), { name: 'second.jpg' });
  await gotoMedia(page);
  expect(await names(page)).toEqual(['second.jpg', 'first.jpg']);
});

test('media-item: клик и Enter открывают карточку; длинное имя не ломает сетку; имя экранировано', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page, fx('small.jpg'), { name: '<img src=x onerror=alert(1)>.jpg' });
  const long = await upload(page, fx('small2.jpg'), { name: 'длинное-'.repeat(30) + '.jpg' });
  await gotoMedia(page);
  await expect(page.getByTestId('media-item').nth(1)).toContainText('<img src=x onerror=alert(1)>.jpg');
  const overflow = await page.getByTestId('media-grid').evaluate((el) => el.scrollWidth > el.clientWidth + 1);
  expect(overflow).toBe(false);
  await page.getByTestId('media-item').nth(1).click();
  await expect(page).toHaveURL(new RegExp(`/admin/media/${m.id}`));
  await gotoMedia(page);
  await page.getByTestId('media-item').first().focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(new RegExp(`/admin/media/${long.id}`));
});

test('media-item-used: значок только у используемых файлов', async ({ page }) => {
  await login(page, OWNER);
  const a = await upload(page, fx('small.jpg'), { name: 'used.jpg' });
  await upload(page, fx('small2.jpg'), { name: 'free.jpg' });
  await setUsages(page, { [a.id]: [{ label: 'Новость «Тест»', url: '/admin' }] });
  await gotoMedia(page);
  await expect(page.getByTestId('media-item-used')).toHaveCount(1);
  await expect(page.getByTestId('media-item').filter({ hasText: 'used.jpg' }).getByTestId('media-item-used')).toBeVisible();
  await expect(page.getByTestId('media-item').filter({ hasText: 'free.jpg' }).getByTestId('media-item-used')).toHaveCount(0);
});

test('media-empty: нет файлов — «Загрузите первое фото»; пустой поиск — «Ничего не найдено» и сброс', async ({ page }) => {
  await login(page, OWNER);
  await gotoMedia(page);
  await expect(page.getByTestId('media-empty')).toContainText('В медиатеке пока нет файлов. Загрузите первое фото.');
  await expect(page.getByTestId('media-grid')).toBeHidden();
  await upload(page);
  await page.goto('/admin/media?q=несуществующее');
  await expect(page.getByTestId('media-empty')).toContainText('Ничего не найдено');
  await expect(page.getByTestId('media-search-reset')).toBeVisible();
  await page.goto('/admin/media');
  await expect(page.getByTestId('media-empty')).toBeHidden();
});

test('media-page-prev: «← Назад» на странице 2, q и type сохраняются; нет на 1-й и при ≤ 48 файлах', async ({ page }) => {
  test.setTimeout(180000);
  await login(page, OWNER);
  await seed(page, 3);
  await gotoMedia(page);
  await expect(page.getByTestId('media-page-prev')).toHaveCount(0);
  await seed(page, 47, 'more');
  await page.goto('/admin/media?q=photo&type=image');
  await expect(page.getByTestId('media-page-prev')).toHaveCount(0);
  await page.goto('/admin/media?page=2&q=o&type=image');
  await expect(page.getByTestId('media-item')).toHaveCount(2);
  await page.getByTestId('media-page-prev').click();
  await expect(page).toHaveURL(/q=o/);
  await expect(page).toHaveURL(/type=image/);
  await expect(page.getByTestId('media-item')).toHaveCount(48);
});

test('media-page-next: «Дальше →» и «Страница K из P»; нет на последней; page=999 -> последняя, page=abc -> 1-я', async ({ page }) => {
  test.setTimeout(180000);
  await login(page, OWNER);
  await seed(page, 50);
  await gotoMedia(page);
  await expect(page.getByTestId('media-item')).toHaveCount(48);
  await expect(page.getByText('Страница 1 из 2')).toBeVisible();
  await page.getByTestId('media-page-next').click();
  await expect(page).toHaveURL(/page=2/);
  await expect(page.getByText('Страница 2 из 2')).toBeVisible();
  await expect(page.getByTestId('media-page-next')).toHaveCount(0);
  await page.goto('/admin/media?page=999');
  await expect(page.getByText('Страница 2 из 2')).toBeVisible();
  await page.goto('/admin/media?page=abc');
  await expect(page.getByText('Страница 1 из 2')).toBeVisible();
});

// ---------------------------------------------------------------- карточка файла
test('media-preview: picture с alt; файлы удалены с диска — «Файл не найден на диске», 200', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page);
  execSql(`UPDATE media SET alt='Описание для теста' WHERE id=${m.id}`);
  await page.goto('/admin/media/' + m.id);
  await expect(page.getByTestId('media-preview').locator('picture')).toHaveCount(1);
  await expect(page.getByTestId('media-preview').getByRole('img', { name: 'Описание для теста' })).toBeVisible();
  for (const f of variantFiles(mediaRow(m.id))) fs.unlinkSync(M.UPLOADS + '/' + f);
  const r = await page.goto('/admin/media/' + m.id);
  expect(r.status()).toBe(200);
  await expect(page.getByTestId('media-preview')).toContainText('Файл не найден на диске');
});

test('media-open: ссылка на large WebP, новая вкладка, rel=noopener, image/webp', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page);
  await page.goto('/admin/media/' + m.id);
  const link = page.getByTestId('media-open');
  await expect(link).toHaveAttribute('target', '_blank');
  await expect(link).toHaveAttribute('rel', /noopener/);
  const href = await link.getAttribute('href');
  expect(href).toMatch(/-large\.webp$/);
  const [popup] = await Promise.all([page.waitForEvent('popup'), link.click()]);
  await popup.waitForLoadState();
  expect(popup.url()).toContain('-large.webp');
  await popup.close();
  const r = await page.request.get(href);
  expect(r.headers()['content-type']).toContain('image/webp');
});

test('media-info: имя, тип, размеры, дата и автор, таблица вариантов 3x2, «Всего на диске»; удалённый автор -> «—»', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page, fx('orient-6.jpg'));
  await page.goto('/admin/media/' + m.id);
  const info = page.getByTestId('media-info');
  const row = mediaRow(m.id);
  await expect(info).toContainText('orient-6.jpg');
  await expect(info).toContainText(`${row.width} × ${row.height} px`);
  await expect(info).toContainText('Иванова Мария Петровна');
  await expect(info).toContainText(formatBytes(Number(row.size)));
  await expect(info.getByRole('row')).toHaveCount(4);
  await expect(info).toContainText('Миниатюра');
  await expect(info).toContainText('Большой');
  execSql(`UPDATE media SET uploaded_by=NULL WHERE id=${m.id}`);
  await page.reload();
  await expect(page.getByTestId('media-info')).toContainText(', —');
});

test('media-alt: maxlength 300, подсказка, HTML — как текст и экранирован, пробелы по краям обрезаются', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page);
  await page.goto('/admin/media/' + m.id);
  await expect(page.getByTestId('media-alt')).toHaveAttribute('maxlength', '300');
  await expect(page.getByLabel('Описание фото (alt)')).toBeVisible();
  await expect(page.getByText('Что на фото — для незрячих и поисковиков')).toBeVisible();
  await page.getByTestId('media-alt').fill('  <b>"Дети"</b> & <script>alert(1)</script>  ');
  await page.getByTestId('media-save').click();
  await expect(page.getByTestId('media-alt')).toHaveValue('<b>"Дети"</b> & <script>alert(1)</script>');
  expect(mediaRow(m.id).alt).toBe('<b>"Дети"</b> & <script>alert(1)</script>');
  const html = await (await page.request.get('/admin/media/' + m.id)).text();
  expect(html).not.toContain('<script>alert(1)</script>');
  await expect(page.getByTestId('media-preview').getByRole('img')).toHaveAttribute('alt', '<b>"Дети"</b> & <script>alert(1)</script>');
});

test('media-save: тост, журнал media.update, пустое допустимо, >300 символов — ошибка у поля, двойной клик — одно сохранение, удалён — 404', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page);
  await page.goto('/admin/media/' + m.id);
  await page.getByTestId('media-alt').fill('Новое описание');
  await page.getByTestId('media-save').dblclick();
  await expect(flash(page)).toContainText('Описание сохранено');
  await expect(page).toHaveURL(new RegExp(`/admin/media/${m.id}$`));
  expect(audit('media.update')).toHaveLength(1);
  await page.getByTestId('media-alt').fill('');
  await page.getByTestId('media-save').click();
  expect(mediaRow(m.id).alt).toBe('');
  const token = await M.csrf(page, '/admin/media/' + m.id);
  const long = await page.request.post('/admin/media/' + m.id, { form: { _csrf: token, alt: 'я'.repeat(301) } });
  expect(long.status()).toBe(422);
  expect(await long.text()).toContain('Не больше 300 символов');
  expect(mediaRow(m.id).alt).toBe('');
  // удалён в другой вкладке
  const d = await page.request.post(`/admin/media/${m.id}/delete`, { form: { _csrf: token }, maxRedirects: 0 });
  expect(d.status()).toBe(302);
  const gone = await page.request.post('/admin/media/' + m.id, { form: { _csrf: token, alt: 'x' } });
  expect(gone.status()).toBe(404);
});

test('media-usages: «нигде не используется»; список из провайдера; сбой провайдера — сообщение, удаление запрещено, не 500', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page);
  await page.goto('/admin/media/' + m.id);
  await expect(page.getByTestId('media-usages')).toContainText('Файл нигде не используется');
  await setUsages(page, { [m.id]: [{ label: 'Новость «А»', url: '/admin' }, { label: 'Альбом «Б»', url: '/admin/profile' }] });
  await page.reload();
  await expect(page.getByTestId('media-usage-item')).toHaveCount(2);
  await setUsagesFailing(page);
  const r = await page.goto('/admin/media/' + m.id);
  expect(r.status()).toBe(200);
  await expect(page.getByTestId('media-usages')).toContainText('Не удалось проверить использование');
  const token = await M.csrf(page, '/admin/media/' + m.id);
  const del = await page.request.post(`/admin/media/${m.id}/delete`, { form: { _csrf: token }, maxRedirects: 0 });
  expect(del.status()).toBe(302);
  expect(mediaCount()).toBe(1);
});

test('media-usage-item: ссылка ведёт по url провайдера, текст экранирован', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page);
  await setUsages(page, { [m.id]: [{ label: 'Новость «<i>X</i>»', url: '/admin/profile' }] });
  await page.goto('/admin/media/' + m.id);
  await expect(page.getByTestId('media-usage-item')).toHaveText('Новость «<i>X</i>»');
  await page.getByTestId('media-usage-item').click();
  await expect(page).toHaveURL(/\/admin\/profile$/);
});

test('media-delete: confirm с именем, удаляет строку и 6 файлов, тост, журнал; отмена — ничего; двойной клик — один запрос; уже удалён — 404', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page, fx('small.jpg'), { name: 'удалить-меня.jpg' });
  expect(filesOnDisk()).toHaveLength(6);
  await page.goto('/admin/media/' + m.id);
  let msg = '';
  page.once('dialog', (d) => { msg = d.message(); return d.dismiss(); });
  await page.getByTestId('media-delete').click();
  expect(msg).toBe('Удалить файл «удалить-меня.jpg»? Восстановить его будет нельзя.');
  expect(mediaCount()).toBe(1);
  expect(filesOnDisk()).toHaveLength(6);
  page.on('dialog', (d) => d.accept());
  await page.getByTestId('media-delete').dblclick();
  await expect(page).toHaveURL(/\/admin\/media$/);
  await expect(flash(page)).toContainText('Файл удалён');
  expect(mediaCount()).toBe(0);
  expect(filesOnDisk()).toEqual([]);
  expect(audit('media.delete')).toHaveLength(1);
  const token = await M.csrf(page);
  const again = await page.request.post(`/admin/media/${m.id}/delete`, { form: { _csrf: token }, maxRedirects: 0 });
  expect(again.status()).toBe(404);
});

test('media-delete: используемый файл — кнопка disabled; использование появилось после открытия — сервер отказывает, ничего не удалено', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page);
  await page.goto('/admin/media/' + m.id);
  await expect(page.getByTestId('media-delete')).toBeEnabled();
  await setUsages(page, { [m.id]: [{ label: 'Новость «Гонка»', url: '/admin' }] });
  page.once('dialog', (d) => d.accept());
  await page.getByTestId('media-delete').click();
  await expect(page).toHaveURL(new RegExp(`/admin/media/${m.id}`));
  await expect(flash(page)).toContainText('Файл используется: Новость «Гонка»');
  expect(mediaCount()).toBe(1);
  expect(filesOnDisk()).toHaveLength(6);
  await expect(page.getByTestId('media-delete')).toBeDisabled();
});

test('media-delete-blocked: пояснение видно только при использовании', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page);
  await page.goto('/admin/media/' + m.id);
  await expect(page.getByTestId('media-delete-blocked')).toHaveCount(0);
  await setUsages(page, { [m.id]: [{ label: 'Новость «X»', url: '/admin' }] });
  await page.reload();
  await expect(page.getByTestId('media-delete-blocked')).toContainText('Удалить нельзя: файл используется');
  await setUsages(page);
  await page.reload();
  await expect(page.getByTestId('media-delete-blocked')).toHaveCount(0);
});

test('media-back: возвращает в список с q/type/page; чужой back игнорируется', async ({ page }) => {
  await seedNamed(page);
  await page.goto('/admin/media?q=o&type=image');
  await page.getByTestId('media-item').first().click();
  await expect(page.getByTestId('media-back')).toHaveAttribute('href', /q=o/);
  await page.getByTestId('media-back').click();
  await expect(page).toHaveURL(/\/admin\/media\?.*q=o/);
  await expect(page).toHaveURL(/type=image/);
  const id = mediaRow(1).id;
  for (const evil of ['https://evil.example/x', '//evil.example', '/admin/users']) {
    await page.goto(`/admin/media/${id}?back=${encodeURIComponent(evil)}`);
    await expect(page.getByTestId('media-back')).toHaveAttribute('href', '/admin/media');
  }
});

// ---------------------------------------------------------------- компонент выбора
const PICK = '/__test/media-picker';
const cover = (page) => page.getByTestId('media-picker-field').first();
const photos = (page) => page.getByTestId('media-picker-field').nth(1);
const modal = (page) => page.getByTestId('media-picker-modal');
const result = async (page) => JSON.parse(await page.getByTestId('test-picker-result').innerText());

test('media-picker-field: два поля; несуществующий id -> «Файл удалён», при сохранении очищается', async ({ page }) => {
  await login(page, OWNER);
  const m = await upload(page);
  await page.goto(`${PICK}?cover=99999&photos[]=${m.id}&photos[]=88888`);
  await expect(page.getByTestId('media-picker-field')).toHaveCount(2);
  await expect(cover(page)).toContainText('Файл удалён');
  await page.getByTestId('test-picker-submit').click();
  expect(await result(page)).toEqual({ cover_media_id: [], photos: [m.id] });
});

test('media-picker-open: открывает окно на вкладке «Медиатека», фокус в поиске; двойной клик — одно окно; нет сети — ошибка и повтор', async ({ page }) => {
  await login(page, OWNER);
  await upload(page);
  await page.goto(PICK);
  await cover(page).getByTestId('media-picker-open').dblclick();
  await expect(modal(page)).toHaveCount(1);
  await expect(page.getByTestId('media-picker-tab-library')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('media-picker-search')).toBeFocused();
  await page.getByTestId('media-picker-cancel').click();
  await page.route('**/admin/media/picker*', (r) => r.abort());
  await cover(page).getByTestId('media-picker-open').click();
  await expect(modal(page)).toContainText('Не удалось загрузить список');
  await expect(page.getByTestId('media-picker-more')).toBeVisible();
  await page.unroute('**/admin/media/picker*');
  await page.getByTestId('media-picker-more').click();
  await expect(page.getByTestId('media-picker-item')).toHaveCount(1);
});

test('media-picker-preview: пусто — «Фото не выбрано»; выбранные — миниатюры с alt', async ({ page }) => {
  await login(page, OWNER);
  const a = await upload(page, fx('small.jpg'), { name: 'a.jpg' });
  const b = await upload(page, fx('small2.jpg'), { name: 'b.jpg' });
  execSql(`UPDATE media SET alt='Альт А' WHERE id=${a.id}`);
  await page.goto(PICK);
  await expect(cover(page).getByTestId('media-picker-preview')).toContainText('Фото не выбрано');
  await expect(photos(page).getByTestId('media-picker-preview')).toContainText('Фото не выбрано');
  await page.goto(`${PICK}?cover=${a.id}&photos[]=${a.id}&photos[]=${b.id}`);
  await expect(cover(page).getByTestId('media-picker-preview').getByRole('img', { name: 'Альт А' })).toBeVisible();
  await expect(photos(page).getByTestId('media-picker-preview').locator('img')).toHaveCount(2);
});

test('media-picker-clear: «Убрать» (одиночный) и «×» (множественный); нет, когда пусто', async ({ page }) => {
  await login(page, OWNER);
  const a = await upload(page, fx('small.jpg'), { name: 'a.jpg' });
  const b = await upload(page, fx('small2.jpg'), { name: 'b.jpg' });
  await page.goto(PICK);
  await expect(page.getByTestId('media-picker-clear')).toHaveCount(0);
  await page.goto(`${PICK}?cover=${a.id}&photos[]=${a.id}&photos[]=${b.id}`);
  await expect(cover(page).getByTestId('media-picker-clear')).toHaveText('Убрать');
  await cover(page).getByTestId('media-picker-clear').click();
  await expect(cover(page).getByTestId('media-picker-preview')).toContainText('Фото не выбрано');
  await expect(cover(page).getByTestId('media-picker-open')).toBeFocused();
  await photos(page).getByTestId('media-picker-clear').first().click();
  await expect(photos(page).getByTestId('media-picker-clear')).toHaveCount(1);
  await page.getByTestId('test-picker-submit').click();
  expect(await result(page)).toEqual({ cover_media_id: [], photos: [b.id] });
});

test('media-picker-modal: role=dialog aria-modal, заголовок, Tab-ловушка, фон не прокручивается, Esc закрывает без изменений', async ({ page }) => {
  await login(page, OWNER);
  const a = await upload(page);
  await page.goto(PICK);
  await cover(page).getByTestId('media-picker-open').click();
  await expect(page.getByRole('dialog', { name: 'Выбор фото' })).toHaveAttribute('aria-modal', 'true');
  expect(await page.evaluate(() => getComputedStyle(document.body).overflow)).toBe('hidden');
  for (let i = 0; i < 12; i++) {
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => !!document.activeElement && !!document.activeElement.closest('[role=dialog]'))).toBe(true);
  }
  await page.getByTestId('media-picker-item').first().click();
  await page.keyboard.press('Escape');
  await expect(modal(page)).toHaveCount(0);
  await expect(cover(page).getByTestId('media-picker-preview')).toContainText('Фото не выбрано');
  await expect(cover(page).getByTestId('media-picker-open')).toBeFocused();
  expect(await page.evaluate(() => getComputedStyle(document.body).overflow)).not.toBe('hidden');
  void a;
});

test('media-picker-tab-library: сетка, выбранные отмечены, стрелки переключают вкладки; пусто — «В медиатеке пока нет фото» + переход к загрузке', async ({ page }) => {
  await login(page, OWNER);
  await page.goto(PICK);
  await cover(page).getByTestId('media-picker-open').click();
  await expect(modal(page)).toContainText('В медиатеке пока нет фото');
  await page.getByRole('button', { name: 'Загрузить фото' }).click();
  await expect(page.getByTestId('media-picker-tab-upload')).toHaveAttribute('aria-selected', 'true');
  await page.getByTestId('media-picker-cancel').click();
  const a = await upload(page, fx('small.jpg'), { name: 'a.jpg' });
  await upload(page, fx('small2.jpg'), { name: 'b.jpg' });
  await page.goto(`${PICK}?cover=${a.id}`);
  await cover(page).getByTestId('media-picker-open').click();
  await expect(page.getByTestId('media-picker-item')).toHaveCount(2);
  await expect(page.getByTestId('media-picker-item').and(page.locator('[aria-pressed=true]'))).toHaveCount(1);
  await page.getByTestId('media-picker-tab-library').focus();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByTestId('media-picker-tab-upload')).toHaveAttribute('aria-selected', 'true');
  await page.keyboard.press('ArrowLeft');
  await expect(page.getByTestId('media-picker-tab-library')).toHaveAttribute('aria-selected', 'true');
});

test('media-picker-tab-upload: показывает зону загрузки и очередь', async ({ page }) => {
  await login(page, OWNER);
  await page.goto(PICK);
  await cover(page).getByTestId('media-picker-open').click();
  await page.getByTestId('media-picker-tab-upload').click();
  await expect(page.getByTestId('media-picker-tab-upload')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('media-picker-dropzone')).toBeVisible();
  await expect(page.getByTestId('media-queue')).toBeHidden();
});

test('media-picker-search: поиск с задержкой; ответы на старые запросы игнорируются', async ({ page }) => {
  await login(page, OWNER);
  await upload(page, fx('small.jpg'), { name: 'яблоко.jpg' });
  await upload(page, fx('small2.jpg'), { name: 'груша.jpg' });
  await page.goto(PICK);
  await cover(page).getByTestId('media-picker-open').click();
  await expect(page.getByTestId('media-picker-item')).toHaveCount(2);
  let slowDone = false;
  let slowStarted = false;
  await page.route('**/admin/media/picker*', async (route) => {
    if (route.request().url().includes('q=%D1%8F')) {
      slowStarted = true;
      const resp = await route.fetch();
      await new Promise((r) => setTimeout(r, 1500));
      slowDone = true;
      await route.fulfill({ response: resp });
    } else await route.continue();
  });
  const search = page.getByTestId('media-picker-search');
  await search.fill('я');
  await expect.poll(() => slowStarted, { timeout: 5000 }).toBe(true);
  await search.fill('груша');
  await expect(page.getByTestId('media-picker-item')).toHaveCount(1);
  await expect.poll(() => slowDone, { timeout: 10000 }).toBe(true);
  await expect(page.getByTestId('media-picker-item')).toHaveCount(1);
  await expect(page.getByTestId('media-picker-item')).toHaveAttribute('title', 'груша.jpg');
});

test('media-picker-item: одиночный — выбрано только одно; множественный — отметка/снятие, «Выбрано: N»; Enter/Space', async ({ page }) => {
  await login(page, OWNER);
  await upload(page, fx('small.jpg'), { name: 'a.jpg' });
  await upload(page, fx('small2.jpg'), { name: 'b.jpg' });
  await upload(page, fx('tiny.png'), { name: 'c.png' });
  await page.goto(PICK);
  await cover(page).getByTestId('media-picker-open').click();
  const items = page.getByTestId('media-picker-item');
  await items.nth(0).click();
  await items.nth(1).click();
  await expect(items.nth(0)).toHaveAttribute('aria-pressed', 'false');
  await expect(items.nth(1)).toHaveAttribute('aria-pressed', 'true');
  await page.getByTestId('media-picker-cancel').click();
  await photos(page).getByTestId('media-picker-open').click();
  await items.nth(0).click();
  await items.nth(1).press('Enter');
  await items.nth(2).press('Space');
  await expect(modal(page)).toContainText('Выбрано: 3');
  await expect(page.getByTestId('media-picker-confirm')).toHaveText('Выбрать (3)');
  await items.nth(0).click();
  await expect(items.nth(0)).toHaveAttribute('aria-pressed', 'false');
  await expect(page.getByTestId('media-picker-confirm')).toHaveText('Выбрать (2)');
});

test('media-picker-more: «Показать ещё» догружает следующую страницу; на последней нет', async ({ page }) => {
  test.setTimeout(180000);
  await login(page, OWNER);
  await seed(page, 50);
  await page.goto(PICK);
  await cover(page).getByTestId('media-picker-open').click();
  await expect(page.getByTestId('media-picker-item')).toHaveCount(48);
  await page.getByTestId('media-picker-more').dblclick();
  await expect(page.getByTestId('media-picker-item')).toHaveCount(50);
  await expect(page.getByTestId('media-picker-more')).toBeHidden();
});

test('media-picker-dropzone: загрузка из окна выбирает файл (одиночный — последний), файл в начале сетки и в медиатеке; ошибки как в очереди', async ({ page }) => {
  await login(page, OWNER);
  await page.goto(PICK);
  await cover(page).getByTestId('media-picker-open').click();
  await page.getByTestId('media-picker-tab-upload').click();
  await page.getByTestId('media-file-input').setInputFiles([fx('small.jpg'), fx('doc.pdf'), fx('small2.jpg')]);
  await waitQueueIdle(page);
  await expect(page.getByTestId('media-queue-item').nth(1)).toContainText('Ошибка');
  expect(mediaCount()).toBe(2);
  await page.getByTestId('media-picker-tab-library').click();
  const items = page.getByTestId('media-picker-item');
  await expect(items).toHaveCount(2);
  await expect(items.first()).toHaveAttribute('title', 'small2.jpg');
  await expect(items.first()).toHaveAttribute('aria-pressed', 'true');
  await expect(items.nth(1)).toHaveAttribute('aria-pressed', 'false');
  await gotoMedia(page);
  await expect(page.getByTestId('media-item')).toHaveCount(2);
});

test('media-picker-confirm: disabled пока пусто; запись id в порядке выбора без дублей; фокус на open; множественный и одиночный', async ({ page }) => {
  await login(page, OWNER);
  const a = await upload(page, fx('small.jpg'), { name: 'a.jpg' });
  const b = await upload(page, fx('small2.jpg'), { name: 'b.jpg' });
  const c = await upload(page, fx('tiny.png'), { name: 'c.png' });
  await page.goto(PICK);
  await photos(page).getByTestId('media-picker-open').click();
  await expect(page.getByTestId('media-picker-confirm')).toBeDisabled();
  const byName = (n) => page.getByTestId('media-picker-item').and(page.locator(`[title="${n}"]`));
  await byName('c.png').click();
  await byName('a.jpg').click();
  await byName('b.jpg').click();
  await byName('b.jpg').click();
  await byName('b.jpg').click();
  await page.getByTestId('media-picker-confirm').click();
  await expect(modal(page)).toHaveCount(0);
  await expect(photos(page).getByTestId('media-picker-open')).toBeFocused();
  await expect(photos(page).getByTestId('media-picker-clear')).toHaveCount(3);
  await cover(page).getByTestId('media-picker-open').click();
  await byName('b.jpg').click();
  await page.getByTestId('media-picker-confirm').click();
  await page.getByTestId('test-picker-submit').click();
  expect(await result(page)).toEqual({ cover_media_id: [b.id], photos: [c.id, a.id, b.id] });
});

test('media-picker-cancel: окно закрывается, значение прежнее; загруженные за это время файлы остаются в медиатеке', async ({ page }) => {
  await login(page, OWNER);
  const a = await upload(page, fx('small.jpg'), { name: 'a.jpg' });
  await page.goto(`${PICK}?cover=${a.id}`);
  await cover(page).getByTestId('media-picker-open').click();
  await page.getByTestId('media-picker-tab-upload').click();
  await page.getByTestId('media-file-input').setInputFiles(fx('small2.jpg'));
  await waitQueueIdle(page);
  await page.getByTestId('media-picker-cancel').click();
  await expect(modal(page)).toHaveCount(0);
  await page.getByTestId('test-picker-submit').click();
  expect((await result(page)).cover_media_id).toEqual([a.id]);
  expect(mediaCount()).toBe(2);
});

test('media-picker-close: «×» (aria-label «Закрыть») действует как «Отмена»', async ({ page }) => {
  await login(page, OWNER);
  await upload(page);
  await page.goto(PICK);
  await cover(page).getByTestId('media-picker-open').click();
  await page.getByTestId('media-picker-item').first().click();
  await expect(page.getByTestId('media-picker-close')).toHaveAttribute('aria-label', 'Закрыть');
  await page.getByTestId('media-picker-close').click();
  await expect(modal(page)).toHaveCount(0);
  await expect(cover(page).getByTestId('media-picker-preview')).toContainText('Фото не выбрано');
});

// ---------------------------------------------------------------- конвейер и безопасность
test.describe('конвейер загрузки', () => {
  test('JPEG ~9 МБ с EXIF Orientation=6 и GPS через интерфейс: сжатие в браузере, 6 файлов, WebP 400/1200/1920, портрет, без EXIF/GPS', async ({ page }) => {
    test.setTimeout(120000);
    expect(fs.statSync(fx('photo-exif-gps.jpg')).size).toBeGreaterThan(8 * 1048576);
    await login(page, OWNER);
    await gotoMedia(page);
    await uploadViaUI(page, fx('photo-exif-gps.jpg'));
    await expect(queueItems(page).first()).toContainText('Готово');
    expect(mediaCount()).toBe(1);
    const row = mediaRow(1);
    expect(Number(row.source_size)).toBeLessThan(fs.statSync(fx('photo-exif-gps.jpg')).size / 2);
    await checkProcessed(page, row);
  });

  async function checkProcessed(page, row) {
    const v = JSON.parse(row.variants);
    const files = filesOnDisk();
    expect(files).toHaveLength(6);
    for (const f of files) expect(f).toMatch(/^images\/\d{4}\/\d{2}\/[0-9a-f]{32}-(thumb|medium|large)\.(webp|jpg)$/);
    expect(Number(row.width)).toBe(1440);
    expect(Number(row.height)).toBe(1920);
    expect(v.thumb.h).toBe(400);
    expect(v.medium.h).toBe(1200);
    expect(v.large.h).toBe(1920);
    expect(v.large.webp_bytes).toBeLessThan(1048576);
    expect(Number(row.size)).toBeLessThan(2 * 1048576);
    for (const rel of variantFiles(row)) {
      const r = await page.request.get('/uploads/' + rel);
      expect(r.status(), rel).toBe(200);
      const body = await r.body();
      expect(M.hasMetadata(body), `метаданные в ${rel}`).toBe(false);
    }
  }

  test('тот же JPEG напрямую на сервер (без сжатия в браузере): обрабатывается так же', async ({ page }) => {
    await login(page, OWNER);
    const r = await M.apiUpload(page, fx('photo-exif-gps.jpg'));
    expect(r.status()).toBe(201);
    const j = await r.json();
    expect(j.media.result_size).toBeLessThan(1048576);
    expect(j.media.source_size).toBeGreaterThan(8 * 1048576);
    await checkProcessed(page, mediaRow(j.media.id));
  });

  for (const o of [3, 6, 8]) {
    test(`ориентация EXIF ${o} применена на сервере`, async ({ page }) => {
      await login(page, OWNER);
      const m = await upload(page, fx(`orient-${o}.jpg`));
      const row = mediaRow(m.id);
      // файл 400x300; при 6/8 — портрет 300x400, при 3 — ландшафт
      if (o === 3) expect([Number(row.width), Number(row.height)]).toEqual([400, 300]);
      else expect([Number(row.width), Number(row.height)]).toEqual([300, 400]);
      for (const rel of variantFiles(row)) expect(M.hasMetadata(M.readUpload(rel))).toBe(false);
    });
  }

  test('PNG с прозрачностью: WebP сохраняет альфа, JPEG — белый фон; GIF -> статичные варианты', async ({ page }) => {
    await login(page, OWNER);
    const p = await upload(page, fx('alpha.png'));
    const row = mediaRow(p.id);
    const webp = M.readUpload(JSON.parse(row.variants).large.webp);
    expect(webp.slice(0, 4).toString()).toBe('RIFF');
    // WebP с альфой: чанк VP8X с флагом alpha или ALPH
    expect(webp.includes(Buffer.from('ALPH')) || (webp.includes(Buffer.from('VP8X')) && (webp[20] & 0x10) !== 0) || webp.includes(Buffer.from('VP8L'))).toBe(true);
    const g = await upload(page, fx('anim.gif'));
    expect(mediaRow(g.id).mime).toBe('image/webp');
  });

  test('безопасность: .php.jpg с PHP-кодом отклонён (415), ничего не записано; полиглот перекодирован без PHP-кода; .php в uploads не исполняется', async ({ page }) => {
    await login(page, OWNER);
    const r = await M.apiUpload(page, fx('fake.php.jpg'));
    expect(r.status()).toBe(415);
    expect((await r.json()).code).toBe('not_image');
    expect(mediaCount()).toBe(0);
    expect(filesOnDisk()).toEqual([]);
    const poly = await M.apiUpload(page, fx('fake-polyglot.php.jpg'));
    if (poly.status() === 201) {
      const row = mediaRow((await poly.json()).media.id);
      for (const rel of variantFiles(row)) {
        expect(rel).not.toMatch(/\.php/);
        expect(M.readUpload(rel).includes(Buffer.from('<?php'))).toBe(false);
      }
    } else {
      expect([415, 422]).toContain(poly.status());
      expect(filesOnDisk()).toEqual([]);
    }
    fs.mkdirSync(M.UPLOADS + '/images/2099/01', { recursive: true });
    fs.writeFileSync(M.UPLOADS + '/images/2099/01/probe.php', "<?php echo 'EXECUTED_' . (6*7);");
    const probe = await page.request.get('/uploads/images/2099/01/probe.php');
    expect(await probe.text()).not.toContain('EXECUTED_42');
  });

  test('отклонения: SVG 415, PDF 415, бомба 422 too_many_pixels, обрезанный 422, >лимита 413, HEIC без поддержки 415 — без строк в БД и файлов', async ({ page }) => {
    await login(page, OWNER);
    const cases = [
      ['image.svg', 415, 'not_image'], ['doc.pdf', 415, 'not_image'], ['bomb.png', 422, 'too_many_pixels'],
      ['broken.jpg', 422, 'broken'], ['big.gif', 413, 'too_large'],
    ];
    for (const [f, status, code] of cases) {
      const r = await M.apiUpload(page, fx(f));
      expect(r.status(), f).toBe(status);
      expect((await r.json()).code, f).toBe(code);
    }
    const h = await M.apiUpload(page, fx('sample.heic'));
    expect([415]).toContain(h.status());
    expect(mediaCount()).toBe(0);
    expect(filesOnDisk()).toEqual([]);
  });

  test('тело больше post_max_size -> 413 (не 400)', async ({ page }) => {
    await login(page, OWNER);
    const token = await M.csrf(page);
    const r = await M.apiUpload(page, fx('small.jpg'), { token, buffer: Buffer.alloc(31 * 1048576, 1), name: 'huge.jpg' });
    expect(r.status()).toBe(413);
    expect((await r.json()).code).toBe('too_large');
    expect(mediaCount()).toBe(0);
  });

  test('без CSRF -> 400 JSON; без сессии -> 401 JSON; editor может загружать', async ({ page, baseURL, browser }) => {
    await login(page, OWNER);
    const noCsrf = await M.apiUpload(page, fx('small.jpg'), { token: '' });
    expect(noCsrf.status()).toBe(400);
    expect((await noCsrf.json()).code).toBe('csrf');
    const anon = await pwRequest.newContext({ baseURL });
    const r = await anon.post('/admin/media/upload', {
      headers: { 'X-Requested-With': 'fetch', Accept: 'application/json' },
      multipart: { file: { name: 'a.jpg', mimeType: 'image/jpeg', buffer: fs.readFileSync(fx('small.jpg')) } },
      maxRedirects: 0,
    });
    expect(r.status()).toBe(401);
    expect((await r.json()).code).toBe('session');
    await anon.dispose();
    expect(mediaCount()).toBe(0);
    const ctx = await browser.newContext();
    const p2 = await ctx.newPage();
    await login(p2, EDITOR);
    const ok = await M.apiUpload(p2, fx('small.jpg'));
    expect(ok.status()).toBe(201);
    await ctx.close();
    expect(mediaCount()).toBe(1);
    void csrfOf;
  });
});
