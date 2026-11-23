// @ts-check
// I-10 (04): бейджи «ҚАЗ — нет перевода» в списке, бейдж вкладки в редакторе и News::translationStats() — одно правило isTranslated():
// заполнено И отличается от русского. Блок «переведено N из M» на главной — фича 08 (в 04 — PHPUnit translationStats()).
const { test, expect } = require('@playwright/test');
const { resetApp, login, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { OWNER } = require('../auth-helpers');
const { createNews, openEdit, tab, typeBody, kkRow, setClock, NOW } = require('../news-helpers');

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d|net::ERR_FAILED/ });
});
test.afterEach(() => expectNoPageErrors());

const CASES = [
  { title_ru: 'Без казахского', body_ru: '<p>р</p>', ready: false },
  { title_ru: 'Только заголовок', body_ru: '<p>р</p>', title_kk: 'Тақырып', ready: false },
  { title_ru: 'Полный перевод', body_ru: '<p>р</p>', title_kk: 'Толық', body_kk: '<p>қ</p>', ready: true },
  { title_ru: 'Копия', body_ru: '<p>р</p>', title_kk: 'Копия', body_kk: '<p>р</p>', ready: false },
  { title_ru: 'Тот же заголовок', body_ru: '<p>р</p>', title_kk: 'Тот же заголовок', body_kk: '<p>қ</p>', ready: true },
  { title_ru: 'Без текста RU', title_kk: 'Тек тақырып', ready: true },
];

test('I-10: бейдж списка, бейдж вкладки редактора и подсчёт зелёных бейджей считаются по одному правилу из реальных данных', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  const ids = {};
  for (const c of CASES) ids[c.title_ru] = await createNews(page, c);
  await page.goto('/admin/news');
  for (const c of CASES) {
    const row = page.getByTestId('news-row').filter({ hasText: c.title_ru });
    await expect(row.getByTestId('news-badge-kk'), c.title_ru).toHaveText(c.ready ? 'ҚАЗ' : 'ҚАЗ — нет перевода');
    await expect(row.getByTestId('news-badge-kk'), c.title_ru).toHaveClass(c.ready ? /lang-badge--ok/ : /lang-badge--warn/);
  }
  const green = CASES.filter((c) => c.ready).length;
  await expect(page.locator('[data-testid="news-badge-kk"].lang-badge--ok')).toHaveCount(green);
  await expect(page.locator('[data-testid="news-badge-kk"].lang-badge--warn')).toHaveCount(CASES.length - green);
  // редактор той же новости показывает то же
  for (const c of CASES) {
    await openEdit(page, ids[c.title_ru]);
    await expect(page.getByTestId('news-tab-kk-state'), c.title_ru).toHaveText(c.ready ? 'заполнено' : 'не заполнено');
  }
});

test('I-10: заполнение, очистка и копирование ҚАЗ сразу меняют бейдж в списке', async ({ page }) => {
  await login(page, OWNER);
  const id = await createNews(page, { title_ru: 'Динамика', body_ru: '<p>рус</p>' });
  const badge = () => page.getByTestId('news-badge-kk');
  const save = async () => { await page.getByTestId('news-submit').click(); await expect(page).toHaveURL(/\/admin\/news$/); };
  await page.goto('/admin/news');
  await expect(badge()).toHaveText('ҚАЗ — нет перевода');
  await openEdit(page, id);
  await tab(page, 'kk');
  await page.getByTestId('news-title-kk').fill('Динамика қаз');
  await typeBody(page, 'kk', 'қазақша мәтін');
  await expect(page.getByTestId('news-tab-kk-state')).toHaveText('заполнено'); // до сохранения
  await save();
  await expect(badge()).toHaveText('ҚАЗ');
  await openEdit(page, id);
  await tab(page, 'kk');
  await page.getByTestId('news-title-kk').fill('');
  await expect(page.getByTestId('news-tab-kk-state')).toHaveText('не заполнено');
  await save();
  await expect(badge()).toHaveText('ҚАЗ — нет перевода');
  await openEdit(page, id);
  await tab(page, 'kk');
  await page.getByTestId('news-title-kk').fill('Динамика');
  await typeBody(page, 'kk', 'x');
  page.once('dialog', (d) => d.accept());
  await page.getByTestId('news-copy-from-ru').click();
  await save();
  await expect(badge()).toHaveText('ҚАЗ — нет перевода');
  expect(kkRow(id).title).toBe('Динамика');
});
