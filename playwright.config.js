// @ts-check
const path = require('path');
const { defineConfig } = require('@playwright/test');

const storage = path.resolve(__dirname, 'storage/testing');

module.exports = defineConfig({
  testDir: 'tests/e2e',
  workers: 1,
  fullyParallel: false,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:8080',
  },
  webServer: {
    command: 'php -S 127.0.0.1:8080 -t public public/index.php',
    url: 'http://127.0.0.1:8080/install.php',
    reuseExistingServer: !process.env.CI,
    env: {
      APP_ENV: 'testing',
      APP_STORAGE_DIR: storage,
      APP_CONFIG_FILE: path.join(storage, 'config.php'),
    },
  },
});
