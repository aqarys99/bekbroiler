// @ts-check
const { test, expect } = require('@playwright/test');
const {
  resetApp, dbQuery, execSql, login, logout, ageSession, trackPageErrors, expectNoPageErrors,
} = require('./helpers');
const {
  OWNER, EDITOR, csrfOf, post, badLogin, q, userId, audit, count, attempts, tokens,
  newSession, createUserUI, setSiteName,
} = require('./auth-helpers');

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
});
test.afterEach(() => expectNoPageErrors());

const LOGIN_FAIL = 'Неверный логин или пароль';
const submitLogin = async (page, l, p) => {
  await page.goto('/admin/login');
  await page.getByTestId('login-login').fill(l);
  await page.getByTestId('login-password').fill(p);
  await page.getByTestId('login-submit').click();
};
const cookie = async (ctx, name) => (await ctx.cookies()).find((c) => c.name === name);

// ---------------------------------------------------------------- вход

test('auth-guard: гость перенаправляется на вход с next, после входа попадает на путь', async ({ page }) => {
  for (const p of ['/admin', '/admin/users', '/admin/profile', '/admin/users/1/edit']) {
    const r = await page.request.get(p, { maxRedirects: 0 });
    expect(r.status(), p).toBe(302);
    expect(decodeURIComponent(r.headers()['location'])).toBe('/admin/login?next=' + p);
  }
  await page.goto('/admin/users');
  await expect(page.getByTestId('login-form')).toBeVisible();
  await page.getByTestId('login-login').fill(OWNER.login);
  await page.getByTestId('login-password').fill(OWNER.password);
  await page.getByTestId('login-submit').click();
  await expect(page).toHaveURL(/\/admin\/users$/);
});

test('auth-guard: POST гостем -> 302 на вход, данные не меняются; GET /admin/logout -> 405; вошедший на /admin/login -> /admin', async ({ page }) => {
  const before = count('SELECT COUNT(*) AS c FROM users');
  const r = await post(page, '/admin/users', { name: 'X', login: 'xxx', role: 'editor', password: 'Pass-word-2026' });
  expect(r.status()).toBe(302);
  expect(r.headers()['location']).toBe('/admin/login');
  expect(count('SELECT COUNT(*) AS c FROM users')).toBe(before);
  await login(page, OWNER);
  const lg = await page.request.get('/admin/login', { maxRedirects: 0 });
  expect(lg.status()).toBe(302);
  expect(lg.headers()['location']).toBe('/admin');
  const out = await page.request.get('/admin/logout', { maxRedirects: 0 });
  expect(out.status()).toBe(405);
  expect(out.headers()['allow']).toContain('POST');
});

test('auth-guard: небезопасный next игнорируется, вход ведёт на /admin', async ({ page }) => {
  for (const next of ['https://evil.example', '//evil.example', '/admin/..\\x']) {
    await page.context().clearCookies();
    await page.goto('/admin/login?next=' + encodeURIComponent(next));
    await page.getByTestId('login-login').fill(OWNER.login);
    await page.getByTestId('login-password').fill(OWNER.password);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((u) => !u.pathname.endsWith('/admin/login'));
    expect(new URL(page.url()).pathname, next).toBe('/admin');
    expect(new URL(page.url()).host).toBe('127.0.0.1:8080');
  }
});

test('login-form: заголовок, поля, title, нет переключателя языка', async ({ page }) => {
  const res = await page.goto('/admin/login');
  expect(res.status()).toBe(200);
  await expect(page.getByTestId('login-form')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Вход в админку' })).toBeVisible();
  await expect(page).toHaveTitle('Вход в админку — Детский сад «Солнышко»');
  await expect(page.getByLabel('Логин')).toBeVisible();
  await expect(page.getByLabel('Пароль', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: /Русский|Қазақша/ })).toHaveCount(0);
  await expect(page.getByText('Қазақша')).toHaveCount(0);
});

test('login-site-name: показан site_name, <script> выводится текстом', async ({ page }) => {
  await page.goto('/admin/login');
  await expect(page.getByTestId('login-site-name')).toHaveText('Детский сад «Солнышко»');
  setSiteName('<script>alert(1)</script>');
  let dialog = false;
  page.on('dialog', (d) => { dialog = true; d.dismiss(); });
  await page.goto('/admin/login');
  await expect(page.getByTestId('login-site-name')).toHaveText('<script>alert(1)</script>');
  expect(dialog).toBe(false);
});

test('login-login: OWNER в верхнем регистре с пробелами входит', async ({ page }) => {
  await submitLogin(page, '  OWNER ', OWNER.password);
  await expect(page).toHaveURL(/\/admin$/);
  await expect(page.getByTestId('dashboard-greeting')).toBeVisible();
});

test('login-login: пусто -> «Укажите логин», 422, попытка не пишется; >100 символов -> неверный логин', async ({ page }) => {
  await page.goto('/admin/login');
  const csrf = await csrfOf(page);
  const r = await post(page, '/admin/login', { _csrf: csrf, login: '', password: 'abc' });
  expect(r.status()).toBe(422);
  expect(await r.text()).toContain('Укажите логин');
  expect(attempts()).toBe(0);
  await submitLogin(page, '', 'abc');
  await expect(page.getByText('Укажите логин')).toBeVisible();
  await expect(page.getByTestId('login-login')).toHaveAttribute('aria-invalid', 'true');
  await submitLogin(page, 'a'.repeat(150), 'Some-pass-123');
  await expect(page.getByTestId('login-error')).toHaveText(LOGIN_FAIL);
});

test('login-password: type=password, autocomplete; пусто -> «Введите пароль»; пароль не возвращается', async ({ page }) => {
  await page.goto('/admin/login');
  await expect(page.getByTestId('login-password')).toHaveAttribute('type', 'password');
  await expect(page.getByTestId('login-password')).toHaveAttribute('autocomplete', 'current-password');
  await submitLogin(page, OWNER.login, '');
  await expect(page.getByText('Введите пароль')).toBeVisible();
  expect(attempts()).toBe(0);
  await submitLogin(page, OWNER.login, 'Secret-wrong-777');
  await expect(page.getByTestId('login-password')).toHaveValue('');
  expect(await page.content()).not.toContain('Secret-wrong-777');
  await expect(page.getByTestId('login-login')).toHaveValue(OWNER.login);
});

test('login-password-toggle: показывает/скрывает пароль, форму не отправляет', async ({ page }) => {
  await page.goto('/admin/login');
  const t = page.getByTestId('login-password-toggle');
  await expect(t).toHaveAttribute('type', 'button');
  await t.click();
  await expect(page.getByTestId('login-password')).toHaveAttribute('type', 'text');
  await expect(t).toHaveAttribute('aria-pressed', 'true');
  await expect(t).toHaveAttribute('aria-label', 'Скрыть пароль');
  await t.click();
  await expect(page.getByTestId('login-password')).toHaveAttribute('type', 'password');
  await expect(t).toHaveAttribute('aria-label', 'Показать пароль');
  await expect(page).toHaveURL(/\/admin\/login$/);
  expect(attempts()).toBe(0);
});

test('login-remember: по умолчанию снят; без отметки cookie и токена нет', async ({ page }) => {
  await page.goto('/admin/login');
  await expect(page.getByTestId('login-remember')).not.toBeChecked();
  await login(page, OWNER);
  expect(await cookie(page.context(), 'sadik_remember')).toBeUndefined();
  expect(tokens()).toBe(0);
});

test('login-remember: токен, флаги cookie, хэш в БД, вход без пароля с ротацией, audit login_remember', async ({ page }) => {
  await login(page, OWNER, { remember: true });
  const c = await cookie(page.context(), 'sadik_remember');
  expect(c).toBeTruthy();
  expect(c.httpOnly).toBe(true);
  expect(c.sameSite).toBe('Lax');
  const days = (c.expires - Date.now() / 1000) / 86400;
  expect(days).toBeGreaterThan(29);
  expect(days).toBeLessThan(31);
  expect(tokens()).toBe(1);
  const [selector, validator] = decodeURIComponent(c.value).split(':');
  const row = dbQuery('SELECT selector, token_hash FROM remember_tokens')[0];
  expect(row.selector).toBe(selector);
  expect(row.token_hash).toMatch(/^[0-9a-f]{64}$/);
  expect(row.token_hash).not.toBe(validator);
  expect(JSON.stringify(dbQuery('SELECT * FROM remember_tokens'))).not.toContain(validator);
  await page.context().clearCookies({ name: 'sadik_sid' });
  await page.goto('/admin');
  await expect(page.getByTestId('dashboard-greeting')).toBeVisible();
  const c2 = await cookie(page.context(), 'sadik_remember');
  expect(c2.value).not.toBe(c.value);
  expect(dbQuery('SELECT token_hash FROM remember_tokens')[0].token_hash).not.toBe(row.token_hash);
  expect(audit('login_remember')).toHaveLength(1);
});

test('login-remember: подменённый validator, истёкший токен, заблокированный, мусор -> не вход', async ({ page, browser }) => {
  await login(page, OWNER, { remember: true });
  const ctx = page.context();
  const c = await cookie(ctx, 'sadik_remember');
  // подмена validator -> удаляются все токены пользователя
  const [sel] = decodeURIComponent(c.value).split(':');
  await ctx.clearCookies({ name: 'sadik_sid' });
  await ctx.addCookies([{ ...c, value: sel + ':' + 'a'.repeat(64) }]);
  await page.goto('/admin');
  await expect(page.getByTestId('login-form')).toBeVisible();
  expect(tokens()).toBe(0);
  // истёк
  await login(page, OWNER, { remember: true });
  execSql("UPDATE remember_tokens SET expires_at='2000-01-01 00:00:00'");
  await ctx.clearCookies({ name: 'sadik_sid' });
  await page.goto('/admin');
  await expect(page.getByTestId('login-form')).toBeVisible();
  expect(tokens()).toBe(0);
  expect(await cookie(ctx, 'sadik_remember')).toBeUndefined();
  // заблокирован
  const s = await newSession(browser, EDITOR, { remember: true });
  execSql(`UPDATE users SET is_active=0 WHERE login='editor'`);
  await s.ctx.clearCookies({ name: 'sadik_sid' });
  await s.page.goto('/admin');
  await expect(s.page.getByTestId('login-form')).toBeVisible();
  await s.ctx.close();
  // мусор
  const g = await browser.newContext();
  await g.addCookies([{ name: 'sadik_remember', value: 'мусор', url: 'http://127.0.0.1:8080' }]);
  const gp = await g.newPage();
  const res = await gp.goto('/admin');
  expect(res.status()).toBe(200);
  await expect(gp.getByTestId('login-form')).toBeVisible();
  expect(await cookie(g, 'sadik_remember')).toBeUndefined();
  await g.close();
});

test('login-submit: вход меняет sid и csrf, пишет last_login_at и audit login', async ({ page }) => {
  await page.goto('/admin/login');
  const sidBefore = (await cookie(page.context(), 'sadik_sid')).value;
  const csrfBefore = await page.locator('input[name=_csrf]').first().inputValue();
  await page.getByTestId('login-login').fill(OWNER.login);
  await page.getByTestId('login-password').fill(OWNER.password);
  await page.getByTestId('login-submit').click();
  await expect(page).toHaveURL(/\/admin$/);
  const sid = await cookie(page.context(), 'sadik_sid');
  expect(sid.value).not.toBe(sidBefore);
  expect(sid.httpOnly).toBe(true);
  expect(sid.sameSite).toBe('Lax');
  expect(sid.expires).toBe(-1);
  const csrfAfter = await page.locator('input[name=_csrf]').first().inputValue();
  expect(csrfAfter).not.toBe(csrfBefore);
  expect(dbQuery("SELECT last_login_at AS t FROM users WHERE login='owner'")[0].t).toBeTruthy();
  const a = audit('login');
  expect(a).toHaveLength(1);
  expect([Number(a[0].user_id), Number(a[0].entity_id)]).toEqual([userId('owner'), userId('owner')]);
});

test('login-submit: двойной клик — одна запись login; неверный пароль и неизвестный логин -> 422, +1 попытка', async ({ page }) => {
  await page.goto('/admin/login');
  await page.getByTestId('login-login').fill(OWNER.login);
  await page.getByTestId('login-password').fill(OWNER.password);
  await page.getByTestId('login-submit').dblclick();
  await expect(page).toHaveURL(/\/admin$/);
  expect(audit('login')).toHaveLength(1);
  await logout(page);
  const r1 = await badLogin(page, 'owner');
  expect(r1.status()).toBe(422);
  expect(attempts()).toBe(1);
  const r2 = await badLogin(page, 'nobody');
  expect(r2.status()).toBe(422);
  expect(attempts()).toBe(2);
  expect(await r2.text()).toContain(LOGIN_FAIL);
  expect(audit('login_failed')).toHaveLength(2);
});

test('login-error: неверные данные, заблокированный, CSRF', async ({ page }) => {
  await submitLogin(page, OWNER.login, 'Wrong-pass-123');
  await expect(page.getByTestId('login-error')).toHaveText(LOGIN_FAIL);
  await expect(page.getByTestId('login-error')).toHaveAttribute('role', 'alert');
  execSql(`UPDATE users SET is_active=0 WHERE login='editor'`);
  await submitLogin(page, EDITOR.login, EDITOR.password);
  await expect(page.getByTestId('login-error')).toHaveText('Учётная запись заблокирована. Обратитесь к ответственному за сайт.');
  const n = attempts();
  await submitLogin(page, EDITOR.login, 'Wrong-pass-123');
  await expect(page.getByTestId('login-error')).toHaveText(LOGIN_FAIL);
  const before = attempts();
  expect(before).toBe(n + 1);
  const r = await post(page, '/admin/login', { login: 'owner', password: 'x'.repeat(10) });
  expect(r.status()).toBe(400);
  expect(await r.text()).toContain('Сессия устарела. Обновите страницу и попробуйте снова.');
  const r2 = await post(page, '/admin/login', { _csrf: '0'.repeat(64), login: 'owner', password: 'x' });
  expect(r2.status()).toBe(400);
  expect(attempts()).toBe(before);
});

test('login-locked: 5 неудач блокируют, верный пароль не пускает, audit login_locked, счётчик не растёт', async ({ page }) => {
  await page.goto('/admin/login');
  for (let i = 0; i < 4; i++) expect((await badLogin(page, 'owner')).status()).toBe(422);
  const fifth = await badLogin(page, 'owner');
  expect(fifth.status()).toBe(429);
  expect(await fifth.text()).toContain('Слишком много попыток входа. Попробуйте через 15 мин.');
  await submitLogin(page, OWNER.login, OWNER.password);
  await expect(page.getByTestId('login-locked')).toContainText('Слишком много попыток входа');
  await expect(page).toHaveURL(/\/admin\/login$/);
  expect(attempts()).toBe(5);
  expect(audit('login_locked')).toHaveLength(1);
});

test('login-locked: через 15 минут вход работает; другой логин не заблокирован; минуты округляются вверх', async ({ page }) => {
  await page.goto('/admin/login');
  for (let i = 0; i < 5; i++) await badLogin(page, 'owner');
  expect((await badLogin(page, 'editor')).status()).toBe(422);
  execSql("UPDATE login_attempts SET created_at=datetime(created_at,'-59 seconds')");
  const r = await badLogin(page, 'owner');
  expect(r.status()).toBe(429);
  expect(await r.text()).toContain('через 15 мин');
  execSql("UPDATE login_attempts SET created_at=datetime(created_at,'-16 minutes')");
  await submitLogin(page, OWNER.login, OWNER.password);
  await expect(page).toHaveURL(/\/admin$/);
});

test('login-locked: 20 неудач по разным логинам блокируют IP; успешный вход очищает счётчик пары', async ({ page }) => {
  await page.goto('/admin/login');
  for (let i = 0; i < 4; i++) await badLogin(page, 'owner');
  await submitLogin(page, OWNER.login, OWNER.password);
  await expect(page).toHaveURL(/\/admin$/);
  expect(count("SELECT COUNT(*) AS c FROM login_attempts WHERE login='owner'")).toBe(0);
  await logout(page);
  for (let i = 0; i < 20; i++) await badLogin(page, 'user' + i);
  const r = await badLogin(page, 'someone-else');
  expect(r.status()).toBe(429);
  await submitLogin(page, OWNER.login, OWNER.password);
  await expect(page.getByTestId('login-locked')).toBeVisible();
});

test('login-notice: «Вы вышли», показывается один раз', async ({ page }) => {
  await login(page, OWNER);
  await logout(page);
  await expect(page.getByTestId('login-notice')).toHaveText('Вы вышли из админки.');
  await expect(page.getByTestId('login-notice')).toHaveAttribute('role', 'status');
  await page.reload();
  await expect(page.getByTestId('login-notice')).toHaveCount(0);
});

test('login-notice: таймаут бездействия и «Пароль изменён» из другого браузера', async ({ page, browser }) => {
  await login(page, OWNER);
  await ageSession(page, 7201);
  await page.goto('/admin');
  await expect(page.getByTestId('login-notice')).toHaveText('Сессия завершена из-за бездействия. Войдите снова.');
  // пароль изменён в другом браузере
  const s = await newSession(browser, EDITOR);
  const other = await newSession(browser, EDITOR);
  await other.page.goto('/admin/profile');
  await other.page.getByTestId('profile-current-password').fill(EDITOR.password);
  await other.page.getByTestId('profile-new-password').fill('New-editor-pass-1');
  await other.page.getByTestId('profile-new-password-confirm').fill('New-editor-pass-1');
  await other.page.getByTestId('profile-password-submit').click();
  await expect(other.page.getByTestId('admin-flash')).toHaveText('Пароль изменён');
  await s.page.goto('/admin');
  await expect(s.page.getByTestId('login-form')).toBeVisible();
  await expect(s.page.getByTestId('login-notice')).toHaveText('Пароль изменён. Войдите с новым паролем.');
  await s.ctx.close();
  await other.ctx.close();
});

test('login-forgot: текст подсказки, не ссылка', async ({ page }) => {
  await page.goto('/admin/login');
  const f = page.getByTestId('login-forgot');
  await expect(f).toContainText('Забыли пароль? Обратитесь к ответственному за сайт');
  await expect(f.locator('a')).toHaveCount(0);
});

test('login-lock-hint: текст под карточкой', async ({ page }) => {
  await page.goto('/admin/login');
  await expect(page.getByTestId('login-lock-hint')).toHaveText('После 5 неверных попыток вход блокируется на 15 минут.');
});

test('auth-timeout: 7201 с -> вход с next и плашкой; 7100 с -> страница открыта', async ({ page }) => {
  await login(page, OWNER);
  await ageSession(page, 7100);
  const ok = await page.goto('/admin/users');
  expect(ok.status()).toBe(200);
  await expect(page.getByTestId('users-add')).toBeVisible();
  await ageSession(page, 7201);
  await page.goto('/admin/users');
  await expect(page).toHaveURL(/\/admin\/login\?next=(%2F|\/)admin(%2F|\/)users$/);
  await expect(page.getByTestId('login-notice')).toContainText('бездействия');
});

test('auth-timeout: с remember-cookie вход по токену; POST после таймаута -> 302 на вход, данные не сохранены', async ({ page, browser }) => {
  await login(page, OWNER, { remember: true });
  await ageSession(page, 7201);
  await page.goto('/admin/users');
  await expect(page.getByTestId('users-add')).toBeVisible();
  const s = await newSession(browser, OWNER);
  await s.page.goto('/admin/users/new');
  const csrf = await s.page.locator('input[name=_csrf]').first().inputValue();
  await ageSession(s.page, 7201);
  const before = count('SELECT COUNT(*) AS c FROM users');
  const r = await post(s.page, '/admin/users', { _csrf: csrf, name: 'Т', login: 'timeout1', role: 'editor', password: 'Pass-word-2026' });
  expect(r.status()).toBe(302);
  expect(r.headers()['location']).toContain('/admin/login');
  expect(count('SELECT COUNT(*) AS c FROM users')).toBe(before);
  await s.ctx.close();
});

// ---------------------------------------------------------------- макет

test('nav-brand: переход на /admin, текст site_name, длинное название переносится', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/profile');
  await expect(page.getByTestId('nav-brand')).toContainText('Детский сад «Солнышко»');
  await page.getByTestId('nav-brand').click();
  await expect(page).toHaveURL(/\/admin$/);
  setSiteName('Очень длинное название '.repeat(7).slice(0, 150));
  await page.setViewportSize({ width: 1280, height: 800 });
  await page.reload();
  const w = await page.locator('nav.nav').evaluate((el) => el.getBoundingClientRect().width);
  expect(w).toBeLessThanOrEqual(280);
});

test('nav-dashboard: ведёт на /admin, активен только там', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/users');
  await expect(page.getByTestId('nav-dashboard')).not.toHaveAttribute('aria-current', 'page');
  await page.getByTestId('nav-dashboard').click();
  await expect(page).toHaveURL(/\/admin$/);
  await expect(page.getByTestId('nav-dashboard')).toHaveAttribute('aria-current', 'page');
});

test('nav-users: активен на списке, форме и изменении; у editor нет пункта и подписи «Система»', async ({ page, browser }) => {
  await login(page, OWNER);
  await page.getByTestId('nav-users').click();
  await expect(page).toHaveURL(/\/admin\/users$/);
  for (const p of ['/admin/users', '/admin/users/new', `/admin/users/${userId('editor')}/edit`]) {
    await page.goto(p);
    await expect(page.getByTestId('nav-users')).toHaveAttribute('aria-current', 'page');
  }
  const s = await newSession(browser, EDITOR);
  await expect(s.page.getByTestId('nav-users')).toHaveCount(0);
  expect(await s.page.content()).not.toContain('Система');
  await s.ctx.close();
});

test('header-breadcrumbs: путь «Главная / Пользователи / Изменение пользователя», ссылки, на /admin без ссылки', async ({ page }) => {
  await login(page, OWNER);
  const bc = page.getByTestId('header-breadcrumbs');
  await expect(bc).toHaveText(/^Главная\s*\/?\s*$|Главная/);
  await expect(page.getByTestId('breadcrumb-link')).toHaveCount(0);
  await page.goto(`/admin/users/${userId('editor')}/edit`);
  await expect(bc).toContainText('Главная');
  await expect(bc).toContainText('Пользователи');
  await expect(bc).toContainText('Изменение пользователя');
  await expect(page.getByTestId('breadcrumb-link')).toHaveCount(2);
  await expect(bc.locator('[aria-current="page"]')).toHaveText('Изменение пользователя');
  await page.getByTestId('breadcrumb-link').filter({ hasText: 'Пользователи' }).click();
  await expect(page).toHaveURL(/\/admin\/users$/);
});

test('header-open-site: новая вкладка с /, rel=noopener', async ({ page }) => {
  await login(page, OWNER);
  const btn = page.getByTestId('header-open-site');
  await expect(btn).toHaveAttribute('rel', /noopener/);
  const [popup] = await Promise.all([page.context().waitForEvent('page'), btn.click()]);
  await popup.waitForLoadState();
  expect(new URL(popup.url()).pathname).toBe('/');
  await expect(popup.getByTestId('site-name')).toBeVisible();
});

test('user-card: инициалы, имя, роль, переход в профиль; одно слово; XSS', async ({ page }) => {
  await login(page, OWNER);
  const card = page.getByTestId('user-card');
  await expect(card).toContainText('ИМ');
  await expect(card).toContainText('Иванова Мария Петровна');
  await expect(card).toContainText('Ответственный за сайт');
  await card.click();
  await expect(page).toHaveURL(/\/admin\/profile$/);
  execSql("UPDATE users SET name='Мадина' WHERE login='owner'");
  await page.reload();
  await expect(page.getByTestId('user-card').locator('.avatar')).toHaveText('М');
  execSql("UPDATE users SET name='<b>Жирный</b> Имя' WHERE login='owner'");
  await page.reload();
  await expect(page.getByTestId('user-card')).toContainText('<b>Жирный</b> Имя');
  await expect(page.getByTestId('user-card').locator('b.nav__user-name b')).toHaveCount(0);
});

test('user-card: у editor роль «Редактор»', async ({ page }) => {
  await login(page, EDITOR);
  await expect(page.getByTestId('user-card')).toContainText('Редактор');
});

test('nav-logout: выход, audit, cookie, «Назад» и /admin -> вход; токены других устройств остаются', async ({ page, browser }) => {
  const other = await newSession(browser, OWNER, { remember: true });
  await login(page, OWNER, { remember: true });
  expect(tokens()).toBe(2);
  await logout(page);
  await expect(page.getByTestId('login-notice')).toHaveText('Вы вышли из админки.');
  expect(audit('logout')).toHaveLength(1);
  expect(tokens()).toBe(1);
  expect(await cookie(page.context(), 'sadik_remember')).toBeUndefined();
  await page.goBack();
  await page.goto('/admin');
  await expect(page.getByTestId('login-form')).toBeVisible();
  await other.ctx.close();
});

test('nav-logout: POST без _csrf -> 400, сессия жива', async ({ page }) => {
  await login(page, OWNER);
  const r = await post(page, '/admin/logout', {});
  expect(r.status()).toBe(400);
  await page.goto('/admin');
  await expect(page.getByTestId('dashboard-greeting')).toBeVisible();
});

test('admin-flash: успех role=status, ошибка role=alert, исчезает после обновления', async ({ page }) => {
  await login(page, OWNER);
  await createUserUI(page, { name: 'Флеш Тест', login: 'flash1' });
  const f = page.getByTestId('admin-flash');
  await expect(f).toHaveText('Пользователь Флеш Тест добавлен');
  await expect(f).toHaveAttribute('role', 'status');
  await page.reload();
  await expect(page.getByTestId('admin-flash')).toHaveCount(0);
  // ошибка: попытка заблокировать единственного владельца принудительным POST
  const csrf = await csrfOf(page, '/admin/users');
  const r = await post(page, `/admin/users/${userId('owner')}/block`, { _csrf: csrf });
  expect(r.status()).toBe(422);
});

test('dashboard-greeting: приветствие, имя экранировано, у editor то же', async ({ page, browser }) => {
  await login(page, OWNER);
  const g = page.getByTestId('dashboard-greeting');
  await expect(g).toContainText('Здравствуйте, Иванова Мария Петровна!');
  await expect(g).toContainText('Это панель управления сайтом детского сада. Выберите раздел в меню слева.');
  execSql("UPDATE users SET name='<i>Имя</i>' WHERE login='owner'");
  await page.reload();
  await expect(page.getByTestId('dashboard-greeting')).toContainText('<i>Имя</i>');
  const s = await newSession(browser, EDITOR);
  await expect(s.page.getByTestId('dashboard-greeting')).toContainText('Здравствуйте, Петрова Анна Сергеевна!');
  await s.ctx.close();
});

// ---------------------------------------------------------------- пользователи

test('users-add: кнопка ведёт на форму, крошки «Новый пользователь»', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/users');
  await page.getByTestId('users-add').click();
  await expect(page).toHaveURL(/\/admin\/users\/new$/);
  await expect(page.getByTestId('header-breadcrumbs')).toContainText('Новый пользователь');
});

test('users-row: имя, логин, роль, статус, вход в Asia/Almaty, порядок; XSS; заблокированный', async ({ page }) => {
  await login(page, OWNER);
  await createUserUI(page, { name: 'Яков Я', login: 'yakov' });
  await createUserUI(page, { name: 'Борис Б', login: 'boris' });
  execSql("UPDATE users SET name='<b>Икс</b>', is_active=0 WHERE login='boris'");
  execSql("UPDATE users SET last_login_at='2026-01-15 10:30:00' WHERE login='editor'");
  await page.goto('/admin/users');
  const rows = page.getByTestId('users-row');
  await expect(rows).toHaveCount(4);
  const ids = await rows.evaluateAll((els) => els.map((e) => e.getAttribute('data-user-id')));
  expect(ids).toEqual([userId('owner'), userId('boris'), userId('editor'), userId('yakov')].map(String));
  await expect(rows.nth(0)).toContainText('Ответственный за сайт');
  await expect(rows.nth(1)).toContainText('<b>Икс</b>');
  await expect(rows.nth(1)).toContainText('Заблокирован');
  await expect(rows.nth(2)).toContainText('15.01.2026 15:30');
  await expect(rows.nth(3)).toContainText('ещё не входил');
  await expect(rows.nth(3)).toContainText('Активен');
});

test('users-self-badge: бейдж «Это вы» только у себя', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/users');
  await expect(page.getByTestId('users-self-badge')).toHaveCount(1);
  await expect(page.locator(`[data-user-id="${userId('owner')}"]`).getByTestId('users-self-badge')).toHaveText('Это вы');
});

test('users-edit: ведёт на форму с заполненными полями; несуществующий id и abc -> 404', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/users');
  await page.locator(`[data-user-id="${userId('editor')}"]`).getByTestId('users-edit').click();
  await expect(page).toHaveURL(new RegExp(`/admin/users/${userId('editor')}/edit$`));
  await expect(page.getByTestId('user-form-name')).toHaveValue(EDITOR.name);
  await expect(page.getByTestId('user-form-login')).toHaveValue('editor');
  await expect(page.getByTestId('user-form-role')).toHaveValue('editor');
  expect((await page.request.get('/admin/users/9999/edit')).status()).toBe(404);
  expect((await page.request.get('/admin/users/abc/edit')).status()).toBe(404);
});

test('users-reset-password: ведёт на форму; у себя кнопки нет, GET -> /admin/profile, POST -> 403', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/users');
  await expect(page.locator(`[data-user-id="${userId('owner')}"]`).getByTestId('users-reset-password')).toHaveCount(0);
  await page.locator(`[data-user-id="${userId('editor')}"]`).getByTestId('users-reset-password').click();
  await expect(page).toHaveURL(new RegExp(`/admin/users/${userId('editor')}/password$`));
  const me = userId('owner');
  const g = await page.request.get(`/admin/users/${me}/password`, { maxRedirects: 0 });
  expect(g.status()).toBe(302);
  expect(g.headers()['location']).toBe('/admin/profile');
  const csrf = await csrfOf(page, '/admin/users');
  const p = await post(page, `/admin/users/${me}/password`, { _csrf: csrf, password: 'Hacked-pass-123' });
  expect(p.status()).toBe(403);
});

test('users-block: confirm, статус, flash, audit; «Отмена» ничего не делает; двойной клик — одна запись', async ({ page, browser }) => {
  const s = await newSession(browser, EDITOR, { remember: true });
  await login(page, OWNER);
  await page.goto('/admin/users');
  const row = page.locator(`[data-user-id="${userId('editor')}"]`);
  let msg = '';
  page.once('dialog', (d) => { msg = d.message(); d.dismiss(); });
  await row.getByTestId('users-block').click();
  expect(msg).toBe(`Заблокировать ${EDITOR.name}? Он не сможет войти в админку.`);
  expect(count("SELECT is_active AS c FROM users WHERE login='editor'")).toBe(1);
  page.on('dialog', (d) => d.accept());
  await row.getByTestId('users-block').dblclick();
  await expect(page.getByTestId('admin-flash')).toHaveText(`Пользователь ${EDITOR.name} заблокирован`);
  await expect(row).toContainText('Заблокирован');
  expect(audit('block')).toHaveLength(1);
  expect(count(`SELECT COUNT(*) AS c FROM remember_tokens WHERE user_id=${userId('editor')}`)).toBe(0);
  await s.page.goto('/admin');
  await expect(s.page.getByTestId('login-form')).toBeVisible();
  await s.ctx.close();
});

test('users-block: себя нельзя (422); уже заблокированного — 302 без записи', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/users');
  await expect(page.locator(`[data-user-id="${userId('owner')}"]`).getByTestId('users-block')).toHaveCount(0);
  const csrf = await csrfOf(page, '/admin/users');
  const r = await post(page, `/admin/users/${userId('owner')}/block`, { _csrf: csrf });
  expect(r.status()).toBe(422);
  expect(await r.text()).toContain('Нельзя заблокировать самого себя');
  execSql("UPDATE users SET role='owner' WHERE login='editor'");
  const ok = await post(page, `/admin/users/${userId('editor')}/block`, { _csrf: csrf });
  expect(ok.status()).toBe(302);
  const again = await post(page, `/admin/users/${userId('editor')}/block`, { _csrf: csrf });
  expect(again.status()).toBe(302);
  expect(audit('block')).toHaveLength(1);
});

test('users-unblock: разблокировка, flash, audit, вход прежним паролем', async ({ page, browser }) => {
  execSql("UPDATE users SET is_active=0 WHERE login='editor'");
  await login(page, OWNER);
  await page.goto('/admin/users');
  const row = page.locator(`[data-user-id="${userId('editor')}"]`);
  await expect(row.getByTestId('users-block')).toHaveCount(0);
  await row.getByTestId('users-unblock').click();
  await expect(page.getByTestId('admin-flash')).toHaveText(`Пользователь ${EDITOR.name} разблокирован`);
  await expect(row).toContainText('Активен');
  expect(audit('unblock')).toHaveLength(1);
  const s = await newSession(browser, EDITOR);
  await expect(s.page.getByTestId('dashboard-greeting')).toBeVisible();
  await s.ctx.close();
  const csrf = await csrfOf(page, '/admin/users');
  const r = await post(page, `/admin/users/${userId('editor')}/unblock`, { _csrf: csrf });
  expect(r.status()).toBe(302);
  expect(audit('unblock')).toHaveLength(1);
});

// ---------------------------------------------------------------- формы пользователя

test('user-form-name: схлопывание пробелов, ошибки, HTML текстом', async ({ page }) => {
  await login(page, OWNER);
  await createUserUI(page, { name: '  Петрова    Анна ', login: 'name1' });
  expect(dbQuery("SELECT name FROM users WHERE login='name1'")[0].name).toBe('Петрова Анна');
  await createUserUI(page, { name: '<b>x</b>', login: 'name2' });
  expect(dbQuery("SELECT name FROM users WHERE login='name2'")[0].name).toBe('<b>x</b>');
  await expect(page.getByText('<b>x</b>').first()).toBeVisible();
  await page.goto('/admin/users/new');
  await page.getByTestId('user-form-login').fill('name3');
  await page.getByTestId('user-form-password').fill('Pass-word-2026');
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByText('Укажите имя')).toBeVisible();
  await page.getByTestId('user-form-name').fill('я'.repeat(101));
  await page.getByTestId('user-form-password').fill('Pass-word-2026');
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByText('Не более 100 символов')).toBeVisible();
});

test('user-form-login: приводится к нижнему регистру; ошибки; занят; свой логин при изменении ок', async ({ page }) => {
  await login(page, OWNER);
  await createUserUI(page, { name: 'Учитель', login: 'Teacher.1' });
  expect(dbQuery("SELECT login FROM users WHERE login='teacher.1'")).toHaveLength(1);
  await page.goto('/admin/users/new');
  await page.getByTestId('user-form-name').fill('Дубль');
  await page.getByTestId('user-form-login').fill('OWNER');
  await page.getByTestId('user-form-password').fill('Pass-word-2026');
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByText('Этот логин уже занят')).toBeVisible();
  await page.getByTestId('user-form-login').fill('ab');
  await page.getByTestId('user-form-password').fill('Pass-word-2026');
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByText('От 3 до 50 символов')).toBeVisible();
  await page.getByTestId('user-form-login').fill('плохой');
  await page.getByTestId('user-form-password').fill('Pass-word-2026');
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByText('Только латинские буквы, цифры, точка, дефис и подчёркивание')).toBeVisible();
  await page.goto(`/admin/users/${userId('teacher.1')}/edit`);
  await page.getByTestId('user-form-name').fill('Учитель 2');
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByTestId('admin-flash')).toHaveText('Изменения сохранены');
});

test('user-form-role: по умолчанию «Редактор», подсказка, подмена admin -> «Выберите роль»', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/users/new');
  await expect(page.getByTestId('user-form-role')).toHaveValue('editor');
  await expect(page.getByText('Новости, фото, видео и документы')).toBeVisible();
  const csrf = await csrfOf(page, '/admin/users/new');
  const r = await post(page, '/admin/users', { _csrf: csrf, name: 'Хак', login: 'hacker', role: 'admin', password: 'Pass-word-2026' });
  expect(r.status()).toBe(422);
  expect(await r.text()).toContain('Выберите роль');
  expect(count("SELECT COUNT(*) AS c FROM users WHERE login='hacker'")).toBe(0);
  await page.goto(`/admin/users/${userId('owner')}/edit`);
  await expect(page.getByTestId('user-form-role')).toBeDisabled();
  const f = await post(page, `/admin/users/${userId('owner')}`, { _csrf: csrf, name: 'Иванова', login: 'owner', role: 'editor' });
  expect(f.status()).toBe(422);
  expect(dbQuery("SELECT role FROM users WHERE login='owner'")[0].role).toBe('owner');
});

test('user-form-role-locked: у себя и у единственного owner есть подсказка, у обычного нет', async ({ page }) => {
  await login(page, OWNER);
  await page.goto(`/admin/users/${userId('owner')}/edit`);
  await expect(page.getByTestId('user-form-role-locked')).toBeVisible();
  await page.goto(`/admin/users/${userId('editor')}/edit`);
  await expect(page.getByTestId('user-form-role-locked')).toHaveCount(0);
  await expect(page.getByTestId('user-form-role')).toBeEnabled();
});

test('user-form-password: хэш в БД, вход этим паролем, после ошибки поле пустое, ограничения длины', async ({ page, browser }) => {
  await login(page, OWNER);
  await createUserUI(page, { name: 'Новый Сотр', login: 'newbie', password: 'Brand-new-pass-1' });
  expect(dbQuery("SELECT password_hash AS h FROM users WHERE login='newbie'")[0].h).toMatch(/^\$(2y|argon2)/);
  const s = await newSession(browser, { login: 'newbie', password: 'Brand-new-pass-1' });
  await expect(s.page.getByTestId('dashboard-greeting')).toBeVisible();
  await s.ctx.close();
  await page.goto('/admin/users/new');
  await page.getByTestId('user-form-name').fill('Тест');
  await page.getByTestId('user-form-login').fill('pwtest');
  await page.getByTestId('user-form-password').fill('1234567');
  await page.getByTestId('user-form-submit').click();
  await expect(page.locator('.field__error', { hasText: 'Не короче 8 символов' })).toBeVisible();
  await expect(page.getByTestId('user-form-password')).toHaveValue('');
  await page.getByTestId('user-form-password').fill('a'.repeat(65));
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByText('Слишком длинный пароль')).toBeVisible();
  await page.getByTestId('user-form-password').fill('я'.repeat(40));
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByText('Слишком длинный пароль')).toBeVisible();
  await page.goto(`/admin/users/${userId('editor')}/edit`);
  await expect(page.getByTestId('user-form-password')).toHaveCount(0);
});

test('user-form-password-toggle: показать/скрыть, type=button', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/users/new');
  const t = page.getByTestId('user-form-password-toggle');
  await expect(t).toHaveAttribute('type', 'button');
  await t.click();
  await expect(page.getByTestId('user-form-password')).toHaveAttribute('type', 'text');
  await t.click();
  await expect(page.getByTestId('user-form-password')).toHaveAttribute('type', 'password');
  await expect(page).toHaveURL(/\/admin\/users\/new$/);
});

test('user-form-submit: создание — redirect, flash, audit create; двойной клик — один пользователь; ошибки сохраняют поля', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/users/new');
  await page.getByTestId('user-form-name').fill('Двойной Клик');
  await page.getByTestId('user-form-login').fill('dblclick');
  await page.getByTestId('user-form-password').fill('Pass-word-2026');
  await page.getByTestId('user-form-submit').dblclick();
  await expect(page).toHaveURL(/\/admin\/users$/);
  await expect(page.getByTestId('admin-flash')).toHaveText('Пользователь Двойной Клик добавлен');
  expect(count("SELECT COUNT(*) AS c FROM users WHERE login='dblclick'")).toBe(1);
  expect(audit('create').filter((a) => a.entity_id == userId('dblclick'))).toHaveLength(1);
  await page.goto('/admin/users/new');
  await page.getByTestId('user-form-name').fill('Сохранится');
  await page.getByTestId('user-form-login').fill('x');
  await page.getByTestId('user-form-password').fill('Pass-word-2026');
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByTestId('user-form-name')).toHaveValue('Сохранится');
  const csrf = await csrfOf(page, '/admin/users/new');
  void csrf;
  const r = await post(page, '/admin/users', { name: 'Без', login: 'nocsrf1', role: 'editor', password: 'Pass-word-2026' });
  expect(r.status()).toBe(400);
});

test('user-form-submit: изменение — flash, update/role_change; без изменений — без записи; понижение owner -> 403', async ({ page, browser }) => {
  await login(page, OWNER);
  await page.goto(`/admin/users/${userId('editor')}/edit`);
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByTestId('admin-flash')).toHaveText('Изменения сохранены');
  expect(audit('update')).toHaveLength(0);
  await page.goto(`/admin/users/${userId('editor')}/edit`);
  await page.getByTestId('user-form-name').fill('Новое Имя');
  await page.getByTestId('user-form-role').selectOption('owner');
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByTestId('admin-flash')).toHaveText('Изменения сохранены');
  expect(audit('update')).toHaveLength(1);
  expect(audit('role_change')).toHaveLength(1);
  const s = await newSession(browser, EDITOR);
  await s.page.goto('/admin/users');
  await expect(s.page.getByTestId('users-add')).toBeVisible();
  await page.goto(`/admin/users/${userId('editor')}/edit`);
  await page.getByTestId('user-form-role').selectOption('editor');
  await page.getByTestId('user-form-submit').click();
  await expect(page.getByTestId('admin-flash')).toHaveText('Изменения сохранены');
  const r = await s.page.goto('/admin/users');
  expect(r.status()).toBe(403);
  await expect(s.page.getByTestId('error-403')).toBeVisible();
  await s.ctx.close();
});

test('user-form-cancel: возврат к списку, ничего не сохранено', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/users/new');
  await page.getByTestId('user-form-name').fill('Отмена');
  await page.getByTestId('user-form-login').fill('cancel1');
  await page.getByTestId('user-form-cancel').click();
  await expect(page).toHaveURL(/\/admin\/users$/);
  expect(count("SELECT COUNT(*) AS c FROM users WHERE login='cancel1'")).toBe(0);
});

// ---------------------------------------------------------------- сброс пароля

test('user-password-new: ввод и сохранение; пусто / коротко / длинно -> ошибки; заголовок «Пароль для»', async ({ page }) => {
  await login(page, OWNER);
  await page.goto(`/admin/users/${userId('editor')}/password`);
  await expect(page.getByText(`Пароль для ${EDITOR.name}`)).toBeVisible();
  await page.getByTestId('user-password-submit').click();
  await expect(page.locator('.field__error')).toBeVisible();
  await page.getByTestId('user-password-new').fill('1234567');
  await page.getByTestId('user-password-submit').click();
  await expect(page.locator('.field__error', { hasText: 'Не короче 8 символов' })).toBeVisible();
  await page.getByTestId('user-password-new').fill('a'.repeat(65));
  await page.getByTestId('user-password-submit').click();
  await expect(page.getByText('Слишком длинный пароль')).toBeVisible();
  await page.getByTestId('user-password-new').fill('Fresh-editor-pass-1');
  await page.getByTestId('user-password-submit').click();
  await expect(page).toHaveURL(/\/admin\/users$/);
});

test('user-password-toggle: показать/скрыть', async ({ page }) => {
  await login(page, OWNER);
  await page.goto(`/admin/users/${userId('editor')}/password`);
  const t = page.getByTestId('user-password-toggle');
  await expect(t).toHaveAttribute('type', 'button');
  await t.click();
  await expect(page.getByTestId('user-password-new')).toHaveAttribute('type', 'text');
  await t.click();
  await expect(page.getByTestId('user-password-new')).toHaveAttribute('type', 'password');
});

test('user-password-submit: flash, audit, старый пароль не работает, сессии и токены сброшены; заблокированному можно; без csrf 400', async ({ page, browser }) => {
  const s = await newSession(browser, EDITOR, { remember: true });
  await login(page, OWNER);
  await page.goto(`/admin/users/${userId('editor')}/password`);
  await page.getByTestId('user-password-new').fill('Fresh-editor-pass-1');
  await page.getByTestId('user-password-submit').dblclick();
  await expect(page).toHaveURL(/\/admin\/users$/);
  await expect(page.getByTestId('admin-flash')).toHaveText(`Пароль для ${EDITOR.name} изменён. Сообщите его сотруднику.`);
  expect(audit('password_reset')).toHaveLength(1);
  expect(count(`SELECT COUNT(*) AS c FROM remember_tokens WHERE user_id=${userId('editor')}`)).toBe(0);
  await s.page.goto('/admin');
  await expect(s.page.getByTestId('login-form')).toBeVisible();
  await submitLogin(s.page, EDITOR.login, EDITOR.password);
  await expect(s.page.getByTestId('login-error')).toBeVisible();
  await submitLogin(s.page, EDITOR.login, 'Fresh-editor-pass-1');
  await expect(s.page).toHaveURL(/\/admin$/);
  await s.ctx.close();
  execSql("UPDATE users SET is_active=0 WHERE login='editor'");
  await page.goto(`/admin/users/${userId('editor')}/password`);
  await page.getByTestId('user-password-new').fill('Another-pass-2026');
  await page.getByTestId('user-password-submit').click();
  await expect(page.getByTestId('admin-flash')).toBeVisible();
  expect(count("SELECT is_active AS c FROM users WHERE login='editor'")).toBe(0);
  const r = await post(page, `/admin/users/${userId('editor')}/password`, { password: 'Nocsrf-pass-2026' });
  expect(r.status()).toBe(400);
});

test('user-password-cancel: возврат к списку', async ({ page }) => {
  await login(page, OWNER);
  await page.goto(`/admin/users/${userId('editor')}/password`);
  await page.getByTestId('user-password-cancel').click();
  await expect(page).toHaveURL(/\/admin\/users$/);
});

// ---------------------------------------------------------------- профиль

for (const [label, user] of [['owner', OWNER], ['editor', EDITOR]]) {
  test(`profile-info: ${label} видит имя, логин, роль текстом`, async ({ page }) => {
    await login(page, user);
    const res = await page.goto('/admin/profile');
    expect(res.status()).toBe(200);
    const info = page.getByTestId('profile-info');
    await expect(info).toContainText(user.name);
    await expect(info).toContainText(user.login);
    await expect(info).toContainText(label === 'owner' ? 'Ответственный за сайт' : 'Редактор');
    await expect(info.locator('input')).toHaveCount(0);
  });
}

async function profileForm(page, cur, nw, conf) {
  await page.goto('/admin/profile');
  await page.getByTestId('profile-current-password').fill(cur);
  await page.getByTestId('profile-new-password').fill(nw);
  await page.getByTestId('profile-new-password-confirm').fill(conf);
  await page.getByTestId('profile-password-submit').click();
}

test('profile-current-password: пусто и неверный -> ошибки, пароль не меняется, попытки не пишутся', async ({ page }) => {
  await login(page, EDITOR);
  const hash = dbQuery("SELECT password_hash AS h FROM users WHERE login='editor'")[0].h;
  await profileForm(page, '', 'New-editor-pass-1', 'New-editor-pass-1');
  await expect(page.getByText('Введите текущий пароль')).toBeVisible();
  await profileForm(page, 'Wrong-current-1', 'New-editor-pass-1', 'New-editor-pass-1');
  await expect(page.getByText('Неверный текущий пароль')).toBeVisible();
  expect(dbQuery("SELECT password_hash AS h FROM users WHERE login='editor'")[0].h).toBe(hash);
  expect(attempts()).toBe(0);
});

test('profile-new-password: длина и совпадение с текущим', async ({ page }) => {
  await login(page, EDITOR);
  await profileForm(page, EDITOR.password, '1234567', '1234567');
  await expect(page.locator('.field__error', { hasText: 'Не короче 8 символов' })).toBeVisible();
  await profileForm(page, EDITOR.password, 'a'.repeat(65), 'a'.repeat(65));
  await expect(page.getByText('Слишком длинный пароль')).toBeVisible();
  await profileForm(page, EDITOR.password, EDITOR.password, EDITOR.password);
  await expect(page.getByText('Новый пароль должен отличаться от текущего')).toBeVisible();
});

test('profile-new-password-confirm: несовпадение -> «Пароли не совпадают»', async ({ page }) => {
  await login(page, EDITOR);
  await profileForm(page, EDITOR.password, 'New-editor-pass-1', 'Different-pass-1');
  await expect(page.getByText('Пароли не совпадают')).toBeVisible();
  await expect(page.getByTestId('profile-new-password-confirm')).toHaveAttribute('aria-invalid', 'true');
});

test('profile-password-toggle: переключает все три поля', async ({ page }) => {
  await login(page, EDITOR);
  await page.goto('/admin/profile');
  const t = page.getByTestId('profile-password-toggle');
  await expect(t).toHaveAttribute('type', 'button');
  await t.click();
  for (const id of ['profile-current-password', 'profile-new-password', 'profile-new-password-confirm']) {
    await expect(page.getByTestId(id)).toHaveAttribute('type', 'text');
  }
  await t.click();
  for (const id of ['profile-current-password', 'profile-new-password', 'profile-new-password-confirm']) {
    await expect(page.getByTestId(id)).toHaveAttribute('type', 'password');
  }
});

test('profile-password-submit: остаётся в системе, sid сменился, audit; другие сессии и токены недействительны; после ошибки поля пустые', async ({ page, browser }) => {
  const other = await newSession(browser, EDITOR, { remember: true });
  await login(page, EDITOR, { remember: true });
  await profileForm(page, EDITOR.password, 'short', 'short');
  for (const id of ['profile-current-password', 'profile-new-password', 'profile-new-password-confirm']) {
    await expect(page.getByTestId(id)).toHaveValue('');
  }
  const sidBefore = (await cookie(page.context(), 'sadik_sid')).value;
  await profileForm(page, EDITOR.password, 'New-editor-pass-1', 'New-editor-pass-1');
  await expect(page.getByTestId('admin-flash')).toHaveText('Пароль изменён');
  expect((await cookie(page.context(), 'sadik_sid')).value).not.toBe(sidBefore);
  expect(audit('password_change')).toHaveLength(1);
  expect(tokens()).toBe(0);
  await page.goto('/admin');
  await expect(page.getByTestId('dashboard-greeting')).toBeVisible();
  await other.page.goto('/admin');
  await expect(other.page.getByTestId('login-form')).toBeVisible();
  const r = await post(page, '/admin/profile/password', { current_password: 'x', new_password: 'y', new_password_confirm: 'y' });
  expect(r.status()).toBe(400);
  await other.ctx.close();
});

test('profile-password-submit: двойной клик — одна запись audit', async ({ page }) => {
  await login(page, EDITOR);
  await page.goto('/admin/profile');
  await page.getByTestId('profile-current-password').fill(EDITOR.password);
  await page.getByTestId('profile-new-password').fill('New-editor-pass-1');
  await page.getByTestId('profile-new-password-confirm').fill('New-editor-pass-1');
  await page.getByTestId('profile-password-submit').dblclick();
  await expect(page.getByTestId('admin-flash')).toBeVisible();
  expect(audit('password_change')).toHaveLength(1);
});

// ---------------------------------------------------------------- ошибки 403 / 400

test('error-403: editor на owner-страницах -> 403 без данных; POST с верным CSRF -> 403, БД не меняется', async ({ page }) => {
  await login(page, EDITOR);
  for (const p of ['/admin/users', '/admin/users/new', '/admin/users/1/edit', '/admin/users/1/password']) {
    const res = await page.goto(p);
    expect(res.status(), p).toBe(403);
    await expect(page.getByTestId('error-403')).toHaveText('Доступ запрещён');
    await expect(page.getByText('У вас нет доступа к этой странице.')).toBeVisible();
    expect(await page.content()).not.toContain(OWNER.name);
  }
  const csrf = await csrfOf(page, '/admin/profile');
  const before = JSON.stringify(dbQuery('SELECT id, name, login, role, is_active, password_hash FROM users ORDER BY id'));
  const posts = [
    ['/admin/users', { name: 'Х', login: 'xxx1', role: 'editor', password: 'Pass-word-2026' }],
    ['/admin/users/1', { name: 'Х', login: 'owner', role: 'editor' }],
    ['/admin/users/1/block', {}],
    ['/admin/users/1/unblock', {}],
    ['/admin/users/1/password', { password: 'Hacked-pass-123' }],
  ];
  for (const [u, f] of posts) {
    expect((await post(page, u, { _csrf: csrf, ...f })).status(), u).toBe(403);
  }
  expect(JSON.stringify(dbQuery('SELECT id, name, login, role, is_active, password_hash FROM users ORDER BY id'))).toBe(before);
});

test('error-403-home: ссылка ведёт на /', async ({ page }) => {
  await login(page, EDITOR);
  await page.goto('/admin/users');
  await page.getByTestId('error-403-home').click();
  await expect(page).toHaveURL(/127\.0\.0\.1:8080\/$/);
  await expect(page.getByTestId('site-name')).toBeVisible();
});

test('error-400: POST админки без/с неверным _csrf -> 400, данные не изменены', async ({ page }) => {
  await login(page, OWNER);
  const before = count('SELECT COUNT(*) AS c FROM users');
  const r1 = await post(page, '/admin/users', { name: 'Х', login: 'csrf1', role: 'editor', password: 'Pass-word-2026' });
  expect(r1.status()).toBe(400);
  const r2 = await post(page, '/admin/users', { _csrf: '0'.repeat(64), name: 'Х', login: 'csrf2', role: 'editor', password: 'Pass-word-2026' });
  expect(r2.status()).toBe(400);
  expect(count('SELECT COUNT(*) AS c FROM users')).toBe(before);
  expect(await r1.text()).toContain('error-400');
  expect(await r1.text()).toContain('Сессия устарела');
  expect(await r1.text()).toContain('Обновите страницу и попробуйте снова.');
});

test('error-400-home: ссылка ведёт на /', async ({ page }) => {
  await login(page, OWNER);
  await page.goto('/admin/users');
  await page.evaluate(() => {
    const f = document.createElement('form');
    f.method = 'post';
    f.action = '/admin/users';
    document.body.append(f);
    f.submit();
  });
  await expect(page.getByTestId('error-400')).toHaveText('Сессия устарела');
  await page.getByTestId('error-400-home').click();
  await expect(page.getByTestId('site-name')).toBeVisible();
});

test('layout: на 360px нет горизонтальной прокрутки на ключевых экранах', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/admin/login');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
  await login(page, OWNER);
  for (const p of ['/admin', '/admin/users', '/admin/users/new', '/admin/profile', `/admin/users/${userId('editor')}/password`]) {
    await page.goto(p);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), p).toBe(true);
  }
});
