// @ts-check
const { test } = require('@playwright/test');

// I-18 (установщик -> вход) проверяется в E2E фичи 02, когда появится форма входа.
// Часть фичи 01 (поиск владельца без учёта регистра, password_verify, роль owner)
// покрыта PHPUnit: tests/Feature/InstallerTest::testOwnerFoundCaseInsensitive.
test.skip('I-18: владелец из установщика входит тем же логином (фича 02)', async () => {});
