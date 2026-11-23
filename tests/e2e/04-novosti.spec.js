// @ts-check
// Фича 04 «Новости»: по одному тесту на каждую строку таблицы «Элементы интерфейса» (имя начинается с testid) + пограничные случаи.
const { test, expect } = require('@playwright/test');
const { resetApp, login, ageSession, execSql, dbQuery, trackPageErrors, expectNoPageErrors } = require('./helpers');
const { OWNER, EDITOR, csrfOf } = require('./auth-helpers');
const { fx, upload, mediaRow } = require('./media-helpers');
const N = require('./news-helpers');
const {
  NOW, setClock, createNews, createScheduled, seedNews, bulkNews, photos, fastAutosave, openNew, openEdit, panel, area, typeBody, fillRu, tab,
  pickInModal, flash, formPhotoIds, newsRow, kkRow, newsCount, lastNewsId, newsMedia, auditActions, postNews, formKey,
} = N;

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d|net::ERR_FAILED/ });
});
test.afterEach(() => expectNoPageErrors());

const q = (s) => String(s).replace(/'/g, "''");
const rows = (page) => page.getByTestId('news-row');
const titles = (page) => page.getByTestId('news-row-title').allInnerTexts();
const body = (page, lang = 'ru') => area(page, lang);
const bodyHtml = (page, lang = 'ru') => area(page, lang).innerHTML();
const submit = (page) => page.getByTestId('news-submit');
const crumbs = (page) => page.getByTestId('header-breadcrumbs');

/** Ожидает, что запрос автосохранения произойдёт; возвращает JSON-ответ. */
const nextAutosave = (page, timeout = 15000) => page.waitForResponse((r) => r.url().endsWith('/admin/news/autosave'), { timeout }).then(async (r) => ({ status: r.status(), json: await r.json() }));

// ================================================================ меню и список

test('nav-news: виден owner и editor, открывает список, aria-current, крошки «Главная / Новости»; активен и в редакторе', async ({ page, browser }) => {
  await login(page, OWNER);
  await page.getByTestId('nav-news').click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  await expect(page.getByTestId('nav-news')).toHaveAttribute('aria-current', 'page');
  await expect(crumbs(page)).toContainText('Главная');
  await expect(crumbs(page)).toContainText('Новости');
  await expect(page.getByRole('heading', { name: 'Новости', level: 1 })).toBeVisible();
  const id = await createNews(page);
  await openEdit(page, id);
  await expect(page.getByTestId('nav-news')).toHaveAttribute('aria-current', 'page');
  const ctx = await browser.newContext();
  const p2 = await ctx.newPage();
  await login(p2, EDITOR);
  await expect(p2.getByTestId('nav-news')).toBeVisible();
  await p2.getByTestId('nav-news').click();
  await expect(p2).toHaveURL(/\/admin\/news$/);
  await expect(p2.getByTestId('nav-news')).toHaveAttribute('aria-current', 'page');
  await ctx.close();
});

test('nav-news: без входа любой адрес /admin/news* ведёт на вход', async ({ page }) => {
  await createNewsAsOwner(page);
  await page.context().clearCookies();
  for (const u of ['/admin/news', '/admin/news/new', '/admin/news/1/edit', '/admin/news/1/preview']) {
    await page.goto(u);
    await expect(page).toHaveURL(/\/admin\/login/);
  }
  const r = await page.request.post('/admin/news', { form: { title_ru: 'x' }, maxRedirects: 0 });
  expect([302, 303, 400]).toContain(r.status());
  expect(newsCount()).toBe(1);
});

async function createNewsAsOwner(page) {
  await login(page, OWNER);
  return createNews(page);
}

test('news-create: открывает форму новой новости, крошки «Новости / Новая новость», фокус в заголовке', async ({ page }) => {
  await login(page, OWNER);
  await createNews(page);
  await page.goto('/admin/news');
  await page.getByTestId('news-create').click();
  await expect(page).toHaveURL(/\/admin\/news\/new$/);
  await expect(crumbs(page)).toContainText('Новости');
  await expect(crumbs(page)).toContainText('Новая новость');
  await expect(page.getByTestId('news-title-ru')).toBeFocused();
});

test('news-search: RU и ҚАЗ без учёта регистра, статус сохраняется, счётчики с q, обрезка, % и _ буквально, <script> текстом', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await createNews(page, { title_ru: 'Осенний Утренник', title_kk: 'Күзгі ҚОНАҚ', body_kk: '<p>x</p>' });
  await createNews(page, { title_ru: 'Прогулка', title_kk: 'Ән-ҮЙ' });
  await createNews(page, { title_ru: 'Скидка 50% и a_b', publish_mode: 'draft' });
  await createNews(page, { title_ru: 'Скидка 50 axb', publish_mode: 'draft' });
  await page.goto('/admin/news');
  await page.getByTestId('news-search').fill('УТРЕННИК');
  await page.getByTestId('news-search').press('Enter');
  await expect(page).toHaveURL(/q=/);
  expect(await titles(page)).toEqual(['Осенний Утренник']);
  await expect(page.getByTestId('news-filter-all')).toContainText('1');
  await expect(page.getByTestId('news-filter-draft')).toContainText('0');
  for (const [text, expected] of [['қонақ', ['Осенний Утренник']], ['ҚОНАҚ', ['Осенний Утренник']], ['әН-үй', ['Прогулка']], ['50%', ['Скидка 50% и a_b']], ['a_b', ['Скидка 50% и a_b']], ['%', ['Скидка 50% и a_b']]]) {
    await page.getByTestId('news-search').fill(text);
    await page.getByTestId('news-search').press('Enter');
    await expect(page.getByTestId('news-row-title')).toHaveText(expected);
  }
  // пробелы по краям
  await page.getByTestId('news-search').fill('   прогулка   ');
  await page.getByTestId('news-search').press('Enter');
  await expect(page.getByTestId('news-search')).toHaveValue('прогулка');
  // статус сохраняется
  await page.goto('/admin/news?status=draft');
  await page.getByTestId('news-search').fill('скидка');
  await page.getByTestId('news-search').press('Enter');
  await expect(page).toHaveURL(/status=draft/);
  await expect(rows(page)).toHaveCount(2);
  await expect(page.getByTestId('news-filter-draft')).toHaveAttribute('aria-selected', 'true');
  // > 100 символов
  await page.goto('/admin/news?q=' + encodeURIComponent('ж'.repeat(150)));
  expect((await page.getByTestId('news-search').inputValue()).length).toBe(100);
  // <script> показывается текстом
  await page.goto('/admin/news?q=' + encodeURIComponent('<script>window.__x=1</script>'));
  await expect(page.getByTestId('news-empty')).toContainText('<script>window.__x=1</script>');
  expect(await page.evaluate(() => /** @type {any} */ (window).__x)).toBeUndefined();
});

test('news-search: сброс на 1-ю страницу при новом поиске', async ({ page }) => {
  await login(page, OWNER);
  bulkNews(25);
  await page.goto('/admin/news?page=2');
  await page.getByTestId('news-search').fill('Н 0');
  await page.getByTestId('news-search').press('Enter');
  await expect(page).not.toHaveURL(/page=/);
  await expect(page.getByTestId('news-pager-summary')).toContainText('Показано 1–');
});

test('news-search-reset: видна только при непустом q, ведёт на список без q, фильтр статуса сохраняется', async ({ page }) => {
  await login(page, OWNER);
  await createNews(page, { title_ru: 'Один' });
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-search-reset')).toHaveCount(0);
  await page.goto('/admin/news?q=один&status=published');
  await page.getByTestId('news-search-reset').click();
  await expect(page).toHaveURL(/\/admin\/news\?status=published$/);
  await expect(page.getByTestId('news-search')).toHaveValue('');
  await page.goto('/admin/news?q=нет');
  await page.getByTestId('news-search-reset').click();
  await expect(page).toHaveURL(/\/admin\/news$/);
});

test('news-filter-all: все новости, вкладка выделена, N — реальное число с учётом q', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await createNews(page, { title_ru: 'Праздник А' });
  await createNews(page, { title_ru: 'Праздник Б', publish_mode: 'draft' });
  await createScheduled(page, '2026-11-30', '09:00', { title_ru: 'Другое' });
  await page.goto('/admin/news?status=draft');
  await page.getByTestId('news-filter-all').click();
  await expect(page).toHaveURL(/\/admin\/news(\?.*)?$/);
  await expect(rows(page)).toHaveCount(3);
  const f = page.getByTestId('news-filter-all');
  await expect(f).toHaveAttribute('role', 'tab');
  await expect(f).toHaveAttribute('aria-selected', 'true');
  await expect(f).toHaveCSS('background-color', 'rgb(31, 91, 216)');
  await expect(f).toContainText('3');
  await expect(page.getByTestId('news-filter-draft')).toHaveAttribute('aria-selected', 'false');
  await page.goto('/admin/news?q=праздник');
  await expect(page.getByTestId('news-filter-all')).toContainText('2');
});

test('news-filter-published: только опубликованные; наступившая запланированная переходит сюда сама (подмена Clock)', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await createNews(page, { title_ru: 'Вышла' });
  await createNews(page, { title_ru: 'Черновик', publish_mode: 'draft' });
  await createScheduled(page, '2026-11-25', '09:00', { title_ru: 'Скоро' });
  await page.goto('/admin/news');
  await page.getByTestId('news-filter-published').click();
  await expect(page).toHaveURL(/status=published/);
  await expect(page.getByTestId('news-filter-published')).toHaveAttribute('aria-selected', 'true');
  expect(await titles(page)).toEqual(['Вышла']);
  await expect(page.getByTestId('news-filter-published')).toContainText('1');
  await setClock(page, '2026-11-25 03:59:59');
  await page.reload();
  expect(await titles(page)).toEqual(['Вышла']);
  await setClock(page, '2026-11-25 04:00:00');
  await page.reload();
  expect(await titles(page)).toEqual(['Скоро', 'Вышла']);
  await expect(page.getByTestId('news-filter-published')).toContainText('2');
  await expect(page.getByTestId('news-filter-scheduled')).toContainText('0');
});

test('news-filter-draft: только черновики', async ({ page }) => {
  await login(page, OWNER);
  await createNews(page, { title_ru: 'Вышла' });
  await createNews(page, { title_ru: 'Черновик 1', publish_mode: 'draft' });
  await createNews(page, { title_ru: 'Черновик 2', intent: 'draft' });
  await page.goto('/admin/news');
  await page.getByTestId('news-filter-draft').click();
  await expect(page.getByTestId('news-filter-draft')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('news-filter-draft')).toContainText('2');
  expect((await titles(page)).sort()).toEqual(['Черновик 1', 'Черновик 2']);
});

test('news-filter-scheduled: только запланированные; 0 — вкладка видна с «0» и news-empty', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await createNews(page, { title_ru: 'Вышла' });
  await page.goto('/admin/news');
  await page.getByTestId('news-filter-scheduled').click();
  await expect(page.getByTestId('news-filter-scheduled')).toBeVisible();
  await expect(page.getByTestId('news-filter-scheduled')).toContainText('0');
  await expect(page.getByTestId('news-empty')).toContainText('В этом разделе пока нет новостей.');
  await createScheduled(page, '2026-11-25', '09:00', { title_ru: 'Скоро' });
  await page.reload();
  expect(await titles(page)).toEqual(['Скоро']);
  await expect(page.getByTestId('news-filter-scheduled')).toContainText('1');
});

test('news-list: сортировка по дате убыванием, запланированные сверху', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, '2026-11-10 00:00:00');
  await createNews(page, { title_ru: 'Старая' });
  await setClock(page, '2026-11-12 00:00:00');
  await createNews(page, { title_ru: 'Свежая' });
  await createScheduled(page, '2026-12-01', '09:00', { title_ru: 'Будущая' });
  await createScheduled(page, '2026-12-05', '09:00', { title_ru: 'Дальняя' });
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-list')).toBeVisible();
  expect(await titles(page)).toEqual(['Дальняя', 'Будущая', 'Свежая', 'Старая']);
});

test('news-row: миниатюра, заголовок, мета, бейджи, статус, действия; длинный заголовок не ломает строку', async ({ page }) => {
  await login(page, OWNER);
  await createNews(page, { title_ru: 'Длинный ' + 'Словооооооооо '.repeat(3) + 'Ж'.repeat(60) });
  await page.setViewportSize({ width: 800, height: 700 });
  await page.goto('/admin/news');
  const row = rows(page).first();
  for (const t of ['news-row-thumb', 'news-row-title', 'news-row-meta', 'news-badge-ru', 'news-badge-kk', 'news-row-status', 'news-row-edit', 'news-row-preview', 'news-row-delete']) {
    await expect(row.getByTestId(t)).toBeVisible();
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  const box = await row.boundingBox();
  const del = await row.getByTestId('news-row-delete').boundingBox();
  expect(del.x + del.width).toBeLessThanOrEqual(box.x + box.width + 1);
});

test('news-row-thumb: обложка через media_picture с alt из медиатеки; без обложки — плашка без <img>', async ({ page }) => {
  await login(page, OWNER);
  const [p] = await photos(page, 1);
  execSql(`UPDATE media SET alt='Дети рисуют' WHERE id=${p}`);
  await createNews(page, { title_ru: 'С обложкой', cover_media_id: p });
  await setClock(page, '2026-11-01 00:00:00');
  await createNews(page, { title_ru: 'Без обложки' });
  await page.goto('/admin/news');
  const withCover = rows(page).filter({ hasText: 'С обложкой' }).getByTestId('news-row-thumb');
  await expect(withCover.locator('picture')).toHaveCount(1);
  await expect(withCover.getByRole('img', { name: 'Дети рисуют' })).toBeVisible();
  const box = await withCover.boundingBox();
  expect([Math.round(box.width), Math.round(box.height)]).toEqual([88, 64]);
  const none = rows(page).filter({ hasText: 'Без обложки' }).getByTestId('news-row-thumb');
  await expect(none.locator('img')).toHaveCount(0);
  await expect(none.locator('svg')).toHaveCount(1);
});

test('news-row-title: ссылка на редактор; заголовок экранирован', async ({ page }) => {
  await login(page, OWNER);
  const id = await createNews(page, { title_ru: '<b>Жирный</b> & "кавычки"' });
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-row-title')).toHaveText('<b>Жирный</b> & "кавычки"');
  await expect(page.getByTestId('news-row-title').locator('b')).toHaveCount(0);
  await page.getByTestId('news-row-title').click();
  await expect(page).toHaveURL(new RegExp(`/admin/news/${id}/edit$`));
});

test('news-row-meta: «дата · автор» по состоянию, время в поясе Алматы, автор удалён — «—»', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, '2026-11-18 05:00:00');
  await createNews(page, { title_ru: 'Опубликованная' });
  await createScheduled(page, '2026-11-25', '09:00', { title_ru: 'Запланированная' });
  await createNews(page, { title_ru: 'Черновик', publish_mode: 'draft' });
  await createNews(page, { title_ru: 'Без автора' });
  execSql(`UPDATE news SET author_id=NULL WHERE title='Без автора'`);
  await page.goto('/admin/news');
  const meta = (t) => rows(page).filter({ hasText: t }).getByTestId('news-row-meta');
  await expect(meta('Опубликованная')).toHaveText(`18.11.2026 · ${OWNER.name}`);
  await expect(meta('Запланированная')).toHaveText(`25.11.2026, 09:00 · ${OWNER.name}`);
  await expect(meta('Черновик')).toHaveText(`не опубликована · ${OWNER.name}`);
  await expect(meta('Без автора')).toHaveText('18.11.2026 · —');
});

test('news-badge-ru: зелёный «RU» у каждой новости', async ({ page }) => {
  await login(page, OWNER);
  await createNews(page, { title_ru: 'А' });
  await createNews(page, { title_ru: 'Б', publish_mode: 'draft' });
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-badge-ru')).toHaveCount(2);
  for (const b of await page.getByTestId('news-badge-ru').all()) {
    await expect(b).toHaveText('RU');
    await expect(b).toHaveClass(/lang-badge--ok/);
  }
});

test('news-badge-kk: нет перевода → оранжевый; заполнили и сохранили → зелёный; очистили → снова оранжевый; копия русского → оранжевый', async ({ page }) => {
  await login(page, OWNER);
  const id = await createNews(page, { title_ru: 'Тест', body_ru: '<p>текст</p>' });
  const badge = () => page.getByTestId('news-badge-kk');
  await page.goto('/admin/news');
  await expect(badge()).toHaveText('ҚАЗ — нет перевода');
  await expect(badge()).toHaveClass(/lang-badge--warn/);
  await openEdit(page, id);
  await tab(page, 'kk');
  await page.getByTestId('news-title-kk').fill('Сынақ');
  await typeBody(page, 'kk', 'мәтін');
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  await expect(badge()).toHaveText('ҚАЗ');
  await expect(badge()).toHaveClass(/lang-badge--ok/);
  await openEdit(page, id);
  await tab(page, 'kk');
  await page.getByTestId('news-title-kk').fill('');
  await submit(page).click();
  await expect(badge()).toHaveText('ҚАЗ — нет перевода');
  // копия русского без изменений
  await openEdit(page, id);
  await tab(page, 'kk');
  page.once('dialog', (d) => d.accept()); // «Заменить казахский текст русским?» (в KK уже есть текст)
  await page.getByTestId('news-copy-from-ru').click();
  await submit(page).click();
  await expect(badge()).toHaveText('ҚАЗ — нет перевода');
  expect(kkRow(id).title).toBe('Тест');
  // отличается заголовком
  await openEdit(page, id);
  await tab(page, 'kk');
  await page.getByTestId('news-title-kk').fill('Тест қаз');
  await submit(page).click();
  await expect(badge()).toHaveText('ҚАЗ');
});

test('news-row-status: «Опубликована» / «Запланирована» / «Черновик» на момент запроса', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await createNews(page, { title_ru: 'Вышла' });
  await createScheduled(page, '2026-11-25', '09:00', { title_ru: 'Скоро' });
  await createNews(page, { title_ru: 'Чернь', publish_mode: 'draft' });
  await page.goto('/admin/news');
  const st = (t) => rows(page).filter({ hasText: t }).getByTestId('news-row-status');
  await expect(st('Вышла')).toHaveText('Опубликована');
  await expect(st('Скоро')).toHaveText('Запланирована');
  await expect(st('Чернь')).toHaveText('Черновик');
  await expect(st('Вышла')).toHaveCSS('background-color', 'rgb(230, 244, 234)');
  await expect(st('Скоро')).toHaveCSS('background-color', 'rgb(234, 240, 253)');
  await expect(st('Чернь')).toHaveCSS('background-color', 'rgb(240, 242, 245)');
  await setClock(page, '2026-11-25 04:00:00');
  await page.reload();
  await expect(st('Скоро')).toHaveText('Опубликована');
});

test('news-row-edit: кнопка-иконка с aria-label 44×44 ведёт в редактор', async ({ page }) => {
  await login(page, OWNER);
  const id = await createNews(page);
  await page.goto('/admin/news');
  const b = page.getByTestId('news-row-edit');
  await expect(b).toHaveAttribute('aria-label', 'Редактировать');
  const box = await b.boundingBox();
  expect(box.width).toBeGreaterThanOrEqual(44);
  expect(box.height).toBeGreaterThanOrEqual(44);
  await b.click();
  await expect(page).toHaveURL(new RegExp(`/admin/news/${id}/edit$`));
});

test('news-row-preview: новая вкладка (_blank, noopener), предпросмотр для любого состояния, включая черновик', async ({ page }) => {
  await login(page, OWNER);
  const draft = await createNews(page, { title_ru: 'Черновик для просмотра', publish_mode: 'draft' });
  await page.goto('/admin/news');
  const b = page.getByTestId('news-row-preview');
  await expect(b).toHaveAttribute('target', '_blank');
  await expect(b).toHaveAttribute('rel', 'noopener');
  await expect(b).toHaveAttribute('aria-label', 'Предпросмотр');
  const [popup] = await Promise.all([page.context().waitForEvent('page'), b.click()]);
  await expect(popup).toHaveURL(new RegExp(`/admin/news/${draft}/preview$`));
  await expect(popup.getByTestId('news-preview-page')).toContainText('Черновик для просмотра');
  await popup.close();
});

test('news-row-delete: confirm с названием; отмена — ничего; удаление, тост, журнал, редирект с q/status/page; двойной клик — один запрос', async ({ page }) => {
  await login(page, OWNER);
  const id = await createNews(page, { title_ru: 'Удаляемая' });
  await createNews(page, { title_ru: 'Остаётся' });
  await page.goto('/admin/news');
  const row = rows(page).filter({ hasText: 'Удаляемая' });
  let msg = '';
  page.once('dialog', (d) => { msg = d.message(); return d.dismiss(); });
  await row.getByTestId('news-row-delete').click();
  expect(msg).toBe('Удалить новость «Удаляемая»? Восстановить её будет нельзя.');
  expect(newsCount()).toBe(2);
  let posts = 0;
  page.on('request', (r) => { if (r.method() === 'POST' && r.url().includes(`/news/${id}/delete`)) posts++; });
  page.on('dialog', (d) => d.accept());
  await row.getByTestId('news-row-delete').dblclick();
  await expect(flash(page)).toHaveText('Новость удалена');
  expect(posts).toBe(1);
  expect(newsCount()).toBe(1);
  expect(auditActions().map((a) => a.action)).toContain('news.delete');
  expect(await titles(page)).toEqual(['Остаётся']);
});

test('news-row-delete: редирект сохраняет q, status и страницу; опустевшая страница → предыдущая', async ({ page }) => {
  await login(page, OWNER);
  bulkNews(21);
  await page.goto('/admin/news?status=published&q=Н&page=2');
  await expect(rows(page)).toHaveCount(1);
  page.on('dialog', (d) => d.accept());
  await page.getByTestId('news-row-delete').click();
  await expect(page).toHaveURL(/\/admin\/news\?q=%D0%9D&status=published$/);
  await expect(rows(page)).toHaveCount(20);
  await expect(page.getByTestId('news-filter-published')).toHaveAttribute('aria-selected', 'true');
});

test('news-row-delete: уже удалена в другой вкладке — 404; после таймаута — вход, не удалено', async ({ page }) => {
  await login(page, OWNER);
  const a = await createNews(page, { title_ru: 'Первая' });
  const b = await createNews(page, { title_ru: 'Вторая' });
  await page.goto('/admin/news');
  execSql(`DELETE FROM news WHERE id=${a}`);
  page.on('dialog', (d) => d.accept());
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(`/news/${a}/delete`)),
    rows(page).filter({ hasText: 'Первая' }).getByTestId('news-row-delete').click(),
  ]);
  expect(resp.status()).toBe(404);
  await page.goto('/admin/news');
  await ageSession(page, 7 * 3600);
  await rows(page).filter({ hasText: 'Вторая' }).getByTestId('news-row-delete').click();
  await expect(page).toHaveURL(/\/admin\/login/);
  expect(newsRow(b)).toBeTruthy();
});

test('news-empty: нет новостей совсем / фильтр без новостей / поиск без результатов', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-empty')).toContainText('Новостей пока нет. Создайте первую.');
  await expect(page.getByTestId('news-create')).toHaveCount(1);
  await page.getByTestId('news-create').click();
  await expect(page).toHaveURL(/\/news\/new$/);
  await createNews(page, { title_ru: 'Есть' });
  await page.goto('/admin/news?status=draft');
  await expect(page.getByTestId('news-empty')).toContainText('В этом разделе пока нет новостей.');
  await page.goto('/admin/news?q=' + encodeURIComponent('<i>нету</i>'));
  await expect(page.getByTestId('news-empty')).toContainText('Ничего не найдено по запросу «<i>нету</i>»');
  await expect(page.getByTestId('news-empty').locator('i')).toHaveCount(0);
  await expect(page.getByTestId('news-search-reset')).toBeVisible();
});

test('news-pager-summary: «Показано X–Y из N», по 20 на страницу; при 0 строки нет', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-pager-summary')).toHaveCount(0);
  bulkNews(25);
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-pager-summary')).toHaveText('Показано 1–20 из 25');
  await expect(rows(page)).toHaveCount(20);
  await page.goto('/admin/news?page=2');
  await expect(page.getByTestId('news-pager-summary')).toHaveText('Показано 21–25 из 25');
  await expect(rows(page)).toHaveCount(5);
});

test('news-page-prev: «← Назад» ведёт на предыдущую страницу с q и status; нет на 1-й странице и при ≤ 20 новостях', async ({ page }) => {
  await login(page, OWNER);
  bulkNews(45);
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-page-prev')).toHaveCount(0);
  await page.goto('/admin/news?q=Н&status=published&page=3');
  await page.getByTestId('news-page-prev').click();
  await expect(page).toHaveURL(/q=%D0%9D&status=published&page=2|status=published&q=%D0%9D&page=2|page=2/);
  expect(page.url()).toContain('status=published');
  expect(page.url()).toContain('page=2');
  await expect(page.getByTestId('news-pager-summary')).toHaveText('Показано 21–40 из 45');
  execSql('DELETE FROM news WHERE id > 15');
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-page-prev')).toHaveCount(0);
  await expect(page.getByTestId('news-page-next')).toHaveCount(0);
});

test('news-page-num: ≤ 7 страниц — все номера; больше — первая, последняя, текущая ±2 и «…»; текущая aria-current', async ({ page }) => {
  await login(page, OWNER);
  bulkNews(100);
  await page.goto('/admin/news?page=3');
  await expect(page.getByTestId('news-page-num')).toHaveText(['1', '2', '3', '4', '5']);
  await expect(page.getByTestId('news-page-num').nth(2)).toHaveAttribute('aria-current', 'page');
  await page.getByTestId('news-page-num').nth(4).click();
  await expect(page.getByTestId('news-pager-summary')).toHaveText('Показано 81–100 из 100');
  bulkNews(60, 'М');
  await page.goto('/admin/news?page=5');
  await expect(page.getByTestId('news-page-num')).toHaveText(['1', '3', '4', '5', '6', '7', '8']);
  await expect(page.locator('.pager-gap')).toHaveCount(1);
  await page.goto('/admin/news?page=1');
  await expect(page.getByTestId('news-page-num')).toHaveText(['1', '2', '3', '8']);
});

test('news-page-next: «Дальше →» ведёт на следующую; нет на последней; ?page=999 → последняя', async ({ page }) => {
  await login(page, OWNER);
  bulkNews(45);
  await page.goto('/admin/news');
  await page.getByTestId('news-page-next').click();
  await expect(page.getByTestId('news-pager-summary')).toHaveText('Показано 21–40 из 45');
  await page.goto('/admin/news?page=999');
  await expect(page.getByTestId('news-pager-summary')).toHaveText('Показано 41–45 из 45');
  await expect(page.getByTestId('news-page-next')).toHaveCount(0);
  await page.goto('/admin/news?page=abc');
  await expect(page.getByTestId('news-pager-summary')).toHaveText('Показано 1–20 из 45');
  await page.goto('/admin/news?status=xxx');
  await expect(page.getByTestId('news-filter-all')).toHaveAttribute('aria-selected', 'true');
});

// ================================================================ редактор: панель действий, автосохранение, форма

test('news-actions: липкая панель всегда видна при прокрутке; на ширине < 600 px кнопки переносятся, высота ≥ 44 px', async ({ page }) => {
  await login(page, OWNER);
  await page.setViewportSize({ width: 1200, height: 400 });
  await openNew(page);
  await typeBody(page, 'ru', Array.from({ length: 40 }, (_, i) => 'строка ' + i).join('\n'));
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await expect(page.getByTestId('news-actions')).toBeInViewport();
  const top = (await page.getByTestId('news-actions').boundingBox()).y;
  expect(top).toBeLessThan(80);
  await page.setViewportSize({ width: 420, height: 800 });
  await page.evaluate(() => window.scrollTo(0, 0));
  for (const t of ['news-preview', 'news-save-draft', 'news-submit']) {
    const box = await page.getByTestId(t).boundingBox();
    expect(box.height, t).toBeGreaterThanOrEqual(44);
    expect(box.x + box.width, t).toBeLessThanOrEqual(420);
  }
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('news-autosave-status: «Есть несохранённые изменения» → «Сохранение…» → «Сохранено автоматически в ЧЧ:ММ»; пустой заголовок — подсказка; у опубликованной строки нет', async ({ page }) => {
  await login(page, OWNER);
  await fastAutosave(page);
  await openNew(page);
  const st = page.getByTestId('news-autosave-status');
  await page.getByTestId('news-excerpt-ru').fill('Описание без заголовка');
  await expect(st).toHaveText('Введите заголовок, чтобы сохранить черновик');
  expect(newsCount()).toBe(0);
  await page.getByTestId('news-title-ru').fill('Черновик');
  await expect(st).toHaveText('Есть несохранённые изменения');
  await expect(st).toHaveText(/^Сохранено автоматически в \d\d:\d\d$/);
  expect(newsCount()).toBe(1);
  const published = await createNews(page, { title_ru: 'Опубликованная' });
  await openEdit(page, published);
  await expect(page.getByTestId('news-autosave-status')).toHaveCount(0);
});

test('news-autosave-status: ошибки — 422 «Не сохранено: исправьте ошибки» с ошибкой у поля; сеть — «Не удалось сохранить автоматически»; таймаут сессии — «Сессия истекла…», текст не потерян', async ({ page }) => {
  await login(page, OWNER);
  await fastAutosave(page);
  await openNew(page);
  const st = page.getByTestId('news-autosave-status');
  await page.getByTestId('news-title-ru').fill('Заголовок');
  await expect(st).toHaveText(/Сохранено автоматически/);
  // 422: превышение лимита в обход maxlength
  await tab(page, 'kk');
  await page.getByTestId('news-title-kk').evaluate((el) => { el.removeAttribute('maxlength'); });
  await page.getByTestId('news-title-kk').fill('ә'.repeat(130));
  await expect(st).toHaveText('Не сохранено: исправьте ошибки');
  await expect(panel(page, 'kk').getByTestId('news-field-error')).toContainText('Не больше 120 символов');
  await page.getByTestId('news-title-kk').fill('Қазақша');
  await expect(st).toHaveText(/Сохранено автоматически/);
  await expect(page.getByTestId('news-field-error')).toHaveCount(0);
  await tab(page, 'ru');
  // сеть
  await page.route('**/admin/news/autosave', (r) => r.abort());
  await page.getByTestId('news-title-ru').fill('Заголовок 2');
  await expect(st).toHaveText('Не удалось сохранить автоматически');
  await page.unroute('**/admin/news/autosave');
  // сессия истекла
  await ageSession(page, 7 * 3600);
  await page.getByTestId('news-title-ru').fill('Заголовок 3 — не потеряется');
  await expect(st).toHaveText('Сессия истекла — войдите снова в новой вкладке, текст на этой странице не потерян');
  await expect(page.getByTestId('news-title-ru')).toHaveValue('Заголовок 3 — не потеряется');
  await expect(page).toHaveURL(/\/admin\/news\/\d+\/edit$/);
});

test('news-autosave-status: конфликт версий при автосохранении показывает news-conflict и останавливает автосохранение', async ({ page, browser }) => {
  await login(page, OWNER);
  const id = await createNews(page, { title_ru: 'Черновик', publish_mode: 'draft' });
  await fastAutosave(page);
  await openEdit(page, id);
  // «другая вкладка» сохраняет ту же новость
  const other = await browser.newContext();
  const p2 = await other.newPage();
  await login(p2, OWNER);
  const r = await postNews(p2, `/admin/news/${id}`, { title_ru: 'Из другой вкладки', publish_mode: 'draft', intent: 'submit', version: '1', slug_auto: '1' });
  expect(r.status()).toBe(302);
  await page.getByTestId('news-title-ru').fill('Моя правка');
  await expect(page.getByTestId('news-conflict')).toBeVisible();
  await expect(page.getByTestId('news-autosave-status')).toHaveText('');
  expect(newsRow(id).title).toBe('Из другой вкладки');
  await page.getByTestId('news-title-ru').fill('Ещё правка');
  await expect(page.getByTestId('news-title-ru')).toHaveValue('Ещё правка');
  expect(newsRow(id).title).toBe('Из другой вкладки');
  await other.close();
});

test('news-form: автосохранение через 5 с после последнего изменения и не чаще раза в 15 с; один запрос одновременно', async ({ page }) => {
  test.setTimeout(90000);
  await login(page, OWNER);
  await openNew(page);
  const stamps = [];
  page.on('request', (r) => { if (r.url().endsWith('/admin/news/autosave')) stamps.push(Date.now()); });
  const t0 = Date.now();
  await page.getByTestId('news-title-ru').fill('Раз');
  await expect.poll(() => stamps.length, { timeout: 12000, intervals: [250] }).toBe(1);
  expect(stamps[0] - t0).toBeGreaterThanOrEqual(4500);
  expect(stamps[0] - t0).toBeLessThan(8000);
  await expect(page.getByTestId('news-autosave-status')).toHaveText(/Сохранено автоматически/);
  // новая правка сразу после сохранения: следующий запрос не раньше чем через 15 с от предыдущего
  await page.getByTestId('news-title-ru').fill('Два');
  await expect.poll(() => stamps.length, { timeout: 25000, intervals: [250] }).toBe(2);
  expect(stamps[1] - stamps[0]).toBeGreaterThanOrEqual(14500);
  expect(newsCount()).toBe(1);
  expect(newsRows()[0].title).toBe('Два');
});

const newsRows = () => N.newsRows();

test('news-form: после первого автосохранения у новой — скрытые id/version, action → update, адрес → /edit, появляется news-delete; повтор не создаёт дубль', async ({ page }) => {
  await login(page, OWNER);
  await fastAutosave(page);
  await openNew(page);
  await expect(page.getByTestId('news-delete')).toBeHidden();
  const form = page.getByTestId('news-form');
  await expect(form.locator('input[name="id"]')).toHaveValue('');
  await page.getByTestId('news-title-ru').fill('Автосохранённая');
  await expect(page.getByTestId('news-autosave-status')).toHaveText(/Сохранено автоматически/);
  const id = lastNewsId();
  await expect(form.locator('input[name="id"]')).toHaveValue(String(id));
  await expect(form.locator('input[name="version"]')).toHaveValue('1');
  await expect(form).toHaveAttribute('action', new RegExp(`/admin/news/${id}$`));
  await expect(page).toHaveURL(new RegExp(`/admin/news/${id}/edit$`));
  await expect(page.getByTestId('news-delete')).toBeVisible();
  await page.getByTestId('news-title-ru').fill('Автосохранённая 2');
  await expect(form.locator('input[name="version"]')).toHaveValue('2');
  expect(newsCount()).toBe(1);
  // ручная отправка после автосохранения — тоже без дубля
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(newsCount()).toBe(1);
  expect(newsRow(id).status).toBe('published');
});

test('news-form: пустой заголовок — автосохранение не запускается, статус-подсказка', async ({ page }) => {
  await login(page, OWNER);
  await fastAutosave(page);
  let autosaves = 0;
  page.on('request', (r) => { if (r.url().endsWith('/admin/news/autosave')) autosaves++; });
  await openNew(page);
  await page.getByTestId('news-excerpt-ru').fill('только описание');
  await expect(page.getByTestId('news-autosave-status')).toHaveText('Введите заголовок, чтобы сохранить черновик');
  await page.getByTestId('news-title-ru').fill('Х');
  await page.getByTestId('news-title-ru').fill('');
  await expect(page.getByTestId('news-autosave-status')).toHaveText('Введите заголовок, чтобы сохранить черновик');
  await quiet(page, 900);
  expect(autosaves).toBe(0);
  expect(newsCount()).toBe(0);
});

/** Подождать заведомо дольше delay автосохранения без waitForTimeout (таймер на странице). */
const quiet = (page, ms) => page.evaluate((t) => new Promise((r) => setTimeout(r, t)), ms);

test('news-form: уход со страницы с несохранёнными изменениями — beforeunload; опубликованная новость: автосохранения нет; после отправки предупреждения нет', async ({ page, context }) => {
  await login(page, OWNER);
  const id = await createNews(page, { title_ru: 'Опубликованная' });
  let autosaves = 0;
  page.on('request', (r) => { if (r.url().endsWith('/admin/news/autosave')) autosaves++; });
  await fastAutosave(page);
  await openEdit(page, id);
  await page.getByTestId('news-title-ru').fill('Изменена');
  await quiet(page, 900);
  expect(autosaves).toBe(0);
  expect(newsRow(id).title).toBe('Опубликованная');
  const dialogs = [];
  page.on('dialog', (d) => { dialogs.push(d.type()); d.dismiss().catch(() => {}); });
  await page.close({ runBeforeUnload: true });
  await expect.poll(() => dialogs).toContain('beforeunload');
  // чистая форма и форма после отправки — без предупреждения
  const p2 = await context.newPage();
  const d2 = [];
  p2.on('dialog', (d) => { d2.push(d.type()); d.accept().catch(() => {}); });
  await p2.goto(`/admin/news/${id}/edit`);
  await p2.getByTestId('news-title-ru').fill('Сохранена');
  await p2.getByTestId('news-submit').click();
  await expect(p2).toHaveURL(/\/admin\/news$/);
  expect(d2).not.toContain('beforeunload');
  await p2.goto(`/admin/news/${id}/edit`);
  await p2.close({ runBeforeUnload: true });
  expect(d2).not.toContain('beforeunload');
});

test('news-conflict: баннер при ручном сохранении устаревшей версии, ссылка «Открыть актуальную версию», введённый текст остаётся, БД не перезаписана', async ({ page, browser }) => {
  await login(page, OWNER);
  const id = await createNews(page, { title_ru: 'Исходная' });
  await openEdit(page, id);
  const other = await browser.newContext();
  const p2 = await other.newPage();
  await login(p2, EDITOR);
  await openEdit(p2, id);
  await p2.getByTestId('news-title-ru').fill('Версия второй вкладки');
  await p2.getByTestId('news-submit').click();
  await expect(p2).toHaveURL(/\/admin\/news$/);
  await page.getByTestId('news-title-ru').fill('Моя устаревшая версия');
  await typeBody(page, 'ru', 'Мой текст, который нельзя терять');
  await submit(page).click();
  const c = page.getByTestId('news-conflict');
  await expect(c).toBeVisible();
  await expect(c).toContainText('Новость изменена в другой вкладке или другим пользователем. Ваши изменения не сохранены.');
  await expect(page.getByTestId('news-title-ru')).toHaveValue('Моя устаревшая версия');
  await expect(body(page)).toContainText('Мой текст, который нельзя терять');
  expect(newsRow(id).title).toBe('Версия второй вкладки');
  await c.getByRole('link', { name: 'Открыть актуальную версию' }).click();
  await expect(page).toHaveURL(new RegExp(`/admin/news/${id}/edit$`));
  await expect(page.getByTestId('news-title-ru')).toHaveValue('Версия второй вкладки');
  await expect(page.getByTestId('news-conflict')).toHaveCount(0);
  await other.close();
});

test('news-preview: кнопка с formaction/formtarget, новая вкладка показывает несохранённые правки, БД не меняется; пустой заголовок — «Без заголовка»', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const btn = page.getByTestId('news-preview');
  await expect(btn).toHaveAttribute('formaction', '/admin/news/preview');
  await expect(btn).toHaveAttribute('formtarget', '_blank');
  const [pop0] = await Promise.all([page.context().waitForEvent('page'), btn.click()]);
  await expect(pop0.getByTestId('news-preview-page')).toContainText('Без заголовка');
  await pop0.close();
  await fillRu(page, { title: 'Несохранённый заголовок', body: 'Несохранённый текст' });
  const [pop] = await Promise.all([page.context().waitForEvent('page'), btn.click()]);
  await expect(pop.getByTestId('news-preview-page')).toContainText('Несохранённый заголовок');
  await expect(pop.getByTestId('news-preview-page')).toContainText('Несохранённый текст');
  await expect(pop.getByText('Предпросмотр — изменения ещё не сохранены')).toBeVisible();
  expect(newsCount()).toBe(0);
  await pop.close();
  // существующая: БД и updated_at не меняются
  const id = await createNews(page, { title_ru: 'Сохранённая' });
  const before = newsRow(id);
  await openEdit(page, id);
  await page.getByTestId('news-title-ru').fill('Правка не сохранена');
  const [pop2] = await Promise.all([page.context().waitForEvent('page'), page.getByTestId('news-preview').click()]);
  await expect(pop2.getByTestId('news-preview-page')).toContainText('Правка не сохранена');
  const after = newsRow(id);
  expect([after.title, after.updated_at, after.version]).toEqual([before.title, before.updated_at, before.version]);
  expect(newsCount()).toBe(1);
  await pop2.close();
});

test('news-preview-page: баннер, noindex, заголовок, дата, обложка, описание, текст через санитайзер, фото сеткой; javascript: нет; внешние ссылки с noopener', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, '2026-11-18 05:00:00');
  const [cover, g1, g2] = await photos(page, 3);
  const id = await createNews(page, {
    title_ru: 'Осенний утренник', excerpt_ru: 'Краткое описание <i>как текст</i>', cover_media_id: cover, photos: [g1, g2],
    body_ru: '<p>Привет <a href="https://example.com/x">внешняя</a> <a href="javascript:alert(1)">плохая</a></p><script>window.__pwn=1</script><p onclick="window.__pwn=1">тут</p>',
  });
  await page.goto(`/admin/news/${id}/preview`);
  const pv = page.getByTestId('news-preview-page');
  await expect(page.getByText('Предпросмотр — так новость увидят на сайте')).toBeVisible();
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
  await expect(pv.getByRole('heading', { level: 1 })).toHaveText('Осенний утренник');
  await expect(pv).toContainText('18.11.2026');
  const ru = pv.locator('#pv-ru');
  await expect(ru.locator('.pv-cover picture')).toHaveCount(1);
  await expect(ru.locator('.pv-lead')).toHaveText('Краткое описание <i>как текст</i>');
  await expect(ru.locator('.pv-photos picture')).toHaveCount(2);
  const ext = ru.getByRole('link', { name: 'внешняя' });
  await expect(ext).toHaveAttribute('rel', 'noopener noreferrer');
  await expect(ext).toHaveAttribute('target', '_blank');
  await expect(ru.getByRole('link', { name: 'плохая' })).toHaveCount(0);
  await expect(ru).toContainText('плохая');
  expect(await pv.locator('script').count()).toBe(0);
  expect(await page.evaluate(() => /** @type {any} */ (window).__pwn)).toBeUndefined();
  const draft = await createNews(page, { title_ru: 'Черновик', publish_mode: 'draft' });
  await page.goto(`/admin/news/${draft}/preview`);
  await expect(page.getByTestId('news-preview-page')).toContainText('не опубликована');
  expect((await page.request.get('/admin/news/99999/preview')).status()).toBe(404);
});

test('news-preview-tab-ru: по умолчанию выбрана, показывает русскую версию', async ({ page }) => {
  await login(page, OWNER);
  const id = await createNews(page, { title_ru: 'Русский заголовок', title_kk: 'Қазақ тақырып', body_ru: '<p>рус</p>', body_kk: '<p>қаз</p>' });
  await page.goto(`/admin/news/${id}/preview`);
  const ru = page.getByTestId('news-preview-tab-ru');
  await expect(ru).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByRole('heading', { level: 1, name: 'Русский заголовок' })).toBeVisible();
  await expect(page.getByRole('heading', { level: 1, name: 'Қазақ тақырып' })).toBeHidden();
  await page.getByTestId('news-preview-tab-kk').click();
  await ru.click();
  await expect(page.getByRole('heading', { level: 1, name: 'Русский заголовок' })).toBeVisible();
  await expect(ru).toHaveAttribute('aria-selected', 'true');
});

test('news-preview-tab-kk: показывает казахскую версию; пустые поля заменены русскими с пометкой «Аударма жоқ»', async ({ page }) => {
  await login(page, OWNER);
  const full = await createNews(page, { title_ru: 'Рус А', title_kk: 'Қаз А', body_ru: '<p>рус</p>', body_kk: '<p>қаз</p>' });
  const none = await createNews(page, { title_ru: 'Рус Б', body_ru: '<p>только русский</p>' });
  await page.goto(`/admin/news/${full}/preview`);
  await page.getByTestId('news-preview-tab-kk').click();
  await expect(page.getByTestId('news-preview-tab-kk')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByRole('heading', { level: 1, name: 'Қаз А' })).toBeVisible();
  await expect(page.getByText('Аударма жоқ')).toBeHidden();
  await page.goto(`/admin/news/${none}/preview`);
  await page.getByTestId('news-preview-tab-kk').click();
  await expect(page.getByRole('heading', { level: 1, name: 'Рус Б' })).toBeVisible();
  await expect(page.locator('#pv-kk').getByText('только русский')).toBeVisible();
  await expect(page.getByText('Аударма жоқ')).toBeVisible();
});

test('news-save-draft: сохраняет черновик (published_at=NULL), остаёмся в редакторе, тост; видна только у новой/черновика при режиме ≠ «Черновик»; двойной клик — одна запись', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const btn = page.getByTestId('news-save-draft');
  await expect(btn).toBeVisible();
  await page.getByTestId('news-pub-draft').check();
  await expect(btn).toBeHidden();
  await page.getByTestId('news-pub-now').check();
  await expect(btn).toBeVisible();
  await page.getByTestId('news-title-ru').fill('Мой черновик');
  await btn.dblclick();
  await expect(page).toHaveURL(/\/admin\/news\/\d+\/edit$/);
  await expect(flash(page)).toHaveText('Черновик сохранён');
  expect(newsCount()).toBe(1);
  const r = newsRows()[0];
  expect([r.status, r.published_at]).toEqual(['draft', null]);
  await expect(page.getByTestId('news-save-draft')).toBeHidden(); // у черновика режим «Черновик»
  await page.getByTestId('news-pub-now').check();
  await expect(page.getByTestId('news-save-draft')).toBeVisible();
  // ошибки — как у news-submit
  await page.getByTestId('news-title-ru').fill('');
  await page.getByTestId('news-save-draft').click();
  await expect(page.getByTestId('news-field-error').first()).toHaveText('Введите заголовок');
  // у опубликованной кнопки нет
  const pub = await createNews(page, { title_ru: 'Опубликованная' });
  await openEdit(page, pub);
  await expect(page.getByTestId('news-save-draft')).toHaveCount(0);
});

test('news-submit: текст кнопки по режиму и состоянию', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await openNew(page);
  const s = submit(page);
  await expect(s).toHaveText('Опубликовать');
  await page.getByTestId('news-pub-draft').check();
  await expect(s).toHaveText('Сохранить черновик');
  await page.getByTestId('news-pub-later').check();
  await expect(s).toHaveText('Запланировать');
  const pub = await createNews(page, { title_ru: 'Опубл' });
  await openEdit(page, pub);
  await expect(s).toHaveText('Сохранить изменения');
  await page.getByTestId('news-pub-draft').check();
  await expect(s).toHaveText('Снять с публикации');
  await page.getByTestId('news-pub-later').check();
  await expect(s).toHaveText('Запланировать');
  const sch = await createScheduled(page, '2026-11-25', '09:00', { title_ru: 'Запл' });
  await openEdit(page, sch);
  await expect(s).toHaveText('Сохранить изменения');
  await page.getByTestId('news-pub-now').check();
  await expect(s).toHaveText('Опубликовать');
});

test('news-submit: «Опубликовать» → редирект в список, тост, журнал news.create+news.publish; «Запланировать» → время в UTC, тост с датой', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await openNew(page);
  await fillRu(page, { title: 'Первая публикация', body: 'Текст' });
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  await expect(flash(page)).toHaveText('Новость опубликована');
  const a = newsRows()[0];
  expect([a.status, a.published_at]).toEqual(['published', NOW]);
  expect(auditActions().map((x) => x.action)).toEqual(['news.create', 'news.publish']);
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Запланированная');
  await page.getByTestId('news-pub-later').check();
  await page.getByTestId('news-pub-date').fill('2026-11-25');
  await page.getByTestId('news-pub-time').fill('09:00');
  await expect(submit(page)).toHaveText('Запланировать');
  await submit(page).click();
  await expect(flash(page)).toHaveText('Публикация запланирована на 25.11.2026 09:00');
  const b = newsRows()[1];
  expect([b.status, b.published_at]).toEqual(['published', '2026-11-25 04:00:00']);
  expect(auditActions().map((x) => x.action)).toEqual(['news.create', 'news.publish', 'news.create', 'news.schedule']);
});

test('news-submit: «Сохранить изменения», «Снять с публикации», «Черновик сохранён»; журнал news.update / news.unpublish', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  const id = await createNews(page, { title_ru: 'Исходная' });
  await openEdit(page, id);
  await page.getByTestId('news-title-ru').fill('Исправленная');
  await submit(page).click();
  await expect(flash(page)).toHaveText('Изменения сохранены');
  expect(newsRow(id).published_at).toBe(NOW);
  await openEdit(page, id);
  await page.getByTestId('news-pub-draft').check();
  await submit(page).click();
  await expect(flash(page)).toHaveText('Новость снята с публикации');
  expect(newsRow(id).status).toBe('draft');
  await openEdit(page, id);
  await submit(page).click(); // режим «Черновик» у черновика
  await expect(flash(page)).toHaveText('Черновик сохранён');
  expect(auditActions().map((x) => x.action)).toEqual(['news.create', 'news.publish', 'news.update', 'news.update', 'news.unpublish', 'news.update']);
});

test('news-submit: пустой заголовок — 422, «Введите заголовок» у поля, вкладка «Русский», ничего не сохранено; ошибка в ҚАЗ — вкладка «Қазақша»', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  await tab(page, 'kk');
  await submit(page).click();
  await expect(page.getByTestId('form-error')).toHaveText('Исправьте ошибки в форме');
  await expect(panel(page, 'ru').getByTestId('news-field-error')).toHaveText('Введите заголовок');
  await expect(page.getByTestId('news-tab-ru')).toHaveAttribute('aria-selected', 'true');
  expect(newsCount()).toBe(0);
  await page.getByTestId('news-title-ru').fill('Нормальный');
  await tab(page, 'kk');
  await page.getByTestId('news-excerpt-kk').evaluate((el) => el.removeAttribute('maxlength'));
  await page.getByTestId('news-excerpt-kk').fill('ә'.repeat(310));
  await submit(page).click();
  await expect(page.getByTestId('news-tab-kk')).toHaveAttribute('aria-selected', 'true');
  await expect(panel(page, 'kk').getByTestId('news-field-error')).toHaveText('Не больше 300 символов');
  await expect(page.getByTestId('news-title-ru')).toHaveValue('Нормальный');
  expect(newsCount()).toBe(0);
});

test('news-submit: «Запланировать» с прошедшим временем — ошибка у даты; сессия истекла — вход, не сохранено; неверный CSRF — 400', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Запланированная в прошлом');
  await page.getByTestId('news-pub-later').check();
  await page.getByTestId('news-pub-date').fill('2026-11-23');
  await page.getByTestId('news-pub-time').fill('09:00');
  await submit(page).click();
  await expect(page.getByTestId('news-field-error')).toHaveText('Укажите будущие дату и время');
  await expect(page.getByTestId('news-pub-later')).toBeChecked();
  await expect(page.getByTestId('news-pub-date')).toHaveValue('2026-11-23');
  expect(newsCount()).toBe(0);
  // неверный CSRF
  const r = await postNews(page, '/admin/news', { title_ru: 'Без токена', publish_mode: 'now', intent: 'submit' }, { csrf: null });
  expect(r.status()).toBe(400);
  const r2 = await postNews(page, '/admin/news', { title_ru: 'Чужой токен', publish_mode: 'now', intent: 'submit' }, { csrf: 'a'.repeat(64) });
  expect(r2.status()).toBe(400);
  expect(newsCount()).toBe(0);
  // сессия истекла
  await page.getByTestId('news-pub-now').check();
  await ageSession(page, 7 * 3600);
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/login/);
  expect(newsCount()).toBe(0);
});

test('news-submit: двойной клик — одно сохранение', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Двойной клик');
  let posts = 0;
  page.on('request', (r) => { if (r.method() === 'POST' && /\/admin\/news(\/\d+)?$/.test(new URL(r.url()).pathname)) posts++; });
  await submit(page).dblclick();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(posts).toBe(1);
  expect(newsCount()).toBe(1);
  expect(auditActions().filter((a) => a.action === 'news.create')).toHaveLength(1);
});

test('news-tab-ru / news-tab-kk: переключение мышью и стрелками ←/→, данные не теряются; без JS обе панели видны', async ({ page, browser }) => {
  await login(page, OWNER);
  await openNew(page);
  const ru = page.getByTestId('news-tab-ru');
  const kk = page.getByTestId('news-tab-kk');
  await expect(ru).toHaveAttribute('role', 'tab');
  await expect(ru).toHaveAttribute('aria-selected', 'true');
  await expect(panel(page, 'kk')).toBeHidden();
  await page.getByTestId('news-title-ru').fill('Русский заголовок');
  await kk.click();
  await expect(panel(page, 'kk')).toBeVisible();
  await expect(panel(page, 'ru')).toBeHidden();
  await expect(kk).toHaveAttribute('aria-selected', 'true');
  await page.getByTestId('news-title-kk').fill('Қазақ');
  await ru.click();
  await expect(page.getByTestId('news-title-ru')).toHaveValue('Русский заголовок');
  await ru.focus();
  await page.keyboard.press('ArrowRight');
  await expect(kk).toBeFocused();
  await expect(panel(page, 'kk')).toBeVisible();
  await page.keyboard.press('ArrowLeft');
  await expect(ru).toBeFocused();
  await expect(panel(page, 'ru')).toBeVisible();
  await kk.click();
  await expect(page.getByTestId('news-title-kk')).toHaveValue('Қазақ');
  const ctx = await browser.newContext({ javaScriptEnabled: false });
  const p2 = await ctx.newPage();
  await login(p2, OWNER);
  await p2.goto('/admin/news/new');
  await expect(panel(p2, 'ru')).toBeVisible();
  await expect(panel(p2, 'kk')).toBeVisible();
  await ctx.close();
});

test('news-tab-ru-state: «заполнено», если RU-заголовок непуст; обновляется при вводе', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const st = page.getByTestId('news-tab-ru-state');
  await expect(st).toHaveText('не заполнено');
  await page.getByTestId('news-title-ru').fill('Есть');
  await expect(st).toHaveText('заполнено');
  await expect(st).toHaveClass(/lang-badge--ok/);
  await page.getByTestId('news-title-ru').fill('   ');
  await expect(st).toHaveText('не заполнено');
  await expect(st).toHaveClass(/lang-badge--warn/);
});

test('news-tab-kk-state: «заполнено» только если ҚАЗ заполнен И отличается от русского (как isTranslated); обновляется без сохранения', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const st = page.getByTestId('news-tab-kk-state');
  await expect(st).toHaveText('не заполнено');
  await expect(st).toHaveClass(/lang-badge--warn/);
  await fillRu(page, { title: 'Тест', body: 'текст' });
  await tab(page, 'kk');
  await page.getByTestId('news-title-kk').fill('Тест');
  await expect(st).toHaveText('не заполнено'); // нет текста при русском тексте
  await typeBody(page, 'kk', 'текст');
  await expect(st).toHaveText('не заполнено'); // копия русского
  await page.getByTestId('news-title-kk').fill('Тест ');
  await expect(st).toHaveText('не заполнено'); // пробел по краям не отличие
  await page.getByTestId('news-title-kk').fill('Сынақ');
  await expect(st).toHaveText('заполнено');
  await expect(st).toHaveClass(/lang-badge--ok/);
  await page.getByTestId('news-title-kk').fill('Тест');
  await body(page, 'kk').click();
  await page.keyboard.type('!');
  await expect(st).toHaveText('заполнено'); // текст отличается
  await page.getByTestId('news-title-kk').fill('');
  await expect(st).toHaveText('не заполнено');
});

test('news-title-ru: maxlength 120, slug подставляется через 400 мс, HTML сохраняется как текст, пустой — ошибка при сохранении', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const t = page.getByTestId('news-title-ru');
  await expect(t).toHaveAttribute('maxlength', '120');
  await expect(page.getByTestId('news-slug')).toHaveValue('');
  await t.fill('Осенний утренник в старшей группе');
  await expect(page.getByTestId('news-slug')).toHaveValue('osenniy-utrennik-v-starshey-gruppe');
  await t.fill('<b>Жирный</b> & "тест"');
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(newsRows()[0].title).toBe('<b>Жирный</b> & "тест"');
  await expect(page.getByTestId('news-row-title')).toHaveText('<b>Жирный</b> & "тест"');
  await openNew(page);
  await submit(page).click();
  await expect(page.getByTestId('news-field-error')).toHaveText('Введите заголовок');
  await expect(t).toHaveAttribute('aria-invalid', 'true');
  expect(newsCount()).toBe(1);
});

test('news-title-ru-counter: «N из 120 символов» обновляется при вводе', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const c = page.getByTestId('news-title-ru-counter');
  await expect(c).toHaveText('0 из 120 символов');
  await page.getByTestId('news-title-ru').fill('ж'.repeat(33));
  await expect(c).toHaveText('33 из 120 символов');
  await page.getByTestId('news-title-ru').click();
  await page.keyboard.press('End');
  await page.keyboard.type('з'.repeat(200));
  await expect(c).toHaveText('120 из 120 символов');
  await expect(page.getByTestId('news-title-ru')).toHaveValue(/^.{120}$/);
});

test('news-excerpt-ru: maxlength 300, подсказка про WhatsApp и Telegram, HTML как текст, > 300 в обход — ошибка у поля', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const ex = page.getByTestId('news-excerpt-ru');
  await expect(ex).toHaveAttribute('maxlength', '300');
  await expect(panel(page, 'ru')).toContainText('Показывается в списке новостей и в превью ссылки в WhatsApp и Telegram.');
  await page.getByTestId('news-title-ru').fill('С описанием');
  await ex.fill('<script>alert(1)</script> & текст');
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(newsRows()[0].excerpt).toBe('<script>alert(1)</script> & текст');
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Длинное');
  await ex.evaluate((el) => el.removeAttribute('maxlength'));
  await ex.fill('я'.repeat(301));
  await submit(page).click();
  await expect(panel(page, 'ru').getByTestId('news-field-error')).toHaveText('Не больше 300 символов');
  await expect(ex).toHaveAttribute('aria-invalid', 'true');
  expect(newsCount()).toBe(1);
});

test('news-body-ru: role=textbox, aria-multiline, aria-labelledby; синхронизация с textarea; вставка — как текст; HTML со script/onclick/style/iframe в БД не попадает; > 200 000 байт — ошибка', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const a = body(page);
  await expect(a).toHaveAttribute('role', 'textbox');
  await expect(a).toHaveAttribute('aria-multiline', 'true');
  await expect(a).toHaveAttribute('contenteditable', 'true');
  const labelId = await a.getAttribute('aria-labelledby');
  await expect(page.locator('#' + labelId)).toHaveText('Текст новости');
  await a.click();
  await page.keyboard.type('Первый абзац');
  await expect(page.locator('textarea[name="body_ru"]')).toHaveValue(/Первый абзац/);
  // вставка: форматирование не переносится, абзацы сохраняются, разметка — текстом
  await a.evaluate((el) => {
    const dt = new DataTransfer();
    dt.setData('text/plain', '<script>window.__pwn=1</script>\n\nВторой абзац\nс переносом');
    dt.setData('text/html', '<b style="color:red" onclick="window.__pwn=1">жирный</b>');
    el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
  });
  await expect(a).toContainText('<script>window.__pwn=1</script>');
  await expect(a).toContainText('Второй абзац');
  await expect(a.locator('script, b')).toHaveCount(0);
  await expect(a.locator('br')).not.toHaveCount(0);
  await page.getByTestId('news-title-ru').fill('Вставка');
  // попытка протащить опасный HTML мимо редактора
  await a.evaluate((el) => {
    el.innerHTML = '<p onclick="window.__pwn=1" style="x:y">чисто</p><script>window.__pwn=1</script><iframe src="//evil"></iframe><b>жир</b><a href="javascript:alert(1)">ссылка</a>';
    el.dispatchEvent(new Event('input', { bubbles: true }));
  });
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  const saved = newsRows()[0].body;
  expect(saved).toBe('<p>чисто</p><strong>жир</strong>ссылка');
  const id = lastNewsId();
  await page.goto(`/admin/news/${id}/preview`);
  expect(await page.evaluate(() => /** @type {any} */ (window).__pwn)).toBeUndefined();
  // > 200 000 байт
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Огромная');
  await body(page).evaluate((el) => { el.innerHTML = '<p>' + 'ж'.repeat(105000) + '</p>'; el.dispatchEvent(new Event('input', { bubbles: true })); });
  await submit(page).click();
  await expect(panel(page, 'ru').getByTestId('news-field-error')).toHaveText('Текст слишком большой');
  expect(newsCount()).toBe(1);
});

// ================================================================ тулбар редактора (в каждой панели свой)

const tb = (page, lang, name) => panel(page, lang).getByTestId(`news-tb-${name}`);

test('news-tb-bold: выделение → жирный (<strong> после сервера), aria-pressed; без выделения — для дальнейшего ввода; повторное нажатие снимает', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  await typeBody(page, 'ru', 'слово');
  await page.keyboard.press('Control+A');
  const b = tb(page, 'ru', 'bold');
  await expect(b).toHaveAttribute('aria-label', 'Жирный');
  await expect(b).toHaveAttribute('aria-pressed', 'false');
  await b.click();
  await expect(b).toHaveAttribute('aria-pressed', 'true');
  expect(await bodyHtml(page)).toMatch(/<(b|strong)>слово<\/(b|strong)>/);
  await b.click();
  await expect(b).toHaveAttribute('aria-pressed', 'false');
  expect(await bodyHtml(page)).not.toMatch(/<(b|strong)>/);
  // без выделения
  await page.keyboard.press('End');
  await b.click();
  await page.keyboard.type(' жир');
  expect(await bodyHtml(page)).toMatch(/<(b|strong)>(\s|&nbsp;)*жир<\/(b|strong)>/);
  await page.getByTestId('news-title-ru').fill('Жирный');
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(newsRows()[0].body).toContain('<strong>');
  expect(newsRows()[0].body).not.toContain('<b>');
});

test('news-tb-italic: выделение → курсив (<em> после сервера); повторно снимает', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  await typeBody(page, 'ru', 'курсив');
  await page.keyboard.press('Control+A');
  const b = tb(page, 'ru', 'italic');
  await expect(b).toHaveAttribute('aria-label', 'Курсив');
  await b.click();
  await expect(b).toHaveAttribute('aria-pressed', 'true');
  expect(await bodyHtml(page)).toMatch(/<(i|em)>курсив<\/(i|em)>/);
  await page.getByTestId('news-title-ru').fill('Курсив');
  await submit(page).click();
  expect(newsRows()[0].body).toContain('<em>курсив</em>');
  await openEdit(page, lastNewsId());
  await body(page).click();
  await page.keyboard.press('Control+A');
  await tb(page, 'ru', 'italic').click();
  expect(await bodyHtml(page)).not.toMatch(/<(i|em)>/);
});

test('news-tb-heading: абзац → <h2>, повторно → <p>; внутри списка ничего не делает', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  await typeBody(page, 'ru', 'Раздел');
  const h = tb(page, 'ru', 'heading');
  await expect(h).toHaveAttribute('aria-label', 'Заголовок раздела');
  await h.click();
  await expect(body(page).locator('h2')).toHaveText('Раздел');
  await expect(h).toHaveAttribute('aria-pressed', 'true');
  await h.click();
  await expect(body(page).locator('h2')).toHaveCount(0);
  await expect(body(page).locator('p')).toContainText('Раздел');
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await tb(page, 'ru', 'list').click();
  await page.keyboard.type('пункт');
  await h.click();
  await expect(body(page).locator('h2')).toHaveCount(0);
  await expect(body(page).locator('ul li')).toHaveText('пункт');
});

test('news-tb-list: маркированный список <ul><li>, повторно — обычные абзацы', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const l = tb(page, 'ru', 'list');
  await body(page).click();
  await l.click();
  await page.keyboard.type('один');
  await page.keyboard.press('Enter');
  await page.keyboard.type('два');
  await expect(body(page).locator('ul > li')).toHaveText(['один', 'два']);
  await expect(l).toHaveAttribute('aria-pressed', 'true');
  await page.keyboard.press('Control+A');
  await l.click();
  await expect(body(page).locator('ul')).toHaveCount(0);
  await expect(body(page)).toContainText('один');
  await expect(body(page)).toContainText('два');
});

test('news-tb-link: выделение → <a href>, внешним ссылкам сервер добавляет target/rel; без выделения — текст = адрес; site.kz → https://; пустой ввод снимает; javascript: — сообщение; отмена — ничего', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const link = tb(page, 'ru', 'link');
  const dialogs = [];
  // 1. выделение
  await typeBody(page, 'ru', 'сайт');
  await page.keyboard.press('Control+A');
  page.once('dialog', (d) => { dialogs.push(d.message()); return d.accept('https://example.com/page'); });
  await link.click();
  expect(dialogs[0]).toBe('Адрес ссылки (https://…)');
  await expect(body(page).locator('a')).toHaveAttribute('href', 'https://example.com/page');
  await expect(body(page).locator('a')).toHaveText('сайт');
  // 2. пустой ввод при курсоре на ссылке — снимает
  await body(page).locator('a').click();
  page.once('dialog', (d) => d.accept(''));
  await link.click();
  await expect(body(page).locator('a')).toHaveCount(0);
  // 3. без выделения: ссылка с текстом = адрес; адрес без схемы дополняется https://
  await page.keyboard.press('Control+End');
  await page.keyboard.press('Enter');
  page.once('dialog', (d) => d.accept('site.kz'));
  await link.click();
  await expect(body(page).locator('a')).toHaveAttribute('href', 'https://site.kz');
  await expect(body(page).locator('a')).toHaveText('site.kz');
  // 4. javascript: / data: — сообщение, ничего не вставлено
  const msgs = [];
  page.on('dialog', (d) => { msgs.push(d.type() + ':' + d.message()); return d.type() === 'prompt' ? d.accept('javascript:alert(1)') : d.accept(); });
  await page.keyboard.press('Control+End');
  await page.keyboard.press('Enter');
  await link.click();
  await expect.poll(() => msgs.some((m) => m === 'alert:Можно вставить только ссылку http, https или mailto')).toBe(true);
  await expect(body(page).locator('a')).toHaveCount(1);
  expect(await bodyHtml(page)).not.toContain('javascript');
  page.removeAllListeners('dialog');
  // 5. отмена
  page.once('dialog', (d) => d.dismiss());
  await link.click();
  await expect(body(page).locator('a')).toHaveCount(1);
  // на сервере
  await page.getByTestId('news-title-ru').fill('Ссылки');
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(newsRows()[0].body).toContain('<a href="https://site.kz" target="_blank" rel="noopener noreferrer">site.kz</a>');
});

test('news-tb-photo: окно медиатеки (множественный режим, вкладки), «Выбрать» вставляет <img data-media-id> в позицию курсора; «Отмена» — ничего; в конец, если курсор не в тексте; I-01', async ({ page }) => {
  await login(page, OWNER);
  const [a, b] = await photos(page, 2);
  await openNew(page);
  await typeBody(page, 'ru', 'Текст до');
  const photo = tb(page, 'ru', 'photo');
  await expect(photo).toHaveAttribute('aria-label', 'Вставить фото');
  await photo.click();
  const modal = page.getByTestId('media-picker-modal');
  await expect(modal).toBeVisible();
  await expect(page.getByTestId('media-picker-tab-library')).toBeVisible();
  await expect(page.getByTestId('media-picker-tab-upload')).toBeVisible();
  await page.getByTestId('media-picker-cancel').click();
  await expect(modal).toBeHidden();
  await expect(body(page).locator('img')).toHaveCount(0);
  await photo.click();
  await pickInModal(page, ['фото-1.jpg', 'фото-2.jpg']);
  const imgs = body(page).locator('img[data-media-id]');
  await expect(imgs).toHaveCount(2);
  for (const i of [0, 1]) await expect(imgs.nth(i)).toHaveAttribute('src', /.+/);
  expect(await imgs.evaluateAll((els) => els.map((e) => Number(e.getAttribute('data-media-id'))).sort())).toEqual([a, b].sort());
  await page.getByTestId('news-title-ru').fill('Фото в тексте');
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  const id = lastNewsId();
  expect(newsMedia(id).filter((m) => m.place === 'body').map((m) => m.media_id).sort()).toEqual([a, b].sort());
  await page.goto('/admin/media/' + a);
  await expect(page.getByTestId('media-usage-item')).toHaveText('Новость «Фото в тексте» (в тексте)');
  // курсор не в редакторе — в конец; удалили img и сохранили — использование снято
  await openEdit(page, id);
  await page.getByTestId('news-title-ru').click();
  await tb(page, 'ru', 'photo').click();
  await pickInModal(page, ['фото-1.jpg']);
  await expect(body(page).locator('img[data-media-id]')).toHaveCount(3);
  await body(page).evaluate((el) => { el.querySelectorAll('img').forEach((i) => i.remove()); el.dispatchEvent(new Event('input', { bubbles: true })); });
  await page.getByTestId('news-submit').click();
  expect(newsMedia(id).filter((m) => m.place === 'body')).toHaveLength(0);
  await page.goto('/admin/media/' + a);
  await expect(page.getByTestId('media-usages')).toContainText('нигде не используется');
});

// ================================================================ вкладка ҚАЗ

test('news-kk-hint: оранжевая плашка видна во вкладке ҚАЗ, пока перевод не «заполнен»', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Тест');
  await tab(page, 'kk');
  const hint = page.getByTestId('news-kk-hint');
  await expect(hint).toBeVisible();
  await expect(hint).toHaveText('Если не заполнить, на казахской версии сайта покажется русский текст с пометкой «Аударма жоқ».');
  await page.getByTestId('news-title-kk').fill('Тест');
  await expect(hint).toBeVisible(); // копия русского
  await page.getByTestId('news-title-kk').fill('Сынақ');
  await expect(hint).toBeHidden();
  await page.getByTestId('news-title-kk').fill('');
  await expect(hint).toBeVisible();
});

test('news-copy-from-ru: копирует заголовок, описание и текст с фото; бейдж остаётся «не заполнено»; при непустом KK — подтверждение; RU пуст — disabled', async ({ page }) => {
  await login(page, OWNER);
  const [p] = await photos(page, 1);
  await openNew(page);
  await tab(page, 'kk');
  const copy = page.getByTestId('news-copy-from-ru');
  await expect(copy).toBeDisabled();
  await tab(page, 'ru');
  await fillRu(page, { title: 'Русский заголовок', excerpt: 'Русское описание', body: 'Русский текст' });
  await tb(page, 'ru', 'photo').click();
  await pickInModal(page, ['фото-1.jpg']);
  await tab(page, 'kk');
  await expect(copy).toBeEnabled();
  await copy.click(); // KK пуст — без подтверждения
  await expect(page.getByTestId('news-title-kk')).toHaveValue('Русский заголовок');
  await expect(page.getByTestId('news-excerpt-kk')).toHaveValue('Русское описание');
  await expect(body(page, 'kk')).toContainText('Русский текст');
  await expect(body(page, 'kk').locator(`img[data-media-id="${p}"]`)).toHaveCount(1);
  await expect(page.getByTestId('news-tab-kk-state')).toHaveText('не заполнено');
  // KK непуст → confirm; отмена — ничего
  await page.getByTestId('news-title-kk').fill('Свой заголовок');
  let msg = '';
  page.once('dialog', (d) => { msg = d.message(); return d.dismiss(); });
  await copy.click();
  expect(msg).toBe('Заменить казахский текст русским?');
  await expect(page.getByTestId('news-title-kk')).toHaveValue('Свой заголовок');
  page.once('dialog', (d) => d.accept());
  await copy.click();
  await expect(page.getByTestId('news-title-kk')).toHaveValue('Русский заголовок');
  // форма помечена изменённой, копия сохраняется и остаётся «без перевода»
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  await expect(page.getByTestId('news-badge-kk')).toHaveText('ҚАЗ — нет перевода');
  expect(kkRow(lastNewsId()).title).toBe('Русский заголовок');
});

test('news-title-kk: placeholder «Тақырып», maxlength 120, сохраняется в news_translations, > 120 — ошибка', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  await tab(page, 'kk');
  const t = page.getByTestId('news-title-kk');
  await expect(t).toHaveAttribute('placeholder', 'Тақырып');
  await expect(t).toHaveAttribute('maxlength', '120');
  await expect(page.getByTestId('news-kk-hint')).toBeVisible();
  await tab(page, 'ru');
  await page.getByTestId('news-title-ru').fill('Рус');
  await tab(page, 'kk');
  await t.fill('Күзгі мереке');
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(kkRow(lastNewsId()).title).toBe('Күзгі мереке');
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Рус 2');
  await tab(page, 'kk');
  await t.evaluate((el) => el.removeAttribute('maxlength'));
  await t.fill('ә'.repeat(121));
  await submit(page).click();
  await expect(panel(page, 'kk').getByTestId('news-field-error')).toHaveText('Не больше 120 символов');
  await expect(page.getByTestId('news-tab-kk')).toHaveAttribute('aria-selected', 'true');
});

test('news-excerpt-kk: placeholder «Қысқаша сипаттама», сохраняется, > 300 — ошибка', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Рус');
  await tab(page, 'kk');
  const e = page.getByTestId('news-excerpt-kk');
  await expect(e).toHaveAttribute('placeholder', 'Қысқаша сипаттама');
  await e.fill('Қысқаша мәтін <b>x</b>');
  await submit(page).click();
  expect(kkRow(lastNewsId()).excerpt).toBe('Қысқаша мәтін <b>x</b>');
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Рус 2');
  await tab(page, 'kk');
  await e.evaluate((el) => el.removeAttribute('maxlength'));
  await e.fill('ә'.repeat(301));
  await submit(page).click();
  await expect(panel(page, 'kk').getByTestId('news-field-error')).toHaveText('Не больше 300 символов');
});

test('news-body-kk: свой тулбар в панели ҚАЗ, санитизируется как RU', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Рус');
  await tab(page, 'kk');
  for (const n of ['bold', 'italic', 'heading', 'list', 'link', 'photo']) await expect(tb(page, 'kk', n)).toBeVisible();
  await expect(panel(page, 'kk').getByRole('button', { name: /видео/i })).toHaveCount(0);
  await expect(body(page, 'kk')).toHaveAttribute('role', 'textbox');
  await typeBody(page, 'kk', 'Қалың');
  await page.keyboard.press('Control+A');
  await tb(page, 'kk', 'bold').click();
  await body(page, 'kk').evaluate((el) => {
    el.innerHTML += '<script>window.__pwn=1</script><p onclick="window.__pwn=1" style="color:red">таза</p><iframe src="//evil"></iframe>';
    el.dispatchEvent(new Event('input', { bubbles: true }));
  });
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(kkRow(lastNewsId()).body).toBe('<p><strong>Қалың</strong></p><p>таза</p>');
});

// ================================================================ фото к новости и обложка

const photosBlock = (page) => page.getByTestId('news-photos');
const coverBlock = (page) => page.getByTestId('news-cover');
const thumbs = (page) => photosBlock(page).getByTestId('media-picker-thumb');

test('news-photos: подпись, подсказка про порядок, плашка про сжатие; сохранённые фото в порядке sort; фото удалено из медиатеки между открытием и сохранением — ошибка у блока', async ({ page }) => {
  await login(page, OWNER);
  const [a, b, c] = await photos(page, 3);
  const id = await createNews(page, { title_ru: 'С фото', photos: [c, a, b] });
  await openEdit(page, id);
  await expect(photosBlock(page)).toContainText('Фото к новости');
  await expect(photosBlock(page)).toContainText('Перетащите, чтобы поменять порядок');
  await expect(photosBlock(page)).toContainText('Фото сжимаются автоматически. С них удаляется место съёмки.');
  expect(await formPhotoIds(page)).toEqual([c, a, b]);
  await expect(photosBlock(page).getByTestId('media-picker-field')).toHaveAttribute('data-sortable', '1');
  await expect(photosBlock(page).getByTestId('media-picker-field')).toHaveAttribute('data-multiple', '1');
  // фото-черновик: удалено из медиатеки до сохранения
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Исчезнувшее фото');
  const [d] = await photos(page, 1, 'исчезнувшее');
  await photosBlock(page).getByTestId('media-picker-open').click();
  await pickInModal(page, ['исчезнувшее-1.jpg']);
  expect(await formPhotoIds(page)).toEqual([d]);
  execSql(`DELETE FROM media WHERE id=${d}`);
  await submit(page).click();
  await expect(photosBlock(page).getByTestId('news-field-error')).toHaveText('Фото удалено из медиатеки — выберите другое');
  expect(newsCount()).toBe(1);
});

test('news-photos › media-picker-open: окно (множественный режим); выбранные добавляются в конец без дублей; > 50 — ошибка при сохранении', async ({ page }) => {
  await login(page, OWNER);
  const [a, b, c] = await photos(page, 3);
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Галерея');
  const open = photosBlock(page).getByTestId('media-picker-open');
  await expect(open).toHaveText('Добавить фото');
  await open.click();
  await expect(page.getByTestId('media-picker-confirm')).toBeVisible();
  await pickInModal(page, ['фото-2.jpg']);
  await open.click();
  await expect(page.locator('[data-testid="media-picker-item"][title="фото-2.jpg"]')).toHaveAttribute('aria-pressed', 'true');
  await pickInModal(page, ['фото-1.jpg', 'фото-3.jpg']);
  expect(await formPhotoIds(page)).toEqual([b, a, c]);
  await expect(thumbs(page)).toHaveCount(3);
  // > 50
  execSql(`INSERT INTO media (type, original_name, path, mime, variants, size) WITH RECURSIVE c(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM c WHERE i < 49)
    SELECT 'image', 'x' || i || '.jpg', 'images/x' || i, 'image/jpeg', '{}', 1 FROM c`);
  const extra = N.formKey();
  const ids = dbQuery('SELECT id FROM media ORDER BY id').map((r) => r.id);
  const r = await postNews(page, '/admin/news', { title_ru: 'Много', publish_mode: 'now', intent: 'submit', form_key: extra, photos: ids });
  expect(ids.length).toBe(52);
  expect(r.status()).toBe(422);
  expect(await r.text()).toContain('Не больше 50 фото');
  expect(newsCount()).toBe(0);
});

test('news-photos › media-picker-clear: «×» убирает фото из новости (после сохранения — из news_media), файл остаётся в медиатеке', async ({ page }) => {
  await login(page, OWNER);
  const [a, b] = await photos(page, 2);
  const id = await createNews(page, { title_ru: 'Две фото', photos: [a, b] });
  await openEdit(page, id);
  await photosBlock(page).getByTestId('media-picker-clear').first().click();
  expect(await formPhotoIds(page)).toEqual([b]);
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(newsMedia(id).map((m) => m.media_id)).toEqual([b]);
  expect(mediaRow(a)).toBeTruthy();
});

test('media-picker-thumb: миниатюры перетаскиваются, порядок скрытых photos[] меняется, в БД sort в новом порядке; в полях без sortable — не draggable', async ({ page }) => {
  await login(page, OWNER);
  const [a, b, c] = await photos(page, 3);
  const id = await createNews(page, { title_ru: 'Порядок', photos: [a, b, c] });
  await openEdit(page, id);
  await expect(thumbs(page)).toHaveCount(3);
  await expect(thumbs(page).first()).toHaveAttribute('draggable', 'true');
  await expect(coverBlock(page).getByTestId('media-picker-thumb')).toHaveCount(0);
  await thumbs(page).nth(0).dragTo(thumbs(page).nth(2));
  expect(await formPhotoIds(page)).toEqual([b, c, a]);
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(newsMedia(id).filter((m) => m.place === 'gallery').map((m) => m.media_id)).toEqual([b, c, a]);
  await page.goto('/admin/media');
  await expect(page.locator('[draggable="true"]')).toHaveCount(0);
});

test('media-picker-move-prev: «←» меняет местами с предыдущим, фокус остаётся на кнопке, у первого disabled', async ({ page }) => {
  await login(page, OWNER);
  const [a, b, c] = await photos(page, 3);
  const id = await createNews(page, { title_ru: 'Порядок', photos: [a, b, c] });
  await openEdit(page, id);
  const prev = (i) => thumbs(page).nth(i).getByTestId('media-picker-move-prev');
  await expect(prev(0)).toBeDisabled();
  await expect(prev(1)).toHaveAttribute('aria-label', 'Переместить раньше');
  await prev(2).click();
  expect(await formPhotoIds(page)).toEqual([a, c, b]);
  await expect(prev(1)).toBeFocused();
  await prev(1).press('Enter');
  expect(await formPhotoIds(page)).toEqual([c, a, b]);
  await expect(prev(0)).toBeDisabled();
  await submit(page).click();
  expect(newsMedia(id).filter((m) => m.place === 'gallery').map((m) => m.media_id)).toEqual([c, a, b]);
});

test('media-picker-move-next: «→» меняет местами со следующим, у последнего disabled', async ({ page }) => {
  await login(page, OWNER);
  const [a, b, c] = await photos(page, 3);
  const id = await createNews(page, { title_ru: 'Порядок', photos: [a, b, c] });
  await openEdit(page, id);
  const next = (i) => thumbs(page).nth(i).getByTestId('media-picker-move-next');
  await expect(next(2)).toBeDisabled();
  await expect(next(0)).toHaveAttribute('aria-label', 'Переместить позже');
  await next(0).click();
  expect(await formPhotoIds(page)).toEqual([b, a, c]);
  await expect(next(1)).toBeFocused();
  await next(1).click();
  expect(await formPhotoIds(page)).toEqual([b, c, a]);
  await expect(next(2)).toBeDisabled();
});

test('news-cover: «Фото не выбрано», выбранная обложка — превью, в списке и в предпросмотре', async ({ page }) => {
  await login(page, OWNER);
  const [a] = await photos(page, 1);
  await openNew(page);
  await expect(coverBlock(page)).toContainText('Обложка');
  await expect(coverBlock(page).getByTestId('media-picker-preview')).toContainText('Фото не выбрано');
  await expect(coverBlock(page).getByTestId('media-picker-field')).toHaveAttribute('data-multiple', '0');
  await page.getByTestId('news-title-ru').fill('С обложкой');
  await coverBlock(page).getByTestId('media-picker-open').click();
  await pickInModal(page, ['фото-1.jpg']);
  await expect(coverBlock(page).getByTestId('media-picker-preview').locator('img')).toHaveCount(1);
  await submit(page).click();
  await expect(page.getByTestId('news-row-thumb').locator('picture')).toHaveCount(1);
  const id = lastNewsId();
  expect(newsRow(id).cover_media_id).toBe(a);
  await page.goto(`/admin/news/${id}/preview`);
  await expect(page.locator('#pv-ru .pv-cover picture')).toHaveCount(1);
});

test('news-cover › media-picker-open: одиночный режим, вкладка «Загрузить» тоже работает, подпись форматов; после сохранения — «используется: обложка»; замена освобождает старое фото', async ({ page }) => {
  await login(page, OWNER);
  const [old] = await photos(page, 1);
  const id = await createNews(page, { title_ru: 'Обложка', cover_media_id: old });
  await openEdit(page, id);
  await expect(coverBlock(page)).toContainText('JPG, PNG, WebP, GIF, HEIC');
  await expect(coverBlock(page).getByTestId('media-picker-open')).toHaveText('Выбрать фото');
  await coverBlock(page).getByTestId('media-picker-open').click();
  await page.getByTestId('media-picker-tab-upload').click();
  await page.getByTestId('media-file-input').setInputFiles(fx('tiny.png'));
  await expect(page.getByTestId('media-queue-clear')).toBeEnabled({ timeout: 60000 });
  await page.getByTestId('media-picker-tab-library').click();
  await expect(page.getByTestId('media-picker-item').and(page.locator('[aria-pressed=true]'))).toHaveCount(1);
  await page.getByTestId('media-picker-confirm').click();
  const newCover = Number(await coverBlock(page).locator('input[name="cover_media_id"]').inputValue());
  expect(newCover).not.toBe(old);
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(newsRow(id).cover_media_id).toBe(newCover);
  await page.goto('/admin/media/' + newCover);
  await expect(page.getByTestId('media-usage-item')).toHaveText('Новость «Обложка» (обложка)');
  await page.goto('/admin/media/' + old);
  await expect(page.getByTestId('media-usages')).toContainText('нигде не используется');
  await expect(page.getByTestId('media-delete')).toBeEnabled();
});

test('news-cover › media-picker-clear: «Убрать» снимает обложку (cover_media_id=NULL после сохранения)', async ({ page }) => {
  await login(page, OWNER);
  const [a] = await photos(page, 1);
  const id = await createNews(page, { title_ru: 'Обложка', cover_media_id: a });
  await openEdit(page, id);
  await coverBlock(page).getByTestId('media-picker-clear').click();
  await expect(coverBlock(page).getByTestId('media-picker-preview')).toContainText('Фото не выбрано');
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(newsRow(id).cover_media_id).toBeNull();
  expect(mediaRow(a)).toBeTruthy();
});

// ================================================================ публикация

test('news-pub-current: у новой нет; «Сейчас: Черновик» / «Опубликована 18.11.2026 в 10:00» / «Запланирована на 25.11.2026 в 09:00»', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, '2026-11-18 05:00:00');
  await openNew(page);
  await expect(page.getByTestId('news-pub-current')).toHaveCount(0);
  const draft = await createNews(page, { title_ru: 'Ч', publish_mode: 'draft' });
  const pub = await createNews(page, { title_ru: 'О' });
  const sch = await createScheduled(page, '2026-11-25', '09:00', { title_ru: 'З' });
  await openEdit(page, draft);
  await expect(page.getByTestId('news-pub-current')).toHaveText('Сейчас: Черновик');
  await openEdit(page, pub);
  await expect(page.getByTestId('news-pub-current')).toHaveText('Опубликована 18.11.2026 в 10:00');
  await openEdit(page, sch);
  await expect(page.getByTestId('news-pub-current')).toHaveText('Запланирована на 25.11.2026 в 09:00');
  await setClock(page, '2026-11-25 04:00:00');
  await openEdit(page, sch);
  await expect(page.getByTestId('news-pub-current')).toHaveText('Опубликована 25.11.2026 в 09:00');
});

test('news-pub-draft: радиокнопка «Черновик — Видно только в админке», меняет текст news-submit, скрывает поля даты', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const r = page.getByTestId('news-pub-draft');
  await expect(r).toHaveAttribute('type', 'radio');
  await expect(page.locator('label').filter({ has: r })).toContainText('Видно только в админке');
  await page.getByTestId('news-pub-later').check();
  await expect(page.getByTestId('news-pub-date')).toBeVisible();
  await r.check();
  await expect(submit(page)).toHaveText('Сохранить черновик');
  await expect(page.getByTestId('news-pub-date')).toBeHidden();
  await expect(page.getByTestId('news-pub-time')).toBeHidden();
  await page.getByTestId('news-title-ru').fill('Только черновик');
  await submit(page).click();
  expect(newsRows()[0].status).toBe('draft');
});

test('news-pub-now: по умолчанию у новой; «Опубликовать» / «Сохранить изменения»; у запланированной при выборе публикуется сейчас', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await openNew(page);
  const r = page.getByTestId('news-pub-now');
  await expect(r).toBeChecked();
  await expect(page.locator('label').filter({ has: r })).toContainText('Появится на сайте после нажатия «Опубликовать»');
  await expect(submit(page)).toHaveText('Опубликовать');
  const sch = await createScheduled(page, '2026-11-30', '09:00', { title_ru: 'Запланированная' });
  await setClock(page, '2026-11-25 06:00:00');
  await openEdit(page, sch);
  await expect(page.getByTestId('news-pub-later')).toBeChecked();
  await r.check();
  await expect(submit(page)).toHaveText('Опубликовать');
  await submit(page).click();
  await expect(flash(page)).toHaveText('Новость опубликована');
  expect(newsRow(sch).published_at).toBe('2026-11-25 06:00:00');
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-row-status')).toHaveText('Опубликована');
});

test('news-pub-later: появляются дата, время, подсказка пояса; текст кнопки «Запланировать»; без JS поля даты видны всегда', async ({ page, browser }) => {
  await login(page, OWNER);
  await openNew(page);
  await expect(page.getByTestId('news-pub-date')).toBeHidden();
  await page.getByTestId('news-pub-later').check();
  await expect(page.getByTestId('news-pub-date')).toBeVisible();
  await expect(page.getByTestId('news-pub-time')).toBeVisible();
  await expect(page.getByTestId('news-pub-tz')).toBeVisible();
  await expect(submit(page)).toHaveText('Запланировать');
  await expect(page.locator('label').filter({ has: page.getByTestId('news-pub-later') })).toContainText('Опубликуется сама в нужный день');
  const ctx = await browser.newContext({ javaScriptEnabled: false });
  const p2 = await ctx.newPage();
  await login(p2, OWNER);
  await p2.goto('/admin/news/new');
  await expect(p2.getByTestId('news-pub-date')).toBeVisible();
  await expect(p2.getByTestId('news-pub-time')).toBeVisible();
  await ctx.close();
});

test('news-pub-date: type=date, min = сегодня по поясу (в т.ч. после полуночи по Алматы); пусто/неверно при «Запланировать» — ошибка', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, '2026-11-24 18:59:00'); // 23:59 24.11 по Алматы
  await openNew(page);
  const d = page.getByTestId('news-pub-date');
  await expect(d).toHaveAttribute('type', 'date');
  await expect(d).toHaveAttribute('min', '2026-11-24');
  await setClock(page, '2026-11-24 19:00:00'); // 00:00 25.11 по Алматы
  await openNew(page);
  await expect(page.getByTestId('news-pub-date')).toHaveAttribute('min', '2026-11-25');
  await page.getByTestId('news-title-ru').fill('Без даты');
  await page.getByTestId('news-pub-later').check();
  await submit(page).click();
  await expect(page.getByTestId('news-field-error')).toHaveText('Укажите будущие дату и время');
  await expect(page.getByTestId('news-pub-date')).toHaveAttribute('aria-invalid', 'true');
  expect(newsCount()).toBe(0);
  const r = await postNews(page, '/admin/news', { title_ru: 'Кривая дата', publish_mode: 'later', publish_date: '2026-02-30', publish_time: '09:00', intent: 'submit' });
  expect(r.status()).toBe(422);
  expect(newsCount()).toBe(0);
});

test('news-pub-time: по умолчанию 09:00, сохраняется в UTC, показывается в поясе; прошло к моменту сохранения — ошибка; наступившее время делает новость опубликованной без cron', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await openNew(page);
  const t = page.getByTestId('news-pub-time');
  await expect(t).toHaveAttribute('type', 'time');
  await page.getByTestId('news-pub-later').check();
  await expect(t).toHaveValue('09:00');
  await page.getByTestId('news-title-ru').fill('По расписанию');
  await page.getByTestId('news-pub-date').fill('2026-11-25');
  await submit(page).click();
  const id = lastNewsId();
  expect(newsRow(id).published_at).toBe('2026-11-25 04:00:00');
  await expect(page.getByTestId('news-row-meta')).toContainText('25.11.2026, 09:00');
  await expect(page.getByTestId('news-row-status')).toHaveText('Запланирована');
  await setClock(page, '2026-11-25 03:59:59');
  await page.reload();
  await expect(page.getByTestId('news-row-status')).toHaveText('Запланирована');
  await setClock(page, '2026-11-25 04:00:00');
  await page.reload();
  await expect(page.getByTestId('news-row-status')).toHaveText('Опубликована');
  await expect(page.getByTestId('news-filter-published')).toContainText('1');
  // время прошло к моменту сохранения
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Опоздала');
  await page.getByTestId('news-pub-later').check();
  await page.getByTestId('news-pub-date').fill('2026-11-25');
  await t.fill('09:00'); // 09:00 по Алматы = 04:00 UTC = «сейчас» → не строго позже
  await submit(page).click();
  await expect(page.getByTestId('news-field-error')).toHaveText('Укажите будущие дату и время');
});

test('news-pub-tz: подсказка с поясом и смещением из config(timezone); смена пояса меняет подсказку и показ, но не сохранённый UTC', async ({ page }) => {
  resetApp({ installed: true, withEditor: true, debug: false });
  await login(page, OWNER);
  await setClock(page, NOW);
  const id = await createScheduled(page, '2026-11-25', '09:00', { title_ru: 'Пояс' });
  await openNew(page);
  await page.getByTestId('news-pub-later').check();
  await expect(page.getByTestId('news-pub-tz')).toHaveText('Время по часовому поясу Asia/Almaty (UTC+05:00)');
  const cfgFile = require('path').join(require('./helpers').STORAGE, 'config.php');
  const fs = require('fs');
  const cfg = fs.readFileSync(cfgFile, 'utf8');
  expect(cfg).toContain("'timezone' => 'Asia/Almaty'");
  fs.writeFileSync(cfgFile, cfg.replace("'timezone' => 'Asia/Almaty'", "'timezone' => 'Europe/Moscow'"));
  await openNew(page);
  await page.getByTestId('news-pub-later').check();
  await expect(page.getByTestId('news-pub-tz')).toHaveText('Время по часовому поясу Europe/Moscow (UTC+03:00)');
  expect(newsRow(id).published_at).toBe('2026-11-25 04:00:00');
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-row-meta')).toContainText('25.11.2026, 07:00');
  await openEdit(page, id);
  await expect(page.getByTestId('news-pub-time')).toHaveValue('07:00');
});

// ================================================================ адрес страницы

test('news-slug: ручная правка нормализуется после потери фокуса, перестаёт зависеть от заголовка; занят — сообщение и ошибка при сохранении; очистка — снова автоматически', async ({ page }) => {
  await login(page, OWNER);
  await createNews(page, { title_ru: 'Занято' });
  await openNew(page);
  const s = page.getByTestId('news-slug');
  await page.getByTestId('news-title-ru').fill('Заголовок');
  await expect(s).toHaveValue('zagolovok');
  await s.fill('Мой Адрес!');
  await s.blur();
  await expect(s).toHaveValue('moy-adres');
  await page.getByTestId('news-title-ru').fill('Совсем другой заголовок');
  await expect(page.getByTestId('news-slug-preview')).toContainText('moy-adres');
  await expect(s).toHaveValue('moy-adres'); // slug_auto=0
  await s.fill('zanyato');
  await s.blur();
  await expect(page.getByTestId('news-field-error')).toHaveText('Этот адрес уже занят новостью «Занято»');
  await submit(page).click();
  await expect(page.getByTestId('news-field-error')).toHaveText('Этот адрес уже занят новостью «Занято»');
  await expect(s).toHaveAttribute('aria-invalid', 'true');
  expect(newsCount()).toBe(1);
  await s.fill('');
  await s.blur();
  await expect(s).toHaveValue('sovsem-drugoy-zagolovok');
  await submit(page).click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  expect(newsRows()[1].slug).toBe('sovsem-drugoy-zagolovok');
});

test('news-slug-preview: «хост/novosti/slug» текстом (не ссылка), обновляется вместе со slug, длинный адрес переносится', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  const pv = page.getByTestId('news-slug-preview');
  await expect(pv).toHaveText('127.0.0.1:8080/novosti/');
  expect(await pv.evaluate((el) => el.tagName.toLowerCase() !== 'a' && !el.closest('a'))).toBe(true);
  await page.getByTestId('news-title-ru').fill('Осенний утренник');
  await expect(pv).toHaveText('127.0.0.1:8080/novosti/osenniy-utrennik');
  await page.getByTestId('news-slug').fill('a'.repeat(80));
  await expect(pv).toHaveText('127.0.0.1:8080/novosti/' + 'a'.repeat(80));
  expect(await pv.evaluate((el) => getComputedStyle(el).wordBreak)).toBe('break-all');
  await page.setViewportSize({ width: 360, height: 700 });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('news-slug-hint: «Создаётся из заголовка автоматически.»; у опубликованной при смене адреса — оранжевое предупреждение', async ({ page }) => {
  await login(page, OWNER);
  await openNew(page);
  await expect(page.getByTestId('news-slug-hint')).toHaveText('Создаётся из заголовка автоматически.');
  const id = await createNews(page, { title_ru: 'Опубликованная' });
  await openEdit(page, id);
  const h = page.getByTestId('news-slug-hint');
  await expect(h).toHaveText('Создаётся из заголовка автоматически.');
  await page.getByTestId('news-slug').fill('novyy-adres');
  await expect(h).toHaveText('После смены адреса старая ссылка перестанет работать.');
  await expect(h).toHaveClass(/is-warn/);
  await page.getByTestId('news-slug').fill('opublikovannaya');
  await expect(h).toHaveText('Создаётся из заголовка автоматически.');
});

// ================================================================ удаление и ошибки

test('news-delete: нет у новой несохранённой; появляется после автосохранения; confirm; удаление с тостом и журналом; отмена — ничего', async ({ page }) => {
  await login(page, OWNER);
  await fastAutosave(page);
  await openNew(page);
  await expect(page.getByTestId('news-delete')).toBeHidden();
  await page.getByTestId('news-title-ru').fill('Будет удалена');
  await expect(page.getByTestId('news-delete')).toBeVisible();
  await expect(page.getByTestId('news-delete')).toHaveText('Удалить новость');
  const id = lastNewsId();
  let msg = '';
  page.once('dialog', (d) => { msg = d.message(); return d.dismiss(); });
  await page.getByTestId('news-delete').click();
  expect(msg).toBe('Удалить новость «Будет удалена»? Восстановить её будет нельзя.');
  expect(newsRow(id)).toBeTruthy();
  await expect(page).toHaveURL(new RegExp(`/admin/news/${id}/edit$`));
  page.once('dialog', (d) => d.accept());
  await page.getByTestId('news-delete').click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  await expect(flash(page)).toHaveText('Новость удалена');
  expect(newsCount()).toBe(0);
  expect(auditActions().map((a) => a.action)).toEqual(['news.create', 'news.delete']);
  // у сохранённой — сразу
  const other = await createNews(page, { title_ru: 'Сохранённая' });
  await openEdit(page, other);
  await expect(page.getByTestId('news-delete')).toBeVisible();
});

test('news-field-error: ошибки из 422 у своих полей с aria-invalid/aria-describedby, «Исправьте ошибки в форме» вверху; введённые данные (фото, порядок, режим, вкладка) сохранены', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  const [a, b] = await photos(page, 2);
  await openNew(page);
  await tab(page, 'kk');
  await page.getByTestId('news-title-kk').fill('Қазақша тақырып');
  await tab(page, 'ru');
  await page.getByTestId('news-excerpt-ru').evaluate((el) => el.removeAttribute('maxlength'));
  await page.getByTestId('news-excerpt-ru').fill('я'.repeat(301));
  await photosBlock(page).getByTestId('media-picker-open').click();
  await pickInModal(page, ['фото-2.jpg', 'фото-1.jpg']);
  await photosBlock(page).getByTestId('media-picker-thumb').first().getByTestId('media-picker-move-next').click();
  const order = await formPhotoIds(page);
  await page.getByTestId('news-pub-later').check();
  await page.getByTestId('news-pub-date').fill('2026-11-20');
  await submit(page).click();
  await expect(page.getByTestId('form-error')).toHaveText('Исправьте ошибки в форме');
  const errs = page.getByTestId('news-field-error');
  await expect(errs).toHaveCount(3); // заголовок, описание, дата
  for (const [testid, text] of [['news-title-ru', 'Введите заголовок'], ['news-excerpt-ru', 'Не больше 300 символов'], ['news-pub-date', 'Укажите будущие дату и время']]) {
    const el = page.getByTestId(testid);
    await expect(el).toHaveAttribute('aria-invalid', 'true');
    const ids = (await el.getAttribute('aria-describedby')).split(' ').filter((x) => x.startsWith('err-'));
    expect(ids.length).toBe(1);
    await expect(page.locator('#' + ids[0])).toHaveText(text);
  }
  expect(await formPhotoIds(page)).toEqual(order);
  expect(new Set(order)).toEqual(new Set([a, b]));
  await expect(page.getByTestId('news-pub-later')).toBeChecked();
  await expect(page.getByTestId('news-pub-date')).toHaveValue('2026-11-20');
  await expect(page.getByTestId('news-excerpt-ru')).toHaveValue('я'.repeat(301));
  await tab(page, 'kk');
  await expect(page.getByTestId('news-title-kk')).toHaveValue('Қазақша тақырып');
  expect(newsCount()).toBe(0);
  // исправили — ошибка пропадает при правке поля
  await tab(page, 'ru');
  await page.getByTestId('news-title-ru').fill('Исправлено');
  await expect(panel(page, 'ru').locator('[data-field="title_ru"] [data-testid="news-field-error"]')).toHaveCount(0);
});
