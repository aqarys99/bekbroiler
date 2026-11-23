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
const usage = (page) => page.getByTestId('media-usage-item');
const used = (page, name) => page.locator('[data-testid="media-item"][title="' + name + '"]').getByTestId('media-item-used');

test('I-01: обложка, фото к новости и фото в тексте — карточка файла показывает «Новость «X» (роли)» со ссылкой на редактор; в сетке значок «Используется» (через форму)', async ({ page }) => {
  await login(page, OWNER);
  const [cover, gal, inText, free] = await photos(page, 4);
  await openNew(page);
  await page.getByTestId('news-title-ru').fill('Утренник');
  await page.getByTestId('news-cover').getByTestId('media-picker-open').click();
  await pickInModal(page, ['фото-1.jpg']);
  await page.getByTestId('news-photos').getByTestId('media-picker-open').click();
  await pickInModal(page, ['фото-2.jpg', 'фото-1.jpg']);
  await page.getByTestId('news-tb-photo').first().click();
  await pickInModal(page, ['фото-3.jpg']);
  await page.getByTestId('news-submit').click();
  await expect(page).toHaveURL(/\/admin\/news$/);
  const id = lastNewsId();
  await page.goto('/admin/media/' + cover);
  await expect(usage(page)).toHaveText('Новость «Утренник» (обложка, фото)');
  await expect(usage(page)).toHaveAttribute('href', '/admin/news/' + id + '/edit');
  await page.goto('/admin/media/' + gal);
  await expect(usage(page)).toHaveText('Новость «Утренник» (фото)');
  await page.goto('/admin/media/' + inText);
  await expect(usage(page)).toHaveText('Новость «Утренник» (в тексте)');
  await usage(page).click();
  await expect(page).toHaveURL(new RegExp('/admin/news/' + id + '/edit$'));
  await expect(page.getByTestId('news-title-ru')).toHaveValue('Утренник');
  await page.goto('/admin/media/' + free);
  await expect(page.getByTestId('media-usages')).toContainText('нигде не используется');
  await page.goto('/admin/media');
  await expect(used(page, 'фото-1.jpg')).toBeVisible();
  await expect(used(page, 'фото-2.jpg')).toBeVisible();
  await expect(used(page, 'фото-3.jpg')).toBeVisible();
  await expect(used(page, 'фото-4.jpg')).toHaveCount(0);
});

test('I-01: все три роли у одного файла — «(обложка, фото, в тексте)»; перечислены только реальные роли', async ({ page }) => {
  await login(page, OWNER);
  const [p, q] = await photos(page, 2);
  const id = await createNews(page, {
    title_ru: 'Все роли', cover_media_id: p, photos: [p, q],
    body_ru: '<p><img data-media-id="' + p + '"></p>', body_kk: '<p><img data-media-id="' + q + '"></p>', title_kk: 'Рөлдер',
  });
  await page.goto('/admin/media/' + p);
  await expect(usage(page)).toHaveText('Новость «Все роли» (обложка, фото, в тексте)');
  await page.goto('/admin/media/' + q);
  await expect(usage(page)).toHaveText('Новость «Все роли» (фото, в тексте)');
  expect(newsMedia(id).filter((m) => m.place === 'body').map((m) => m.media_id).sort()).toEqual([p, q].sort());
});

test('I-01: название новости в метке экранируется; смена названия сразу видна в карточке файла', async ({ page }) => {
  await login(page, OWNER);
  const [p] = await photos(page, 1);
  const id = await createNews(page, { title_ru: '<i>Жирно</i> & "x"', cover_media_id: p });
  await page.goto('/admin/media/' + p);
  await expect(usage(page)).toHaveText('Новость «<i>Жирно</i> & "x"» (обложка)');
  await expect(usage(page).locator('i')).toHaveCount(0);
  await openEdit(page, id);
  await page.getByTestId('news-title-ru').fill('Новое имя');
  await page.getByTestId('news-submit').click();
  await page.goto('/admin/media/' + p);
  await expect(usage(page)).toHaveText('Новость «Новое имя» (обложка)');
});

test('I-01: editor видит те же использования (права не ограничивают карточку)', async ({ page }) => {
  await login(page, EDITOR);
  const [p] = await photos(page, 1);
  await createNews(page, { title_ru: 'От редактора', cover_media_id: p });
  await page.goto('/admin/media/' + p);
  await expect(usage(page)).toHaveText('Новость «От редактора» (обложка)');
  await expect(page.getByTestId('media-delete')).toBeDisabled();
});
