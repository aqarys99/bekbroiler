// @ts-check
// Общие хелперы E2E медиатеки (фича 03): фикстуры, загрузка через API, проверки диска и БД.
const fs = require('fs');
const path = require('path');
const { expect } = require('@playwright/test');
const { ROOT, dbQuery } = require('./helpers');

const FX = path.join(ROOT, 'tests/fixtures/media');
const UPLOADS = path.join(ROOT, 'public/uploads');
/** Путь к фикстуре. */
const fx = (name) => path.join(FX, name);

const MIME = { jpg: 'image/jpeg', png: 'image/png', gif: 'image/gif', svg: 'image/svg+xml', pdf: 'application/pdf', heic: 'image/heic' };
const mimeOf = (name) => MIME[name.split('.').pop().toLowerCase()] || 'application/octet-stream';

/** CSRF-токен текущей сессии (со страницы медиатеки: data-csrf или скрытое поле). */
async function csrf(page, url = '/admin/media') {
  const r = await page.request.get(url);
  const m = /data-csrf="([0-9a-f]{64})"|name="_csrf" value="([0-9a-f]{64})"/.exec(await r.text());
  if (!m) throw new Error('csrf not found at ' + url + ' (status ' + r.status() + ')');
  return m[1] || m[2];
}

/**
 * Загрузка файла напрямую (как XHR загрузчика). opts: name, token ('' — без токена), headers, buffer.
 * Возвращает APIResponse.
 */
async function apiUpload(page, file, opts = {}) {
  const buffer = opts.buffer || fs.readFileSync(file);
  const name = opts.name || path.basename(file);
  const headers = { 'X-Requested-With': 'fetch', Accept: 'application/json', ...(opts.headers || {}) };
  if (opts.token !== '') headers['X-CSRF-Token'] = opts.token || (await csrf(page));
  return page.request.post('/admin/media/upload', {
    headers,
    multipart: { file: { name, mimeType: opts.mime || mimeOf(name), buffer } },
    maxRedirects: 0,
  });
}

/** Загрузить фикстуру и вернуть media из ответа (ждёт 201). */
async function upload(page, file = fx('small.jpg'), opts = {}) {
  const r = await apiUpload(page, file, opts);
  expect(r.status(), await r.text()).toBe(201);
  return (await r.json()).media;
}

/** Загрузить n маленьких фото (имена prefix-001.jpg ...). */
async function seed(page, n, prefix = 'photo') {
  const token = await csrf(page);
  const buffer = fs.readFileSync(fx('small2.jpg'));
  const ids = [];
  for (let i = 1; i <= n; i++) {
    const name = `${prefix}-${String(i).padStart(3, '0')}.jpg`;
    const r = await apiUpload(page, fx('small2.jpg'), { name, token, buffer });
    expect(r.status()).toBe(201);
    ids.push((await r.json()).media.id);
  }
  return ids;
}

/** Задать использования тестовым провайдером: { [id]: [{label,url}] }; без аргумента — очистить. */
async function setUsages(page, map) {
  const r = await page.request.post('/__test/media-usage', {
    data: map ? JSON.stringify(map) : '',
    headers: { 'Content-Type': 'application/json' },
  });
  expect(r.status()).toBe(204);
}

/** Провайдер сбоит при проверке использования. */
const setUsagesFailing = (page) => setUsages(page, { __throw: true });

const mediaRows = () => dbQuery('SELECT * FROM media ORDER BY id');
const mediaCount = () => Number(dbQuery('SELECT COUNT(*) AS c FROM media')[0].c);
const mediaRow = (id) => dbQuery(`SELECT * FROM media WHERE id=${Number(id)}`)[0];

/** Все файлы в public/uploads/images (относительные пути), без .gitkeep/.htaccess. */
function filesOnDisk() {
  const out = [];
  const walk = (dir) => {
    if (!fs.existsSync(dir)) return;
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const p = path.join(dir, e.name);
      if (e.isDirectory()) walk(p);
      else if (e.name !== '.gitkeep' && e.name !== '.htaccess') out.push(path.relative(UPLOADS, p));
    }
  };
  walk(path.join(UPLOADS, 'images'));
  return out.sort();
}

/** Пути 6 файлов фото (относительно uploads) из строки media. */
function variantFiles(row) {
  const v = JSON.parse(row.variants);
  const out = [];
  for (const s of ['thumb', 'medium', 'large']) out.push(v[s].webp, v[s].jpg);
  return out;
}

/** Данные файла на диске: Buffer. */
const readUpload = (rel) => fs.readFileSync(path.join(UPLOADS, rel));

/** Есть ли в байтах метаданные EXIF/GPS/XMP. */
function hasMetadata(buf) {
  const s = buf.toString('latin1');
  return s.includes('Exif') || s.includes('GPS') || s.includes('http://ns.adobe.com/xap') || s.includes('XMP');
}

/** Открыть медиатеку и дождаться загрузки. */
async function gotoMedia(page, qs = '') {
  const r = await page.goto('/admin/media' + qs);
  expect(r.status()).toBeLessThan(400);
}

/** Выбрать файлы в input медиатеки и дождаться окончания очереди (все строки не в процессе). */
async function uploadViaUI(page, files, { scope = page } = {}) {
  await scope.getByTestId('media-file-input').setInputFiles(files);
  await waitQueueIdle(page, scope);
}

async function waitQueueIdle(page, scope = page) {
  await expect(scope.getByTestId('media-queue-clear')).toBeEnabled({ timeout: 90000 });
}

module.exports = {
  FX, UPLOADS, fx, csrf, apiUpload, upload, seed, setUsages, setUsagesFailing, mediaRows, mediaCount, mediaRow,
  filesOnDisk, variantFiles, readUpload, hasMetadata, gotoMedia, uploadViaUI, waitQueueIdle,
};
