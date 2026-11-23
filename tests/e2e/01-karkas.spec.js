// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const {
  STORAGE, VALID, migrationVersions, resetApp, dbQuery, readLog, trackPageErrors, expectNoPageErrors,
  fillInstall, installViaUI, csrfFromInstallPage, validPost,
} = require('./helpers');

const KEYS = ['php', 'pdo_sqlite', 'fileinfo', 'mbstring', 'image', 'heic', 'zip',
  'upload_max_filesize', 'post_max_size', 'writable_storage', 'writable_config', 'writable_uploads', 'writable_sessions'];
const usersCount = () => Number(dbQuery('SELECT COUNT(*) AS c FROM users')[0].c);
// reset.php при --debug=0 кладёт тестовый config.php, поэтому «ничего не создано» = нет БД и lock
const noDb = () => !fs.existsSync(path.join(STORAGE, 'database.sqlite')) && !fs.existsSync(path.join(STORAGE, 'install.lock'));

test.beforeEach(({ page }, info) => {
  const allow500 = /error-500/.test(info.title);
  // Chromium пишет «Failed to load resource» для ожидаемых 4xx/5xx страниц — это не ошибка приложения
  trackPageErrors(page, { allow500, ignoreConsole: allow500 ? /status of [45]\d\d/ : /status of 4\d\d/ });
});
test.afterEach(() => expectNoPageErrors());

test.describe('до установки', () => {
  test.beforeEach(() => resetApp());

  test('install-redirect: любой URL ведёт на /install.php, статика отдаётся', async ({ page }) => {
    for (const p of ['/', '/admin', '/abc']) {
      const r = await page.request.get(p, { maxRedirects: 0 });
      expect(r.status(), p).toBe(302);
      expect(r.headers()['location']).toBe('/install.php');
    }
    await page.goto('/admin');
    await expect(page).toHaveURL(/\/install\.php$/);
    await expect(page.getByTestId('install-requirements')).toBeVisible();
    const css = await page.request.get('/assets/admin/admin.css', { maxRedirects: 0 });
    expect(css.status()).toBe(200);
    const inst = await page.request.get('/install.php', { maxRedirects: 0 });
    expect(inst.status()).toBe(200);
  });

  test('install-requirements: блок показывает все строки требований с data-status', async ({ page }) => {
    await page.goto('/install.php');
    await expect(page.getByTestId('install-requirements')).toBeVisible();
    for (const k of KEYS) {
      await expect(page.getByTestId(`install-req-${k}`)).toHaveAttribute('data-status', /^(ok|warn|fail)$/);
    }
  });

  test('install-req-{key}: название, значение, статус и подсказка у каждой строки требований', async ({ page }) => {
    await resetApp({ fail: ['pdo_sqlite', 'zip:warn'] });
    await page.goto('/install.php');
    for (const k of KEYS) {
      const row = page.getByTestId(`install-req-${k}`);
      await expect(row).toBeVisible();
      await expect(row).not.toHaveText('');
    }
    await expect(page.getByTestId('install-req-php')).toContainText(/\d+\.\d+/);
    await expect(page.getByTestId('install-req-pdo_sqlite')).toHaveAttribute('data-status', 'fail');
    await expect(page.getByTestId('install-req-pdo_sqlite')).toContainText('Включите расширение');
    await expect(page.getByTestId('install-req-zip')).toHaveAttribute('data-status', 'warn');
    await expect(page.getByTestId('install-req-zip')).toContainText('Включите расширение');
    await expect(page.getByTestId('install-req-mbstring')).toContainText(/есть|нет/);
    await expect(page.getByTestId('install-req-writable_storage')).toContainText('запись разрешена');
    // fail красный, warn оранжевый
    const color = (id) => page.getByTestId(id).evaluate((el) => getComputedStyle(el).color);
    expect(await color('install-req-pdo_sqlite')).toBe('rgb(180, 35, 24)');
    expect(await color('install-req-zip')).toBe('rgb(154, 77, 0)');
  });

  test('install-requirements-failed: плашка, disabled-поля, принудительный POST даёт 422', async ({ page }) => {
    await resetApp({ fail: ['pdo_sqlite'] });
    await page.goto('/install.php');
    await expect(page.getByTestId('install-requirements-failed')).toBeVisible();
    await expect(page.getByTestId('install-submit')).toBeDisabled();
    for (const id of ['install-site-name', 'install-owner-name', 'install-login', 'install-password', 'install-password-confirm']) {
      await expect(page.getByTestId(id)).toBeDisabled();
    }
    const csrf = await csrfFromInstallPage(page);
    const r = await page.request.post('/install.php', { form: validPost(csrf) });
    expect(r.status()).toBe(422);
    expect(await r.text()).toContain('install-requirements-failed');
    expect(noDb()).toBe(true);
  });

  test('install-requirements-failed: строки warn не блокируют установку', async ({ page }) => {
    await resetApp({ fail: ['zip:warn'] });
    await page.goto('/install.php');
    await expect(page.getByTestId('install-requirements-failed')).toHaveCount(0);
    await expect(page.getByTestId('install-submit')).toBeEnabled();
  });

  test('install-recheck: ссылка перезагружает страницу и сбрасывает форму', async ({ page }) => {
    await page.goto('/install.php');
    await page.getByTestId('install-site-name').fill('Что-то');
    await page.getByTestId('install-recheck').click();
    await expect(page).toHaveURL(/\/install\.php$/);
    await expect(page.getByTestId('install-site-name')).toHaveValue('');
    await expect(page.getByTestId('install-recheck')).toBeVisible();
  });

  test('install-recheck: после исправления требований блок пересчитан', async ({ page }) => {
    await resetApp({ fail: ['pdo_sqlite'] });
    await page.goto('/install.php');
    await expect(page.getByTestId('install-req-pdo_sqlite')).toHaveAttribute('data-status', 'fail');
    resetApp();
    await page.getByTestId('install-recheck').click();
    await expect(page.getByTestId('install-req-pdo_sqlite')).toHaveAttribute('data-status', 'ok');
    await expect(page.getByTestId('install-submit')).toBeEnabled();
  });

  test('install-site-name: ошибки (пусто, 1 и 151 символ) и сохранённые другие поля', async ({ page }) => {
    await installViaUI(page, { siteName: '   ' });
    await expect(page.getByText('Укажите название детского сада')).toBeVisible();
    await expect(page.getByTestId('install-site-name')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByTestId('install-owner-name')).toHaveValue(VALID.ownerName);
    await expect(page.getByTestId('install-login')).toHaveValue('Admin');
    await fillInstall(page, { siteName: 'я' });
    await page.getByTestId('install-submit').click();
    await expect(page.getByText('От 2 до 150 символов')).toBeVisible();
    await fillInstall(page, { siteName: 'я'.repeat(151) });
    await page.getByTestId('install-submit').click();
    await expect(page.getByText('От 2 до 150 символов')).toBeVisible();
    expect(noDb()).toBe(true);
  });

  test('install-site-name: валидное название сохраняется, HTML выводится текстом', async ({ page }) => {
    await installViaUI(page, { siteName: '<b>x</b>' });
    await expect(page.getByTestId('install-done')).toBeVisible();
    expect(JSON.parse(dbQuery("SELECT value FROM settings WHERE key='site_name'")[0].value)).toBe('<b>x</b>');
    await page.goto('/');
    await expect(page.getByTestId('stub-site-name')).toHaveText('<b>x</b>');
  });

  test('install-owner-name: пусто, >100, схлопывание пробелов', async ({ page }) => {
    await installViaUI(page, { ownerName: '' });
    await expect(page.getByText('Укажите имя')).toBeVisible();
    await fillInstall(page, { ownerName: 'я'.repeat(101) });
    await page.getByTestId('install-submit').click();
    await expect(page.getByText('Не более 100 символов')).toBeVisible();
    await fillInstall(page, { ownerName: 'Иванова    Мария   Петровна' });
    await page.getByTestId('install-submit').click();
    await expect(page.getByTestId('install-done')).toBeVisible();
    expect(dbQuery('SELECT name FROM users')[0].name).toBe('Иванова Мария Петровна');
  });

  test('install-login: ошибки, регистр приводится к нижнему', async ({ page }) => {
    await installViaUI(page, { login: '' });
    await expect(page.getByText('Укажите логин')).toBeVisible();
    for (const bad of ['ab', 'a'.repeat(51)]) {
      await fillInstall(page, { login: bad });
      await page.getByTestId('install-submit').click();
      await expect(page.getByText('От 3 до 50 символов')).toBeVisible();
    }
    for (const bad of ['админ', 'a b c']) {
      await fillInstall(page, { login: bad });
      await page.getByTestId('install-submit').click();
      await expect(page.getByText('Только латинские буквы, цифры, точка, дефис и подчёркивание')).toBeVisible();
    }
    expect(noDb()).toBe(true);
    await fillInstall(page, { login: 'Admin' });
    await page.getByTestId('install-submit').click();
    await expect(page.getByText('Ваш логин: admin.')).toBeVisible();
    expect(dbQuery('SELECT login FROM users')[0].login).toBe('admin');
  });

  test('install-password: ошибки, пароль не возвращается в HTML, в БД только хэш', async ({ page }) => {
    await installViaUI(page, { password: '', confirm: '' });
    await expect(page.getByText('Придумайте пароль')).toBeVisible();
    await fillInstall(page, { password: '1234567', confirm: '1234567' });
    await page.getByTestId('install-submit').click();
    await expect(page.locator('.field__error', { hasText: 'Не короче 8 символов' })).toBeVisible();
    await expect(page.getByTestId('install-password')).toHaveValue('');
    await fillInstall(page, { password: 'a'.repeat(65), confirm: 'a'.repeat(65) });
    await page.getByTestId('install-submit').click();
    await expect(page.getByText('Слишком длинный пароль')).toBeVisible();
    await fillInstall(page, { password: 'я'.repeat(40), confirm: 'я'.repeat(40) });
    await page.getByTestId('install-submit').click();
    await expect(page.getByText('Слишком длинный пароль')).toBeVisible();
    expect(await page.content()).not.toContain('я'.repeat(40));
    await fillInstall(page);
    await page.getByTestId('install-submit').click();
    await expect(page.getByTestId('install-done')).toBeVisible();
    expect(dbQuery('SELECT password_hash AS h FROM users')[0].h).toMatch(/^\$(2y|argon2)/);
  });

  test('install-password-confirm: несовпадение даёт ошибку у этого поля', async ({ page }) => {
    await installViaUI(page, { confirm: 'Different-pass-1' });
    await expect(page.getByText('Пароли не совпадают')).toBeVisible();
    await expect(page.getByTestId('install-password-confirm')).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByTestId('install-password')).not.toHaveAttribute('aria-invalid', 'true');
    expect(noDb()).toBe(true);
  });

  test('install-password-toggle: оба поля text/password, aria меняются, форма не отправляется', async ({ page }) => {
    await page.goto('/install.php');
    const t = page.getByTestId('install-password-toggle');
    await expect(t).toHaveAttribute('aria-pressed', 'false');
    await t.click();
    await expect(page.getByTestId('install-password')).toHaveAttribute('type', 'text');
    await expect(page.getByTestId('install-password-confirm')).toHaveAttribute('type', 'text');
    await expect(t).toHaveAttribute('aria-label', 'Скрыть пароль');
    await expect(t).toHaveAttribute('aria-pressed', 'true');
    await t.click();
    await expect(page.getByTestId('install-password')).toHaveAttribute('type', 'password');
    await expect(page.getByTestId('install-password-confirm')).toHaveAttribute('type', 'password');
    await expect(t).toHaveAttribute('aria-label', 'Показать пароль');
    await expect(page).toHaveURL(/\/install\.php$/);
    expect(noDb()).toBe(true);
  });

  test('install-submit: установка создаёт БД, пользователя, настройку, аудит, конфиг, lock', async ({ page }) => {
    await installViaUI(page);
    await expect(page.getByTestId('install-done')).toBeVisible();
    expect(dbQuery("SELECT version FROM schema_migrations").map((r) => r.version)).toEqual(migrationVersions());
    const tables = dbQuery("SELECT name FROM sqlite_master WHERE type='table'").map((r) => r.name);
    for (const t of ['users', 'login_attempts', 'settings', 'audit_log', 'schema_migrations']) expect(tables).toContain(t);
    const u = dbQuery('SELECT id, role, login FROM users');
    expect(u).toHaveLength(1);
    expect(u[0].role).toBe('owner');
    const audit = dbQuery('SELECT action, entity, entity_id FROM audit_log');
    expect(audit).toHaveLength(1);
    expect([audit[0].action, audit[0].entity, Number(audit[0].entity_id)]).toEqual(['create', 'user', Number(u[0].id)]);
    expect(fs.readFileSync(path.join(STORAGE, 'config.php'), 'utf8')).toMatch(/'debug' => false/);
    expect(fs.existsSync(path.join(STORAGE, 'install.lock'))).toBe(true);
  });

  test('install-submit: ошибка полей — 422 и ничего не создано', async ({ page }) => {
    const csrf = await csrfFromInstallPage(page);
    const r = await page.request.post('/install.php', { form: validPost(csrf, { login: 'x' }) });
    expect(r.status()).toBe(422);
    expect(noDb()).toBe(true);
  });

  test('install-submit: двойной клик создаёт ровно одного пользователя', async ({ page }) => {
    await page.goto('/install.php');
    await fillInstall(page);
    await page.getByTestId('install-submit').dblclick();
    await expect(page.getByTestId('install-done')).toBeVisible();
    expect(usersCount()).toBe(1);
  });

  test('install-submit: две параллельные отправки из разных сессий — ровно один владелец', async ({ browser }) => {
    const ctxs = [await browser.newContext(), await browser.newContext()];
    try {
      const pages = await Promise.all(ctxs.map((c) => c.newPage()));
      const tokens = await Promise.all(pages.map((p) => csrfFromInstallPage(p)));
      const rs = await Promise.all(pages.map((p, i) => p.request.post('/install.php', {
        form: validPost(tokens[i], { login: 'owner' + i }),
      })));
      expect(rs.map((r) => r.status()).sort()).toEqual([200, 403]);
      expect(Number(dbQuery("SELECT COUNT(*) AS c FROM users WHERE role='owner'")[0].c)).toBe(1);
    } finally {
      await Promise.all(ctxs.map((c) => c.close()));
    }
  });

  test('install-submit: лишнее поле base_path не попадает в конфиг и не уводит на чужой домен', async ({ page }) => {
    const csrf = await csrfFromInstallPage(page);
    const r = await page.request.post('/install.php', { form: validPost(csrf, { login: 'owner', base_path: '//evil.example' }) });
    expect(r.status()).toBe(200);
    const cfg = fs.readFileSync(path.join(STORAGE, 'config.php'), 'utf8');
    expect(cfg).not.toContain('evil.example');
    expect(cfg).toMatch(/'base_path' => ''/);
    const res = await page.goto('/');
    expect(res.status()).toBe(200);
    await expect(page.getByTestId('stub-site-name')).toBeVisible();
    for (const h of await page.locator('a').evaluateAll((els) => els.map((e) => e.getAttribute('href')))) {
      expect(h || '').not.toContain('evil.example');
    }
  });

  test('install-submit: массивы в полях (site_name[], login[]) — 422 с ошибкой поля, не 500, с CSRF и без', async ({ page }) => {
    const csrf = await csrfFromInstallPage(page);
    const headers = { 'content-type': 'application/x-www-form-urlencoded' };
    const base = 'owner_name=Ivan&password=Secret-pass-2026&password_confirm=Secret-pass-2026';
    const withCsrf = await page.request.post('/install.php', { headers, data: `_csrf=${csrf}&site_name[]=x&login[]=x&${base}` });
    expect(withCsrf.status()).toBe(422);
    expect(await withCsrf.text()).toContain('field__error');
    const noCsrf = await page.request.post('/install.php', { headers, data: `site_name[]=x&login[]=x&${base}` });
    expect([400, 422]).toContain(noCsrf.status());
    const badCsrf = await page.request.post('/install.php', { headers, data: `_csrf[]=x&site_name[]=x&login[]=x&${base}` });
    expect([400, 422]).toContain(badCsrf.status());
    expect(noDb()).toBe(true);
  });

  test('install-error: неверный или отсутствующий _csrf даёт 400 и плашку', async ({ page }) => {
    await page.goto('/install.php');
    const bad = await page.request.post('/install.php', { form: validPost('0'.repeat(64)) });
    expect(bad.status()).toBe(400);
    const html = await bad.text();
    expect(html).toContain('install-error');
    expect(html).toContain('Сессия устарела. Обновите страницу и попробуйте снова.');
    const { _csrf, ...noToken } = validPost('x');
    const missing = await page.request.post('/install.php', { form: noToken });
    expect(missing.status()).toBe(400);
    expect(noDb()).toBe(true);
  });
});

test.describe('после установки', () => {
  test.beforeEach(() => resetApp({ installed: true }));

  test('install-done: логин владельца и напоминание; повторный POST — 403, второго пользователя нет', async ({ page }) => {
    resetApp();
    await installViaUI(page, { login: 'Admin' });
    await expect(page.getByTestId('install-done')).toHaveText('Установка завершена');
    await expect(page.getByText('Ваш логин: admin.')).toBeVisible();
    await expect(page.getByText('Запомните пароль')).toBeVisible();
    const r = await page.request.post('/install.php', { form: validPost('0'.repeat(64)) });
    expect(r.status()).toBe(403);
    expect(usersCount()).toBe(1);
  });

  test('install-go-admin: кнопка ведёт в админку (заглушка)', async ({ page }) => {
    resetApp();
    await installViaUI(page);
    await page.getByTestId('install-go-admin').click();
    await expect(page).toHaveURL(/\/admin\/login\?next=(%2F|\/)admin$/);
    await expect(page.getByTestId('login-form')).toBeVisible();
  });

  test('install-locked: GET и POST дают 403, форма скрыта, пароль прежний', async ({ page }) => {
    const hash = dbQuery('SELECT password_hash AS h FROM users')[0].h;
    const res = await page.goto('/install.php');
    expect(res.status()).toBe(403);
    await expect(page.getByTestId('install-locked')).toHaveText('Установка уже выполнена');
    await expect(page.getByTestId('install-requirements')).toHaveCount(0);
    await expect(page.getByTestId('install-submit')).toHaveCount(0);
    const r = await page.request.post('/install.php', { form: validPost('0'.repeat(64)) });
    expect(r.status()).toBe(403);
    expect(await r.text()).toContain('install-locked');
    expect(usersCount()).toBe(1);
    expect(dbQuery('SELECT password_hash AS h FROM users')[0].h).toBe(hash);
  });

  test('install-locked-admin: ссылка ведёт на /admin', async ({ page }) => {
    await page.goto('/install.php');
    await page.getByTestId('install-locked-admin').click();
    await expect(page).toHaveURL(/\/admin\/login/);
    await expect(page.getByTestId('login-form')).toBeVisible();
  });

  test('stub-site-name: название из settings, <script> выводится текстом', async ({ page }) => {
    resetApp();
    let dialog = false;
    page.on('dialog', (d) => { dialog = true; d.dismiss(); });
    await installViaUI(page, { siteName: '<script>alert(1)</script>' });
    await expect(page.getByTestId('install-done')).toBeVisible();
    await page.goto('/');
    await expect(page.getByTestId('stub-site-name')).toHaveText('<script>alert(1)</script>');
    await page.goto('/admin/login');
    await expect(page.getByTestId('login-site-name')).toHaveText('<script>alert(1)</script>');
    expect(dialog).toBe(false);
  });

  test('stub-site-name: обычное название садика на / ', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('stub-site-name')).toHaveText('Детский сад «Солнышко»');
  });

  test('stub-message: / — 200, /admin требует вход, /admin/ — 301, HEAD / пустой, POST / — 405', async ({ page }) => {
    const res = await page.goto('/');
    expect(res.status()).toBe(200);
    await expect(page.getByTestId('stub-message')).toHaveText('Сайт скоро откроется.');
    const adm = await page.request.get('/admin', { maxRedirects: 0 });
    expect(adm.status()).toBe(302);
    const slash = await page.request.get('/admin/', { maxRedirects: 0 });
    expect(slash.status()).toBe(301);
    expect(slash.headers()['location']).toBe('/admin');
    const head = await page.request.head('/');
    expect(head.status()).toBe(200);
    expect((await head.body()).length).toBe(0);
    expect((await page.request.post('/')).status()).toBe(405);
  });

  test('error-404: код 404 и текст, /uploads/x.php, кириллица и %00 — 404 без 500', async ({ page }) => {
    const res = await page.goto('/net-takoy-stranicy');
    expect(res.status()).toBe(404);
    await expect(page.getByTestId('error-404')).toHaveText('Страница не найдена');
    await expect(page.getByText('Возможно, адрес набран с ошибкой или страница удалена.')).toBeVisible();
    for (const p of ['/uploads/x.php', '/%D1%81%D1%82%D1%80%D0%B0%D0%BD%D0%B8%D1%86%D0%B0', '/abc%00d']) {
      expect((await page.request.get(p)).status(), p).toBe(404);
    }
  });

  test('error-404-home: ссылка «На главную» ведёт на /', async ({ page }) => {
    await page.goto('/net-takoy-stranicy');
    await page.getByTestId('error-404-home').click();
    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByTestId('stub-site-name')).toBeVisible();
  });

  async function open405(page) {
    await page.goto('/');
    const resp = page.waitForResponse((r) => r.request().method() === 'POST');
    await page.evaluate(() => {
      const f = document.createElement('form');
      f.method = 'post';
      f.action = '/';
      document.body.append(f);
      f.submit();
    });
    return resp;
  }

  test('error-405: код 405, Allow: GET, HEAD, заголовок страницы', async ({ page }) => {
    const r = await open405(page);
    expect(r.status()).toBe(405);
    expect(r.headers()['allow']).toBe('GET, HEAD');
    await expect(page.getByTestId('error-405')).toHaveText('Метод не поддерживается');
  });

  test('error-405-home: ссылка «На главную» ведёт на /', async ({ page }) => {
    await open405(page);
    await page.getByTestId('error-405-home').click();
    await expect(page.getByTestId('stub-site-name')).toBeVisible();
  });

  test('error-500: без деталей при debug=false, запись в логе', async ({ page }) => {
    const res = await page.goto('/__test/error');
    expect(res.status()).toBe(500);
    await expect(page.getByTestId('error-500')).toHaveText('Что-то пошло не так');
    await expect(page.getByText('Мы уже записали ошибку. Попробуйте обновить страницу через минуту.')).toBeVisible();
    const html = await page.content();
    expect(html).not.toContain('Test error');
    expect(html).not.toContain('RuntimeException');
    expect(html).not.toContain('/home/');
    await expect(page.getByTestId('error-500-details')).toHaveCount(0);
    const log = readLog();
    expect(log).toContain('Test error');
    expect(log).toContain('/__test/error');
  });

  test('error-500: lock есть, а БД удалена — страница 500, пустая БД не создаётся', async ({ page }) => {
    for (const f of ['database.sqlite', 'database.sqlite-wal', 'database.sqlite-shm']) fs.rmSync(path.join(STORAGE, f), { force: true });
    const res = await page.goto('/');
    expect(res.status()).toBe(500);
    await expect(page.getByTestId('error-500')).toBeVisible();
    expect(fs.existsSync(path.join(STORAGE, 'database.sqlite'))).toBe(false);
  });

  test('error-500-details: при debug=true видны класс, сообщение, файл:строка', async ({ page }) => {
    resetApp({ installed: true, debug: true });
    const res = await page.goto('/__test/error');
    expect(res.status()).toBe(500);
    const d = page.getByTestId('error-500-details');
    await expect(d).toBeVisible();
    await expect(d).toContainText('RuntimeException');
    await expect(d).toContainText('Test error');
    await expect(d).toContainText('ErrorController.php:');
  });

  test('error-500-home: ссылка «На главную» ведёт на /', async ({ page }) => {
    await page.goto('/__test/error');
    await page.getByTestId('error-500-home').click();
    await expect(page.getByTestId('stub-site-name')).toBeVisible();
  });
});
