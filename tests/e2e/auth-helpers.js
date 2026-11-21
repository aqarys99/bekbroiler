// @ts-check
// Общие хелперы E2E фичи 02 (вход и пользователи).
const { expect } = require('@playwright/test');
const { OWNER, EDITOR, dbQuery, login } = require('./helpers');

const TOKEN_RE = /name="_csrf" value="([0-9a-f]{64})"/;

/** CSRF-токен текущей сессии контекста страницы (берётся со страницы path). */
async function csrfOf(page, path = '/admin/login') {
  const r = await page.request.get(path);
  const m = TOKEN_RE.exec(await r.text());
  if (!m) throw new Error('csrf not found at ' + path + ' (status ' + r.status() + ')');
  return m[1];
}

/** POST без следования редиректам. */
const post = (page, url, form) => page.request.post(url, { form, maxRedirects: 0 });

/** Неудачная попытка входа через прямой POST. */
async function badLogin(page, loginName, password = 'Wrong-pass-123') {
  const csrf = await csrfOf(page, '/admin/login');
  return post(page, '/admin/login', { _csrf: csrf, login: loginName, password });
}

const q = (s) => String(s).replace(/'/g, "''");
const userId = (loginName) => Number(dbQuery(`SELECT id FROM users WHERE login='${q(loginName)}'`)[0].id);
const audit = (action) => dbQuery(`SELECT user_id, entity, entity_id FROM audit_log WHERE action='${q(action)}' ORDER BY id`);
const count = (sql) => Number(dbQuery(sql)[0].c);
const attempts = () => count("SELECT COUNT(*) AS c FROM login_attempts WHERE ip NOT LIKE 'profile:%'");
const tokens = () => count('SELECT COUNT(*) AS c FROM remember_tokens');

/** Новая независимая сессия (контекст), вошедшая под user. */
async function newSession(browser, user, opts = {}) {
  const ctx = await browser.newContext();
  const page = await ctx.newPage();
  await login(page, user, opts);
  return { ctx, page };
}

/** Создать пользователя через UI формы (страница должна быть под owner). */
async function createUserUI(page, { name, login: l, role = 'editor', password = 'Pass-word-2026' }) {
  await page.goto('/admin/users/new');
  await page.getByTestId('user-form-name').fill(name);
  await page.getByTestId('user-form-login').fill(l);
  await page.getByTestId('user-form-role').selectOption(role);
  await page.getByTestId('user-form-password').fill(password);
  await page.getByTestId('user-form-submit').click();
  await expect(page).toHaveURL(/\/admin\/users$/);
}

/** Сменить site_name в БД (JSON-значение). */
function setSiteName(name) {
  const { execSql } = require('./helpers');
  execSql(`UPDATE settings SET value='${q(JSON.stringify(name))}' WHERE key='site_name'`);
}

/** Страница-владелец -> ждёт вход. */
const OWNER_ONLY_URLS = ['/admin/users', '/admin/users/new', '/admin/users/1/edit', '/admin/users/1/password'];

module.exports = {
  OWNER, EDITOR, csrfOf, post, badLogin, q, userId, audit, count, attempts, tokens,
  newSession, createUserUI, setSiteName, OWNER_ONLY_URLS,
};
