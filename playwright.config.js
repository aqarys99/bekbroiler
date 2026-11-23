// @ts-check
const path = require('path');
const { defineConfig } = require('@playwright/test');

const storage = path.resolve(__dirname, 'storage/testing');

module.exports = defineConfig({
  testDir: 'tests/e2e',
  globalSetup: require.resolve('./tests/e2e/global-setup.js'),
  workers: 1,
  fullyParallel: false,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:8080',
  },
  webServer: {
    command: 'php -d upload_max_filesize=25M -d post_max_size=30M -S 127.0.0.1:8080 -t public public/index.php',
    url: 'http://127.0.0.1:8080/install.php',
    reuseExistingServer: !process.env.CI,
    env: {
      PHP_CLI_SERVER_WORKERS: '4',
      APP_ENV: 'testing',
      APP_STORAGE_DIR: storage,
      APP_CONFIG_FILE: path.join(storage, 'config.php'),
    },
  },
});
