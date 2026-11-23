// @ts-check
// Общие хелперы E2E новостей (фича 04): подмена времени, создание новостей через форму, выборки из БД, ускорение автосохранения.
const crypto = require('crypto');
const { expect } = require('@playwright/test');
const { dbQuery, execSql } = require('./helpers');
const { csrfOf } = require('./auth-helpers');
const { fx, upload } = require('./media-helpers');

/** Подмена «сейчас» (UTC, 'Y-m-d H:i:s'); без аргумента — вернуть настоящее время. */
async function setClock(page, utc) {
  const r = await page.request.post('/__test/clock', {
    data: utc ? JSON.stringify({ now: utc }) : '',
    headers: { 'Content-Type': 'application/json' },
  });
  expect(r.status()).toBe(204);
}

/** Стандартное «сейчас» тестов: 24.11.2026 05:00 по Алматы (00:00 UTC). */
const NOW = '2026-11-24 00:00:00';

/** application/x-www-form-urlencoded с массивами (photos[]). */
function encode(fields) {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries(fields)) {
    if (Array.isArray(v)) v.forEach((x) => p.append(k + '[]', String(x)));
    else if (v !== undefined && v !== null) p.append(k, String(v));
  }
  return p.toString();
}

/** POST формы новости напрямую (с CSRF текущей сессии), без следования редиректам. */
async function postNews(page, url, fields, { csrf } = {}) {
  const token = csrf === undefined ? await csrfOf(page, '/admin/news/new') : csrf;
  const body = { ...(token === null ? {} : { _csrf: token }), ...fields };
  return page.request.post(url, {
    data: encode(body),
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    maxRedirects: 0,
  });
}

const formKey = () => crypto.randomBytes(16).toString('hex');
const newsRows = () => dbQuery('SELECT * FROM news ORDER BY id');
const newsRow = (id) => dbQuery(`SELECT * FROM news WHERE id=${Number(id)}`)[0];
const kkRow = (id) => dbQuery(`SELECT * FROM news_translations WHERE news_id=${Number(id)}`)[0];
const newsCount = () => Number(dbQuery('SELECT COUNT(*) AS c FROM news')[0].c);
const lastNewsId = () => Number(dbQuery('SELECT COALESCE(MAX(id),0) AS m FROM news')[0].m);
const newsMedia = (id) => dbQuery(`SELECT media_id, place, sort FROM news_media WHERE news_id=${Number(id)} ORDER BY place, sort`);
const auditActions = () => dbQuery("SELECT action, user_id, entity_id FROM audit_log WHERE action LIKE 'news.%' ORDER BY id");

/**
 * Создать новость через POST формы (как браузер). fields — имена полей формы; по умолчанию режим «Опубликовать сразу».
 * Возвращает id. Ждёт 302.
 */
async function createNews(page, fields = {}) {
  const r = await postNews(page, '/admin/news', { title_ru: 'Новость', publish_mode: 'now', intent: 'submit', slug_auto: '1', form_key: formKey(), ...fields });
  expect(r.status(), (await r.text()).slice(0, 300)).toBe(302);
  return lastNewsId();
}

/** Новость, запланированная на локальные (Алматы) дату и время. */
const createScheduled = (page, date, time, fields = {}) => createNews(page, { publish_mode: 'later', publish_date: date, publish_time: time, ...fields });

/** Создать n новостей «Заголовок NN»: каждая опубликована на минуту позже предыдущей (с 01.11.2026). Часы остаются на последней. */
async function seedNews(page, n, prefix = 'Новость', fields = {}) {
  for (let i = 1; i <= n; i++) {
    const t = new Date(Date.UTC(2026, 10, 1, 0, i)).toISOString().slice(0, 19).replace('T', ' ');
    await setClock(page, t);
    await createNews(page, { title_ru: `${prefix} ${String(i).padStart(2, '0')}`, ...fields });
  }
}

/** Быстро добавить n опубликованных новостей прямо в БД («Н 001»…, минута за минутой с 01.10.2026 UTC, автор — owner). */
function bulkNews(n, prefix = 'Н') {
  execSql(`INSERT INTO news (slug, title, status, published_at, author_id) WITH RECURSIVE c(i) AS (SELECT 1 UNION ALL SELECT i+1 FROM c WHERE i < ${Number(n)})
    SELECT '${Math.random().toString(36).slice(2, 8)}-' || i, '${prefix} ' || printf('%03d', i), 'published', datetime('2026-10-01', '+' || i || ' minutes'), 1 FROM c`);
}

/** Загрузить n фото, вернуть их id. */
async function photos(page, n = 1, prefix = 'фото') {
  const out = [];
  for (let i = 0; i < n; i++) out.push((await upload(page, fx(i % 2 ? 'small2.jpg' : 'small.jpg'), { name: `${prefix}-${i + 1}.jpg` })).id);
  return out;
}

/**
 * Ускорить автосохранение формы: подменяет delay/minGap в data-config (страницы редактора).
 * Вызывать до page.goto на страницу редактора.
 */
async function fastAutosave(page, delay = 300, minGap = 600) {
  await page.route(/\/admin\/news(\/new|\/\d+(\/edit)?)?$/, async (route) => {
    const resp = await route.fetch({ maxRedirects: 0 });
    if (!(resp.headers()['content-type'] || '').includes('text/html')) return route.fulfill({ response: resp });
    const body = (await resp.text()).replace('&quot;delay&quot;:5000', `&quot;delay&quot;:${delay}`).replace('&quot;minGap&quot;:15000', `&quot;minGap&quot;:${minGap}`);
    return route.fulfill({ response: resp, body });
  });
}

/** Открыть редактор новой новости. */
async function openNew(page) {
  await page.goto('/admin/news/new');
  await expect(page.getByTestId('news-form')).toBeVisible();
}

/** Открыть редактор существующей. */
async function openEdit(page, id) {
  await page.goto(`/admin/news/${id}/edit`);
  await expect(page.getByTestId('news-form')).toBeVisible();
}

const panel = (page, lang) => page.getByTestId(`news-panel-${lang}`);
const area = (page, lang) => page.getByTestId(`news-body-${lang}`);

/** Ввести текст в редактор (contenteditable) нужной вкладки: абзацы разделяются \n. */
async function typeBody(page, lang, text) {
  const a = area(page, lang);
  await a.click();
  const parts = text.split('\n');
  for (let i = 0; i < parts.length; i++) {
    if (i) await page.keyboard.press('Enter');
    await page.keyboard.type(parts[i]);
  }
}

/** Заполнить русскую вкладку. */
async function fillRu(page, { title, excerpt, body }) {
  if (title !== undefined) await page.getByTestId('news-title-ru').fill(title);
  if (excerpt !== undefined) await page.getByTestId('news-excerpt-ru').fill(excerpt);
  if (body !== undefined) await typeBody(page, 'ru', body);
}

/** Перейти на вкладку языка. */
async function tab(page, lang) {
  await page.getByTestId(`news-tab-${lang}`).click();
  await expect(panel(page, lang)).toBeVisible();
}

/** Выбрать фото в открытом окне медиатеки по именам файлов (title плитки) и подтвердить. */
async function pickInModal(page, names) {
  const modal = page.getByTestId('media-picker-modal');
  await expect(modal).toBeVisible();
  await expect(page.getByTestId('media-picker-item').first()).toBeVisible();
  for (const n of names) await page.locator(`[data-testid="media-picker-item"][title="${n}"]`).click();
  await page.getByTestId('media-picker-confirm').click();
  await expect(modal).toBeHidden();
}

/** Тосты и сообщения. */
const flash = (page) => page.getByTestId('admin-flash');

/** Скрытые поля photos[] / cover_media_id формы как массив id. */
async function formPhotoIds(page) {
  return page.getByTestId('news-photos').locator('input[name="photos[]"]').evaluateAll((els) => els.map((e) => Number(/** @type {HTMLInputElement} */ (e).value)));
}

module.exports = {
  NOW, setClock, encode, postNews, formKey, newsRows, newsRow, kkRow, newsCount, lastNewsId, newsMedia, auditActions,
  createNews, createScheduled, seedNews, bulkNews, photos, fastAutosave, openNew, openEdit, panel, area, typeBody, fillRu, tab, pickInModal, flash, formPhotoIds,
};
