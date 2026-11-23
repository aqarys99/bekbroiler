const { test, expect } = require('@playwright/test');
const { OWNER, login, dbQuery } = require('./helpers');
const path = require('path');
const F = (n) => path.join(__dirname, '..', 'fixtures', 'media', n);

test('gallery smoke', async ({ page }) => {
  const errs = [];
  page.on('console', (m) => { if (m.type() === 'error') errs.push(m.text()); });
  page.on('pageerror', (e) => errs.push(String(e)));
  page.on('response', (r) => { if (r.status() >= 500) errs.push('HTTP ' + r.status() + ' ' + r.url()); });
  page.on('dialog', (d) => d.accept());
  await login(page, OWNER);
  await page.goto('/admin/gallery');
  await page.getByTestId('gallery-new').click();
  await page.getByTestId('gallery-form-title').fill('Осенний утренник');
  await page.getByTestId('gallery-form-title-kk').fill('Күзгі мереке');
  await page.getByTestId('gallery-form-submit').click();
  await expect(page).toHaveURL(/\/admin\/gallery\/\d+$/);
  await page.getByTestId('gallery-file-input').setInputFiles([F('small.jpg'), F('small2.jpg'), F('orient-6.jpg')]);
  await expect(page.getByTestId('gallery-photo')).toHaveCount(3, { timeout: 30000 });
  await expect(page.getByTestId('gallery-cover-badge')).toHaveCount(1);
  // cover to third
  await page.getByTestId('gallery-photo').nth(2).getByTestId('gallery-photo-cover').click();
  await expect(page.getByTestId('gallery-photo').nth(2).getByTestId('gallery-cover-badge')).toBeVisible();
  // move third left
  await page.getByTestId('gallery-photo').nth(2).getByTestId('gallery-photo-left').click();
  await expect(page.getByTestId('gallery-photo').nth(1).getByTestId('gallery-cover-badge')).toBeVisible();
  await page.getByTestId('gallery-photo').nth(0).getByTestId('gallery-photo-caption').fill('Подпись');
  await page.getByTestId('gallery-photo').nth(0).getByTestId('gallery-photo-caption-save').click();
  await expect(page.getByTestId('gallery-photo').nth(0).getByTestId('gallery-photo-caption')).toHaveValue('Подпись');
  // library add: upload one to media, then add via picker
  await page.getByTestId('gallery-photo').nth(0).getByTestId('gallery-photo-remove').click();
  await expect(page.getByTestId('gallery-photo')).toHaveCount(2);
  await page.getByTestId('gallery-add-library').click();
  for (let i = 0; i < 3; i++) await page.getByTestId('media-picker-item').nth(i).click();
  await page.getByTestId('media-picker-confirm').click();
  await expect(page.getByTestId('gallery-photo')).toHaveCount(3);
  // media is still in library; deletion blocked
  const id = dbQuery('SELECT media_id AS m FROM album_media LIMIT 1')[0].m;
  await page.goto('/admin/media/' + id);
  await expect(page.locator('body')).toContainText('Альбом');
  await page.goto('/admin/gallery');
  await expect(page.getByTestId('gallery-album-meta').first()).toContainText('3 фото');
  await page.getByTestId('gallery-album').first().click();
  await page.getByTestId('gallery-published').uncheck();
  await page.getByTestId('gallery-publish-apply').click();
  await page.goto('/admin/gallery');
  await expect(page.getByTestId('gallery-album-hidden').first()).toBeVisible();
  await page.getByTestId('gallery-album').first().click();
  await page.getByTestId('gallery-delete').click();
  await expect(page).toHaveURL(/\/admin\/gallery$/);
  expect(Number(dbQuery('SELECT COUNT(*) AS c FROM media')[0].c)).toBeGreaterThanOrEqual(3);
  expect(errs).toEqual([]);
});
