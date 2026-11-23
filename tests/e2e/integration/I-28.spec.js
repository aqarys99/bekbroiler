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
const used = (page, name) => page.locator('[data-testid="media-item"][title="' + name + '"]').getByTestId('media-item-used');

/** Фото свободно: карточка «нигде не используется», нет значка в сетке, удаление доступно; само фото на месте. */
async function expectFree(page, id, name) {
  expect(mediaRow(id)).toBeTruthy();
  await page.goto('/admin/media/' + id);
  await expect(page.getByTestId('media-usages')).toContainText('нигде не используется');
  await expect(page.getByTestId('media-usage-item')).toHaveCount(0);
  await expect(page.getByTestId('media-delete')).toBeEnabled();
  await page.goto('/admin/media');
  await expect(page.locator('[data-testid="media-item"][title="' + name + '"]')).toHaveCount(1);
  await expect(used(page, name)).toHaveCount(0);
}

async function expectUsed(page, id, name) {
  await page.goto('/admin/media/' + id);
  await expect(page.getByTestId('media-usage-item')).toHaveCount(1);
  await expect(page.getByTestId('media-delete')).toBeDisabled();
  await page.goto('/admin/media');
  await expect(used(page, name)).toBeVisible();
}

test('I-28: удаление новости освобождает обложку, фото и фото в тексте; сами фото остаются в медиатеке', async ({ page }) => {
  await login(page, OWNER);
  const [c, g, t] = await photos(page, 3);
  const id = await createNews(page, { title_ru: 'На удаление', cover_media_id: c, photos: [g], body_ru: '<p><img data-media-id="' + t + '"></p>' });
  await expectUsed(page, c, 'фото-1.jpg');
  await expectUsed(page, g, 'фото-2.jpg');
  await expectUsed(page, t, 'фото-3.jpg');
  await page.goto('/admin/news');
  page.once('dialog', (d) => d.accept());
  await page.getByTestId('news-row-delete').click();
  await expect(flash(page)).toHaveText('Новость удалена');
  expect(newsMedia(id)).toEqual([]);
  await expectFree(page, c, 'фото-1.jpg');
  await expectFree(page, g, 'фото-2.jpg');
  await expectFree(page, t, 'фото-3.jpg');
});

test('I-28: снятие обложки освобождает фото (если оно не используется в другом месте новости)', async ({ page }) => {
  await login(page, OWNER);
  const [c, both] = await photos(page, 2);
  const id = await createNews(page, { title_ru: 'Обложка', cover_media_id: c });
  await expectUsed(page, c, 'фото-1.jpg');
  await openEdit(page, id);
  await page.getByTestId('news-cover').getByTestId('media-picker-clear').click();
  await page.getByTestId('news-submit').click();
  await expectFree(page, c, 'фото-1.jpg');
  // та же картинка и обложка, и в фото к новости: снятие обложки оставляет «фото»
  await openEdit(page, id);
  await page.getByTestId('news-cover').getByTestId('media-picker-open').click();
  await pickInModal(page, ['фото-2.jpg']);
  await page.getByTestId('news-photos').getByTestId('media-picker-open').click();
  await pickInModal(page, ['фото-2.jpg']);
  await page.getByTestId('news-submit').click();
  await page.goto('/admin/media/' + both);
  await expect(page.getByTestId('media-usage-item')).toHaveText('Новость «Обложка» (обложка, фото)');
  await openEdit(page, id);
  await page.getByTestId('news-cover').getByTestId('media-picker-clear').click();
  await page.getByTestId('news-submit').click();
  await page.goto('/admin/media/' + both);
  await expect(page.getByTestId('media-usage-item')).toHaveText('Новость «Обложка» (фото)');
  await expect(page.getByTestId('media-delete')).toBeDisabled();
});

test('I-28: замена обложки освобождает старое фото; удаление фото из «Фото к новости» освобождает его', async ({ page }) => {
  await login(page, OWNER);
  const [old, fresh, g1, g2] = await photos(page, 4);
  const id = await createNews(page, { title_ru: 'Замена', cover_media_id: old, photos: [g1, g2] });
  await openEdit(page, id);
  await page.getByTestId('news-cover').getByTestId('media-picker-open').click();
  await pickInModal(page, ['фото-2.jpg']);
  await page.getByTestId('news-photos').getByTestId('media-picker-clear').first().click();
  await page.getByTestId('news-submit').click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  await expectFree(page, old, 'фото-1.jpg');
  await expectFree(page, g1, 'фото-3.jpg');
  await expectUsed(page, fresh, 'фото-2.jpg');
  await expectUsed(page, g2, 'фото-4.jpg');
  expect(newsMedia(id).filter((m) => m.place === 'gallery').map((m) => m.media_id)).toEqual([g2]);
});

test('I-28: удаление img из текста (RU или ҚАЗ) освобождает фото; фото, оставшееся в другом языке, остаётся занятым', async ({ page }) => {
  await login(page, OWNER);
  const [p] = await photos(page, 1);
  const id = await createNews(page, {
    title_ru: 'В тексте', body_ru: '<p><img data-media-id="' + p + '"></p>', title_kk: 'Мәтінде', body_kk: '<p><img data-media-id="' + p + '"></p>',
  });
  await expectUsed(page, p, 'фото-1.jpg');
  await openEdit(page, id);
  await area(page, 'ru').evaluate((el) => { el.querySelectorAll('img').forEach((i) => i.remove()); el.dispatchEvent(new Event('input', { bubbles: true })); });
  await page.getByTestId('news-submit').click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  await expectUsed(page, p, 'фото-1.jpg'); // осталось в ҚАЗ
  await openEdit(page, id);
  await page.getByTestId('news-tab-kk').click();
  await area(page, 'kk').evaluate((el) => { el.querySelectorAll('img').forEach((i) => i.remove()); el.dispatchEvent(new Event('input', { bubbles: true })); });
  await page.getByTestId('news-submit').click();
  await expectFree(page, p, 'фото-1.jpg');
  // освободившееся фото можно удалить через UI
  await page.goto('/admin/media/' + p);
  page.once('dialog', (d) => d.accept());
  await page.getByTestId('media-delete').click();
  expect(mediaRow(p)).toBeUndefined();
});

test('I-28: удаление новости из редактора (после автосохранения) тоже освобождает фото', async ({ page }) => {
  await login(page, OWNER);
  const [p] = await photos(page, 1);
  const id = await createNews(page, { title_ru: 'Из редактора', publish_mode: 'draft', cover_media_id: p });
  await openEdit(page, id);
  page.once('dialog', (d) => d.accept());
  await page.getByTestId('news-delete').click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  await expectFree(page, p, 'фото-1.jpg');
});
