// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, login, execSql, dbQuery, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { OWNER, EDITOR } = require('../auth-helpers');
const { csrf, mediaRow } = require('../media-helpers');
const N = require('../news-helpers');
const { NOW, setClock, createNews, createScheduled, photos, openNew, openEdit, panel, area, pickInModal, flash, newsRow, newsRows, newsMedia, lastNewsId, newsCount, postNews, formKey } = N;

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d|net::ERR_FAILED/ });
});
test.afterEach(() => expectNoPageErrors());
test('I-02: фото, используемое новостью, нельзя удалить: кнопка disabled, список мест, прямой POST отказывает, прямой DELETE нарушает FK; после отвязки — можно', async ({ page }) => {
  await login(page, OWNER);
  const [cover, gal] = await photos(page, 2);
  const id = await createNews(page, { title_ru: 'Держит фото', cover_media_id: cover, photos: [gal] });
  for (const p of [cover, gal]) {
    await page.goto('/admin/media/' + p);
    await expect(page.getByTestId('media-delete')).toBeDisabled();
    await expect(page.getByTestId('media-delete-blocked')).toBeVisible();
    await expect(page.getByTestId('media-usage-item')).toHaveCount(1);
    // прямой POST (мимо отключённой кнопки)
    const r = await page.request.post('/admin/media/' + p + '/delete', { form: { _csrf: await csrf(page, '/admin/media/' + p) }, maxRedirects: 0 });
    expect(r.status()).toBeLessThan(500);
    expect(mediaRow(p)).toBeTruthy();
    // прямой DELETE в БД — FK RESTRICT
    expect(() => execSql('DELETE FROM media WHERE id=' + p)).toThrow();
    expect(mediaRow(p)).toBeTruthy();
  }
  // отвязка обложки освобождает только её
  await openEdit(page, id);
  await page.getByTestId('news-cover').getByTestId('media-picker-clear').click();
  await page.getByTestId('news-submit').click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  await page.goto('/admin/media/' + cover);
  await expect(page.getByTestId('media-delete')).toBeEnabled();
  await page.goto('/admin/media/' + gal);
  await expect(page.getByTestId('media-delete')).toBeDisabled();
  await page.goto('/admin/media/' + cover);
  page.once('dialog', (d) => d.accept());
  await page.getByTestId('media-delete').click();
  await expect(flash(page)).toBeVisible();
  expect(mediaRow(cover)).toBeUndefined();
  expect(mediaRow(gal)).toBeTruthy();
});

test('I-02: одно фото в нескольких новостях и в нескольких ролях — все места в списке; удалить нельзя, пока осталась хоть одна', async ({ page }) => {
  await login(page, OWNER);
  const [p] = await photos(page, 1);
  const a = await createNews(page, { title_ru: 'Первая', cover_media_id: p });
  const b = await createNews(page, { title_ru: 'Вторая', photos: [p] });
  await page.goto('/admin/media/' + p);
  await expect(page.getByTestId('media-usage-item')).toHaveCount(2);
  execSql('DELETE FROM news WHERE id=' + a);
  await page.reload();
  await expect(page.getByTestId('media-usage-item')).toHaveCount(1);
  await expect(page.getByTestId('media-delete')).toBeDisabled();
  execSql('DELETE FROM news WHERE id=' + b);
  await page.reload();
  await expect(page.getByTestId('media-delete')).toBeEnabled();
});

test('I-02: использование появилось после открытия карточки — сервер отказывает, фото не удалено', async ({ page }) => {
  await login(page, OWNER);
  const [p] = await photos(page, 1);
  await page.goto('/admin/media/' + p);
  await expect(page.getByTestId('media-delete')).toBeEnabled();
  await createNews(page, { title_ru: 'Успела раньше', cover_media_id: p });
  page.once('dialog', (d) => d.accept());
  await page.getByTestId('media-delete').click();
  expect(mediaRow(p)).toBeTruthy();
  await page.goto('/admin/media/' + p);
  await expect(page.getByTestId('media-delete')).toBeDisabled();
});

test('I-02: фото удалено из медиатеки, пока открыта форма новости — новость не сохраняется с «битым» id, ошибка у поля', async ({ page }) => {
  await login(page, OWNER);
  const [p] = await photos(page, 1);
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Ссылка на удалённое');
  await page.getByTestId('news-cover').getByTestId('media-picker-open').click();
  await pickInModal(page, ['фото-1.jpg']);
  execSql('DELETE FROM media WHERE id=' + p);
  await page.getByTestId('news-submit').click();
  await expect(page.getByTestId('news-cover').getByTestId('news-field-error')).toHaveText('Фото удалено из медиатеки — выберите другое');
  expect(newsCount()).toBe(0);
});
