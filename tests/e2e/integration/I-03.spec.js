// @ts-check
// I-03 (часть 04): запросы модели для публичного сайта — только status='published' AND published_at <= now.
// Здесь проверяется через вычисляемое состояние в админке и подмену времени (/__test/clock); сами публичные страницы /novosti — тест фичи 11.
const { test, expect } = require('@playwright/test');
const { resetApp, login, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { OWNER } = require('../auth-helpers');
const { NOW, setClock, createNews, createScheduled } = require('../news-helpers');

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d|net::ERR_FAILED/ });
});
test.afterEach(() => expectNoPageErrors());

const titles = (page) => page.getByTestId('news-row-title').allInnerTexts();

test('I-03: «опубликованной» считается только вышедшая: черновик и будущая запланированная в фильтре «Опубликованы» не показываются', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await createNews(page, { title_ru: 'Вышла' });
  await createNews(page, { title_ru: 'Черновик', publish_mode: 'draft' });
  await createNews(page, { title_ru: 'Автосохранённый', intent: 'draft' });
  await createScheduled(page, '2026-11-25', '09:00', { title_ru: 'Скоро' });
  await page.goto('/admin/news?status=published');
  expect(await titles(page)).toEqual(['Вышла']);
  await page.goto('/admin/news?status=scheduled');
  expect(await titles(page)).toEqual(['Скоро']);
  await page.goto('/admin/news?status=draft');
  expect((await titles(page)).sort()).toEqual(['Автосохранённый', 'Черновик']);
});

test('I-03: после наступления даты (подмена Clock) запланированная становится опубликованной без cron и без сохранения', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  await createScheduled(page, '2026-11-25', '09:00', { title_ru: 'По расписанию' });
  for (const [t, state, pub] of [['2026-11-25 03:59:59', 'Запланирована', 0], ['2026-11-25 04:00:00', 'Опубликована', 1], ['2026-11-30 00:00:00', 'Опубликована', 1]]) {
    await setClock(page, t);
    await page.goto('/admin/news');
    await expect(page.getByTestId('news-row-status')).toHaveText(state);
    await expect(page.getByTestId('news-filter-published')).toContainText(String(pub));
    await expect(page.getByTestId('news-filter-scheduled')).toContainText(String(1 - pub));
  }
});

test('I-03: снятие с публикации возвращает в черновики и убирает из «Опубликованы»; повторная публикация ставит новую дату', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, '2026-11-20 00:00:00');
  const id = await createNews(page, { title_ru: 'Туда-сюда' });
  await page.goto(`/admin/news/${id}/edit`);
  await page.getByTestId('news-pub-draft').check();
  await page.getByTestId('news-submit').click();
  await expect(page.getByTestId('news-row-status')).toHaveText('Черновик');
  await page.goto('/admin/news?status=published');
  await expect(page.getByTestId('news-empty')).toBeVisible();
  await setClock(page, '2026-11-22 00:00:00');
  await page.goto(`/admin/news/${id}/edit`);
  await page.getByTestId('news-pub-now').check();
  await page.getByTestId('news-submit').click();
  await expect(page.getByTestId('news-row-meta')).toContainText('22.11.2026');
});
