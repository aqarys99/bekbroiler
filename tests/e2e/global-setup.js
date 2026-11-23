// @ts-check
const path = require('path');
const { execFileSync } = require('child_process');

/** Генерация фикстур медиатеки (tests/fixtures/media) перед всеми тестами. */
module.exports = async () => {
  execFileSync('php', ['tests/fixtures/make-media.php'], { cwd: path.resolve(__dirname, '../..'), stdio: 'pipe' });
};
