// @ts-check
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { expect } = require('@playwright/test');

const ROOT = path.resolve(__dirname, '../..');
const STORAGE = path.join(ROOT, 'storage/testing');
const ENV = {
  ...process.env,
  APP_ENV: 'testing',
  APP_STORAGE_DIR: STORAGE,
  APP_CONFIG_FILE: path.join(STORAGE, 'config.php'),
};

const EDITOR = { name: 'Петрова Анна Сергеевна', login: 'editor', password: 'Editor-pass-2026' };

const OWNER = {
  name: 'Иванова Мария Петровна',
  login: 'owner',
  password: 'Owner-pass-2026',
  siteName: 'Детский сад «Солнышко»',
};

/**
 * Сбросить тестовое приложение.
 * fail: ['pdo_sqlite', 'zip:warn'] — подмена требований (по умолчанию статус fail).
 */
function resetApp({ installed = false, debug = false, fail = [], withEditor = false } = {}) {
  const args = ['tests/e2e/bin/reset.php'];
  if (installed) args.push('--installed');
  if (withEditor) args.push('--with-editor');
  args.push(`--debug=${debug ? 1 : 0}`);
  if (fail.length) args.push(`--fail=${fail.join(',')}`);
  execFileSync('php', args, { cwd: ROOT, env: ENV, stdio: 'pipe' });
}

/** Выполнить UPDATE/INSERT/DELETE в тестовой БД, вернуть число затронутых строк. */
function execSql(sql) {
  const out = execFileSync('php', ['tests/e2e/bin/exec.php', sql], { cwd: ROOT, env: ENV, stdio: 'pipe' });
  return parseInt(out.toString('utf8').trim(), 10);
}

/** Войти через форму. user — OWNER или EDITOR. */
async function login(page, user, { remember = false } = {}) {
  await page.goto('/admin/login');
  await page.getByTestId('login-login').fill(user.login);
  await page.getByTestId('login-password').fill(user.password);
  if (remember) await page.getByTestId('login-remember').check();
  await page.getByTestId('login-submit').click();
  await page.waitForURL((u) => !u.pathname.endsWith('/admin/login'));
}

/** Выйти кнопкой в меню. */
async function logout(page) {
  await page.getByTestId('nav-logout').click();
  await page.waitForURL('**/admin/login');
}

/** Состарить сессию на N секунд (таймаут бездействия). */
async function ageSession(page, seconds) {
  const res = await page.request.get(`/__test/session-age?seconds=${seconds}`);
  expect(res.status()).toBe(204);
}

/** Выполнить SELECT в тестовой БД, вернуть массив строк. */
function dbQuery(sql) {
  const out = execFileSync('php', ['tests/e2e/bin/db.php', sql], { cwd: ROOT, env: ENV, stdio: 'pipe' });
  return JSON.parse(out.toString('utf8'));
}

/** Содержимое всех логов приложения из storage/testing/logs. */
function readLog() {
  const dir = path.join(STORAGE, 'logs');
  if (!fs.existsSync(dir)) return '';
  return fs
    .readdirSync(dir)
    .filter((f) => /^app-.*\.log$/.test(f))
    .map((f) => fs.readFileSync(path.join(dir, f), 'utf8'))
    .join('\n');
}

/** Версии всех файлов из migrations/ (NNN_name.sql -> NNN). */
function migrationVersions() {
  return fs.readdirSync(path.join(ROOT, 'migrations')).filter((f) => /^\d+_.*\.sql$/.test(f)).map((f) => f.split('_')[0]).sort();
}

let current = null;

/**
 * Начать сбор ошибок страницы: console.error, pageerror, ответы >= 500.
 * options.allow500 — разрешить 500 (тест error-500).
 * options.ignoreConsole — RegExp для сообщений консоли, которые не считаются ошибкой.
 */
function trackPageErrors(page, options = {}) {
  const errors = [];
  const tracker = { errors, options };
  page.on('console', (msg) => {
    if (msg.type() !== 'error') return;
    const text = msg.text();
    if (options.ignoreConsole && options.ignoreConsole.test(text)) return;
    errors.push('console: ' + text);
  });
  page.on('pageerror', (err) => errors.push('pageerror: ' + err.message));
  page.on('response', (res) => {
    if (res.status() >= 500 && !options.allow500) errors.push(`HTTP ${res.status()}: ${res.url()}`);
  });
  current = tracker;
  return tracker;
}

/** Вызывать в afterEach. */
function expectNoPageErrors() {
  const tracker = current;
  current = null;
  expect(tracker ? tracker.errors : []).toEqual([]);
}

const VALID = {
  siteName: OWNER.siteName,
  ownerName: OWNER.name,
  login: 'Admin',
  password: 'Secret-pass-2026',
  confirm: 'Secret-pass-2026',
};

/** Заполнить форму установки (undefined-поля пропускаются). */
async function fillInstall(page, o = {}) {
  const v = { ...VALID, ...o };
  await page.getByTestId('install-site-name').fill(v.siteName);
  await page.getByTestId('install-owner-name').fill(v.ownerName);
  await page.getByTestId('install-login').fill(v.login);
  await page.getByTestId('install-password').fill(v.password);
  await page.getByTestId('install-password-confirm').fill(v.confirm);
}

/** Открыть /install.php, заполнить и отправить форму. */
async function installViaUI(page, o = {}) {
  await page.goto('/install.php');
  await fillInstall(page, o);
  await page.getByTestId('install-submit').click();
}

/** CSRF-токен из HTML формы установки (в сессии контекста страницы). */
async function csrfFromInstallPage(page) {
  const res = await page.request.get('/install.php');
  const m = /name="_csrf" value="([0-9a-f]{64})"/.exec(await res.text());
  if (!m) throw new Error('csrf token not found');
  return m[1];
}

function validPost(csrf, o = {}) {
  return {
    _csrf: csrf,
    site_name: VALID.siteName,
    owner_name: VALID.ownerName,
    login: 'second',
    password: 'Other-pass-2026',
    password_confirm: 'Other-pass-2026',
    ...o,
  };
}

module.exports = {
  OWNER, EDITOR, ROOT, migrationVersions, STORAGE, VALID, resetApp, dbQuery, execSql, login, logout, ageSession, readLog, trackPageErrors, expectNoPageErrors,
  fillInstall, installViaUI, csrfFromInstallPage, validPost,
};
