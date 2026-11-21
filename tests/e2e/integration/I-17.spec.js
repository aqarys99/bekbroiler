// @ts-check
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');
const { ROOT, migrationVersions, resetApp, dbQuery, trackPageErrors, expectNoPageErrors } = require('../helpers');

test.beforeEach(({ page }) => trackPageErrors(page));
test.afterEach(() => expectNoPageErrors());

test('I-17: миграция 001 применена ровно один раз, повторные запросы и bin/migrate.php ничего не дублируют', async ({ page }) => {
  resetApp({ installed: true });
  for (let i = 0; i < 3; i++) {
    expect((await page.request.get('/')).status()).toBe(200);
  }
  expect(dbQuery('SELECT version FROM schema_migrations')).toEqual(migrationVersions().map((version) => ({ version })));
  const out = execFileSync('php', ['bin/migrate.php'], {
    cwd: ROOT,
    env: { ...process.env, APP_ENV: 'testing', APP_STORAGE_DIR: require('../helpers').STORAGE, APP_CONFIG_FILE: require('path').join(require('../helpers').STORAGE, 'config.php') },
  }).toString('utf8');
  expect(out).toContain('Нечего применять');
  expect(dbQuery('SELECT version FROM schema_migrations')).toEqual(migrationVersions().map((version) => ({ version })));
  // применение новой 002 и откат сбойной — в PHPUnit MigratorTest (в общую migrations/ из E2E класть нельзя)
});
