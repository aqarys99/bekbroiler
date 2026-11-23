// @ts-check
// I-30: HTML из редактора сохраняется только после HtmlSanitizer и выводится только через renderBody (img → media_picture).
const { test, expect } = require('@playwright/test');
const { resetApp, login, dbQuery, execSql, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { OWNER } = require('../auth-helpers');
const { createNews, photos, newsRow, kkRow, lastNewsId, postNews, formKey } = require('../news-helpers');

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d|net::ERR_FAILED/ });
});
test.afterEach(() => expectNoPageErrors());

const VECTORS = [
  '<script>window.__pwn=1</script>',
  '<img src=x onerror="window.__pwn=1">',
  '<svg onload="window.__pwn=1"><script>window.__pwn=1</script></svg>',
  '<iframe src="javascript:window.__pwn=1"></iframe>',
  '<iframe srcdoc="<script>parent.__pwn=1</script>"></iframe>',
  '<a href="javascript:window.__pwn=1">клик</a>',
  '<a href="  JaVa&#9;ScRiPt:window.__pwn=1">клик</a>',
  '<a href="data:text/html;base64,PHNjcmlwdD53aW5kb3cuX19wd249MTwvc2NyaXB0Pg==">клик</a>',
  '<a href="vbscript:msgbox(1)">клик</a>',
  '<p onclick="window.__pwn=1" style="background:url(javascript:1)">абзац</p>',
  '<object data="javascript:window.__pwn=1"></object><embed src="javascript:1">',
  '<form action="javascript:1"><input name=x><button>go</button></form>',
  '<math><mi xlink:href="javascript:1">x</mi></math>',
  '<style>*{background:url(javascript:1)}</style>',
  '<video src=x onerror="window.__pwn=1"></video><audio src=x onerror="window.__pwn=1"></audio>',
  '<scr<script>ipt>window.__pwn=1</scr</script>ipt>',
  '<meta http-equiv="refresh" content="0;url=javascript:1"><link rel=stylesheet href=//evil>',
  '<img data-media-id="999999" src="http://evil/x.png">',
];

test('I-30: в БД и в предпросмотре после сохранения любого ввода нет ни запрещённых тегов, ни атрибутов; скрипты не выполняются (RU и ҚАЗ)', async ({ page }) => {
  await login(page, OWNER);
  for (const [i, v] of VECTORS.entries()) {
    const r = await postNews(page, '/admin/news', {
      title_ru: `Вектор ${i}`, body_ru: `<p>ru ${i}</p>${v}`, title_kk: 'Қаз', body_kk: `<p>kk ${i}</p>${v}`, publish_mode: 'now', intent: 'submit', form_key: formKey(),
    });
    expect(r.status(), v).toBe(302);
  }
  const rows = dbQuery(`SELECT n.id, n.body, k.body AS kk FROM news n JOIN news_translations k ON k.news_id = n.id ORDER BY n.id`);
  expect(rows).toHaveLength(VECTORS.length);
  for (const row of rows) {
    for (const html of [row.body, row.kk]) {
      expect(html).not.toMatch(/<\s*(script|iframe|object|embed|svg|math|style|form|input|button|meta|link|video|audio|img)\b/i);
      expect(html).not.toMatch(/\son[a-z]+\s*=/i);
      expect(html).not.toMatch(/\sstyle\s*=/i);
      expect(html).not.toMatch(/javascript:|vbscript:|data:text/i);
    }
  }
  for (const row of rows) {
    for (const url of [`/admin/news/${row.id}/preview`, `/admin/news/${row.id}/edit`]) {
      await page.goto(url);
      expect(await page.evaluate(() => /** @type {any} */ (window).__pwn), url).toBeUndefined();
      expect(await page.locator('.pv-body script, .editor__area script, .pv-body iframe').count(), url).toBe(0);
    }
  }
});

test('I-30: ссылки — только http/https/mailto; внешние с rel="noopener noreferrer" target=_blank, mailto без target', async ({ page }) => {
  await login(page, OWNER);
  const id = await createNews(page, {
    title_ru: 'Ссылки',
    body_ru: '<p><a href="https://example.com/a?x=1&y=2">https</a> <a href="HTTP://example.org">http</a> <a href="mailto:info@sadik.kz">mail</a> <a href="/admin">относительная</a> <a href="ftp://x">ftp</a></p>',
  });
  expect(newsRow(id).body).toBe('<p><a href="https://example.com/a?x=1&amp;y=2" target="_blank" rel="noopener noreferrer">https</a> <a href="HTTP://example.org" target="_blank" rel="noopener noreferrer">http</a> <a href="mailto:info@sadik.kz">mail</a> относительная ftp</p>');
  await page.goto(`/admin/news/${id}/preview`);
  const body = page.locator('#pv-ru .pv-body');
  await expect(body.getByRole('link')).toHaveCount(3);
  for (const n of ['https', 'http']) {
    await expect(body.getByRole('link', { name: n, exact: true })).toHaveAttribute('rel', 'noopener noreferrer');
    await expect(body.getByRole('link', { name: n, exact: true })).toHaveAttribute('target', '_blank');
  }
  await expect(body.getByRole('link', { name: 'mail' })).not.toHaveAttribute('target', /.*/);
});

test('I-30: фото в тексте хранится как <img data-media-id>, выводится через media_picture (picture, alt из медиатеки); смена alt видна сразу; удалённого фото нет — ничего вместо битой картинки', async ({ page }) => {
  await login(page, OWNER);
  const [p] = await photos(page, 1);
  execSql(`UPDATE media SET alt='Первый alt' WHERE id=${p}`);
  const id = await createNews(page, { title_ru: 'Фото', body_ru: `<p>до</p><img data-media-id="${p}" src="http://evil/x.png" alt="чужой" style="x:y"><p>после</p>` });
  expect(newsRow(id).body).toBe(`<p>до</p><img data-media-id="${p}"><p>после</p>`);
  await page.goto(`/admin/news/${id}/preview`);
  const body = page.locator('#pv-ru .pv-body');
  await expect(body.locator('picture')).toHaveCount(1);
  await expect(body.locator('img[data-media-id]')).toHaveCount(0);
  await expect(body.getByRole('img', { name: 'Первый alt' })).toBeVisible();
  expect(await body.locator('img').getAttribute('src')).toMatch(/^\/uploads\/images\//);
  // смена alt в медиатеке
  execSql(`UPDATE media SET alt='Второй alt' WHERE id=${p}`);
  await page.reload();
  await expect(page.locator('#pv-ru .pv-body').getByRole('img', { name: 'Второй alt' })).toBeVisible();
  // фото, которого нет в медиатеке (удалено в обход санитайзера) — вместо битой картинки ничего
  execSql(`UPDATE news SET body='<p>текст</p><img data-media-id="777777">' WHERE id=${id}`);
  await page.reload();
  await expect(page.locator('#pv-ru .pv-body')).toContainText('текст');
  await expect(page.locator('#pv-ru .pv-body img, #pv-ru .pv-body picture')).toHaveCount(0);
});
