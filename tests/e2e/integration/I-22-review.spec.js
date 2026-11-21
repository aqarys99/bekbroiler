// @ts-check
// Дополнительные проверки по ревью фичи 02 (параллельность, кража remember-токена, профиль).
const { test, expect, request: pwRequest } = require('@playwright/test');
const { resetApp, execSql, dbQuery, login, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { OWNER, EDITOR, csrfOf, post, count, tokens, newSession } = require('../auth-helpers');

const BASE = 'http://127.0.0.1:8080';

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
});
test.afterEach(() => expectNoPageErrors());

/** Независимая сессия без браузера со своим CSRF. */
async function guestApi() {
  const api = await pwRequest.newContext({ baseURL: BASE });
  const html = await (await api.get('/admin/login')).text();
  const csrf = /name="_csrf" value="([0-9a-f]{64})"/.exec(html)[1];
  return { api, csrf };
}

test('I-22: параллельный подбор пароля owner — в login_attempts ровно 5 строк пары, остальные 429', async () => {
  const sessions = await Promise.all(Array.from({ length: 12 }, guestApi));
  const rs = await Promise.all(sessions.map((s) => s.api.post('/admin/login', {
    form: { _csrf: s.csrf, login: 'owner', password: 'Wrong-pass-123' }, maxRedirects: 0,
  })));
  const codes = rs.map((r) => r.status());
  expect(count("SELECT COUNT(*) AS c FROM login_attempts WHERE login='owner'")).toBe(5);
  expect(codes.filter((c) => c === 422).length).toBeLessThanOrEqual(4);
  expect(codes.filter((c) => c === 429).length).toBeGreaterThanOrEqual(8);
  expect(codes.every((c) => c === 422 || c === 429)).toBe(true);
  await Promise.all(sessions.map((s) => s.api.dispose()));
});

test('I-22: параллельный подбор с одного IP по разным логинам — не более 20 строк', async () => {
  const sessions = await Promise.all(Array.from({ length: 28 }, guestApi));
  const rs = await Promise.all(sessions.map((s, i) => s.api.post('/admin/login', {
    form: { _csrf: s.csrf, login: 'user' + i, password: 'Wrong-pass-123' }, maxRedirects: 0,
  })));
  expect(count('SELECT COUNT(*) AS c FROM login_attempts')).toBe(20);
  expect(rs.filter((r) => r.status() === 429).length).toBeGreaterThanOrEqual(8);
  await Promise.all(sessions.map((s) => s.api.dispose()));
});

test('I-22: кража remember-токена — владелец со старым validator запускает детекцию, сессия вора завершается', async ({ page, browser }) => {
  await login(page, OWNER, { remember: true });
  const c0 = (await page.context().cookies()).find((c) => c.name === 'sadik_remember');
  await page.context().clearCookies({ name: 'sadik_sid' });
  // злоумышленник входит украденным cookie
  const thief = await browser.newContext();
  await thief.addCookies([{ ...c0 }]);
  const tp = await thief.newPage();
  await tp.goto('/admin');
  await expect(tp.getByTestId('dashboard-greeting')).toBeVisible();
  // владелец приходит со старым validator
  await page.goto('/admin');
  await expect(page.getByTestId('login-form')).toBeVisible();
  expect(tokens()).toBe(0);
  // сессия вора недействительна при следующем запросе
  await tp.goto('/admin');
  await expect(tp.getByTestId('login-form')).toBeVisible();
  await thief.close();
});

test('I-22: 3 параллельных GET /admin с одним remember-cookie — токен не удаляется', async ({ page }) => {
  await login(page, OWNER, { remember: true });
  const c = (await page.context().cookies()).find((x) => x.name === 'sadik_remember');
  const apis = await Promise.all([0, 1, 2].map(async () => {
    const api = await pwRequest.newContext({ baseURL: BASE });
    await api.storageState(); // инициализация
    return api;
  }));
  const header = { cookie: `sadik_remember=${c.value}` };
  const rs = await Promise.all(apis.map((a) => a.get('/admin', { headers: header, maxRedirects: 0 })));
  for (const r of rs) expect([200, 302]).toContain(r.status());
  expect(rs.some((r) => r.status() === 200)).toBe(true);
  expect(tokens()).toBeGreaterThanOrEqual(1);
  await Promise.all(apis.map((a) => a.dispose()));
});

test('I-22: профиль — 5 неверных текущих паролей блокируют, 6-я с верным тоже отклонена', async ({ page }) => {
  await login(page, EDITOR);
  const hash = dbQuery("SELECT password_hash AS h FROM users WHERE login='editor'")[0].h;
  const csrf = await csrfOf(page, '/admin/profile');
  const body = (cur) => ({ _csrf: csrf, current_password: cur, new_password: 'New-editor-pass-1', new_password_confirm: 'New-editor-pass-1' });
  let last;
  for (let i = 0; i < 5; i++) last = await post(page, '/admin/profile/password', body('Wrong-current-' + i));
  expect([422, 429]).toContain(last.status());
  expect(await last.text()).toMatch(/Слишком много|Попробуйте через/);
  const sixth = await post(page, '/admin/profile/password', body(EDITOR.password));
  expect([422, 429]).toContain(sixth.status());
  expect(await sixth.text()).toMatch(/Слишком много|Попробуйте через/);
  expect(dbQuery("SELECT password_hash AS h FROM users WHERE login='editor'")[0].h).toBe(hash);
});

test('I-22: повторный вход с «Запомнить меня» на том же устройстве не увеличивает число токенов', async ({ page }) => {
  await login(page, OWNER, { remember: true });
  expect(tokens()).toBe(1);
  const c = (await page.context().cookies()).find((x) => x.name === 'sadik_remember');
  // выйти без удаления remember-cookie: сессия исчезает, cookie остаётся на устройстве
  await page.context().clearCookies();
  await page.goto('/admin/login');
  const csrf = await page.locator('input[name=_csrf]').first().inputValue();
  await page.context().addCookies([c]);
  const r = await post(page, '/admin/login', { _csrf: csrf, login: 'owner', password: OWNER.password, remember: '1' });
  expect(r.status()).toBe(302);
  expect(tokens()).toBe(1);
  void execSql; void newSession;
});
