// @ts-check
// I-29: состояние draft/scheduled/published вычисляется одной функцией из published_at (UTC) и Clock::nowUtc() без cron;
// ввод и показ дат — в config('timezone'); запланированная становится опубликованной везде одновременно.
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { resetApp, login, STORAGE, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { OWNER } = require('../auth-helpers');
const { NOW, setClock, createNews, createScheduled, newsRow, openEdit, flash } = require('../news-helpers');

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d|net::ERR_FAILED/ });
});
test.afterEach(() => expectNoPageErrors());

/** Состояние новости на всех экранах 04: список, фильтры, редактор, предпросмотр. */
async function snapshot(page, id) {
  await page.goto('/admin/news');
  const row = page.getByTestId('news-row').first();
  const list = await row.getByTestId('news-row-status').innerText();
  const meta = await row.getByTestId('news-row-meta').innerText();
  const counts = {
    published: await page.getByTestId('news-filter-published').innerText(),
    scheduled: await page.getByTestId('news-filter-scheduled').innerText(),
  };
  await openEdit(page, id);
  const current = await page.getByTestId('news-pub-current').innerText();
  const mode = await page.locator('[name="publish_mode"]:checked').getAttribute('data-testid');
  const labels = await page.getByTestId('news-submit').innerText();
  await page.goto(`/admin/news/${id}/preview`);
  const date = await page.locator('#pv-ru .pv-date').innerText();
  return { list, meta, counts, current, mode, labels, date };
}

test('I-29: 25.11.2026 09:00 по Алматы = 04:00 UTC в БД; в момент наступления даты список, фильтры, редактор и предпросмотр меняются одновременно', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, NOW);
  const id = await createScheduled(page, '2026-11-25', '09:00', { title_ru: 'Граница' });
  expect(newsRow(id).published_at).toBe('2026-11-25 04:00:00');

  await setClock(page, '2026-11-25 03:59:59');
  const before = await snapshot(page, id);
  expect(before.list).toBe('Запланирована');
  expect(before.meta).toContain('25.11.2026, 09:00');
  expect(before.counts.published).toContain('0');
  expect(before.counts.scheduled).toContain('1');
  expect(before.current).toBe('Запланирована на 25.11.2026 в 09:00');
  expect(before.mode).toBe('news-pub-later');
  expect(before.labels).toBe('Сохранить изменения');
  expect(before.date).toBe('25.11.2026, 09:00');

  await setClock(page, '2026-11-25 04:00:00');
  const after = await snapshot(page, id);
  expect(after.list).toBe('Опубликована');
  expect(after.meta).toContain('25.11.2026');
  expect(after.meta).not.toContain('09:00');
  expect(after.counts.published).toContain('1');
  expect(after.counts.scheduled).toContain('0');
  expect(after.current).toBe('Опубликована 25.11.2026 в 09:00');
  expect(after.mode).toBe('news-pub-now');
  expect(after.date).toBe('25.11.2026');
  expect(newsRow(id).published_at).toBe('2026-11-25 04:00:00'); // сохранение не требовалось
});

test('I-29: граница суток по Алматы — полночь: 00:00 25.11 = 19:00 UTC 24.11; поле min даты и дата в списке переходят вместе', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, '2026-11-24 12:00:00');
  const id = await createScheduled(page, '2026-11-25', '00:00', { title_ru: 'Полночь' });
  expect(newsRow(id).published_at).toBe('2026-11-24 19:00:00');
  await setClock(page, '2026-11-24 18:59:59');
  await page.goto('/admin/news/new');
  await expect(page.getByTestId('news-pub-date')).toHaveAttribute('min', '2026-11-24');
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-row-status')).toHaveText('Запланирована');
  await setClock(page, '2026-11-24 19:00:00');
  await page.goto('/admin/news/new');
  await expect(page.getByTestId('news-pub-date')).toHaveAttribute('min', '2026-11-25');
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-row-status')).toHaveText('Опубликована');
  await expect(page.getByTestId('news-row-meta')).toContainText('25.11.2026');
});

test('I-29: «Опубликовать сразу» ставит дату по Clock и показывает её в поясе сайта; повторное сохранение дату не меняет', async ({ page }) => {
  await login(page, OWNER);
  await setClock(page, '2026-11-18 05:00:00');
  const id = await createNews(page, { title_ru: 'Сразу' });
  expect(newsRow(id).published_at).toBe('2026-11-18 05:00:00');
  await openEdit(page, id);
  await expect(page.getByTestId('news-pub-current')).toHaveText('Опубликована 18.11.2026 в 10:00');
  await setClock(page, '2026-11-20 05:00:00');
  await page.getByTestId('news-title-ru').fill('Сразу (правка)');
  await page.getByTestId('news-submit').click();
  expect(newsRow(id).published_at).toBe('2026-11-18 05:00:00');
});

test('I-29: смена config(timezone) меняет показ и ввод, но не сохранённое UTC; Clock одинаков для сохранения и проверки «в будущем»', async ({ page }) => {
  resetApp({ installed: true, withEditor: true, debug: false });
  await login(page, OWNER);
  await setClock(page, NOW);
  const id = await createScheduled(page, '2026-11-25', '09:00', { title_ru: 'Часовой пояс' });
  const cfgFile = path.join(STORAGE, 'config.php');
  fs.writeFileSync(cfgFile, fs.readFileSync(cfgFile, 'utf8').replace("'timezone' => 'Asia/Almaty'", "'timezone' => 'Europe/Moscow'"));
  await page.goto('/admin/news');
  await expect(page.getByTestId('news-row-meta')).toContainText('25.11.2026, 07:00');
  expect(newsRow(id).published_at).toBe('2026-11-25 04:00:00');
  await openEdit(page, id);
  await expect(page.getByTestId('news-pub-current')).toHaveText('Запланирована на 25.11.2026 в 07:00');
  // новый ввод в Москве: 07:00 MSK = 04:00 UTC = «сейчас» не раньше чем будущее
  await setClock(page, '2026-11-25 04:00:00');
  await page.goto('/admin/news/new');
  await page.getByTestId('news-title-ru').fill('Вторая');
  await page.getByTestId('news-pub-later').check();
  await page.getByTestId('news-pub-date').fill('2026-11-25');
  await page.getByTestId('news-pub-time').fill('07:00');
  await page.getByTestId('news-submit').click();
  await expect(page.getByTestId('news-field-error')).toHaveText('Укажите будущие дату и время');
  await page.getByTestId('news-pub-time').fill('07:01');
  await page.getByTestId('news-submit').click();
  await expect(flash(page)).toHaveText('Публикация запланирована на 25.11.2026 07:01');
});
