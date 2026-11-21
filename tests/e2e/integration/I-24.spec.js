// @ts-check
const { test, expect } = require('@playwright/test');
const { resetApp, login, logout, ageSession, trackPageErrors, expectNoPageErrors } = require('../helpers');
const { OWNER, csrfOf, post, count, newSession } = require('../auth-helpers');

test.beforeEach(({ page }) => {
  resetApp({ installed: true, withEditor: true });
  trackPageErrors(page, { ignoreConsole: /status of 4\d\d/ });
});
test.afterEach(() => expectNoPageErrors());

const FORMS = (csrf) => [
  ['/admin/users', { _csrf: csrf, name: 'Т', login: 'i24user', role: 'editor', password: 'Pass-word-2026' }],
  ['/admin/users/2', { _csrf: csrf, name: 'Хак', login: 'editor', role: 'owner' }],
  ['/admin/users/2/block', { _csrf: csrf }],
  ['/admin/users/2/password', { _csrf: csrf, password: 'Hacked-pass-123' }],
  ['/admin/profile/password', { _csrf: csrf, current_password: 'Owner-pass-2026', new_password: 'Hacked-pass-123', new_password_confirm: 'Hacked-pass-123' }],
];
const snapshot = () => JSON.stringify(require('../helpers').dbQuery('SELECT id, name, login, role, is_active, password_hash FROM users ORDER BY id'));

test('I-24: POST после таймаута (2 ч) -> переход на вход, данные не сохранены, не 400/500', async ({ page }) => {
  await login(page, OWNER);
  const csrf = await csrfOf(page, '/admin/users/new');
  const before = snapshot();
  await ageSession(page, 7201);
  for (const [u, f] of FORMS(csrf)) {
    const r = await post(page, u, f);
    expect(r.status(), u).toBe(302);
    expect(r.headers()['location'], u).toContain('/admin/login');
  }
  expect(snapshot()).toBe(before);
});

test('I-24: POST после выхода в другом контексте -> переход на вход, данные не сохранены', async ({ page, browser }) => {
  const a = await newSession(browser, OWNER);
  await login(page, OWNER);
  const csrf = await csrfOf(page, '/admin/users/new');
  const before = snapshot();
  await logout(a.page);
  await a.ctx.close();
  await logout(page);
  for (const [u, f] of FORMS(csrf)) {
    const r = await post(page, u, f);
    expect(r.status(), u).toBe(302);
    expect(r.headers()['location'], u).toContain('/admin/login');
  }
  expect(snapshot()).toBe(before);
});

test('I-24: POST с неверным или без CSRF -> 400, данные не меняются', async ({ page }) => {
  await login(page, OWNER);
  const before = snapshot();
  for (const [u, f] of FORMS('0'.repeat(64))) {
    expect((await post(page, u, f)).status(), u).toBe(400);
    const { _csrf, ...noToken } = f;
    expect((await post(page, u, noToken)).status(), u + ' no token').toBe(400);
  }
  expect(snapshot()).toBe(before);
  expect(count('SELECT COUNT(*) AS c FROM users')).toBe(2);
});
