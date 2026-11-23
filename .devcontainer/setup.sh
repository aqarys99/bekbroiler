#!/usr/bin/env bash
# Ставит модули PHP, которые требует мастер установки: GD с WebP/JPEG, zip.
set -e
# В образе подключён репозиторий Yarn с просроченным ключом — он ломает apt-get update. Yarn не нужен.
sudo rm -f /etc/apt/sources.list.d/yarn.list /etc/apt/sources.list.d/yarn*.list
sudo apt-get update || echo "apt-get update завершился с предупреждениями — продолжаем"
sudo apt-get install -y --no-install-recommends libjpeg-dev libpng-dev libwebp-dev libfreetype6-dev libzip-dev

# sudo сбрасывает переменные окружения — передаём путь к настройкам PHP явно.
INI_DIR="${PHP_INI_DIR:-/usr/local/etc/php}"
SCAN_DIR="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')"
[ -n "$SCAN_DIR" ] || SCAN_DIR="$INI_DIR/conf.d"
sudo mkdir -p "$SCAN_DIR"

if ! php -m | grep -qi '^gd$'; then
  sudo env PHP_INI_DIR="$INI_DIR" docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype
  sudo env PHP_INI_DIR="$INI_DIR" docker-php-ext-install -j"$(nproc)" gd || true
fi
if ! php -m | grep -qi '^zip$'; then
  sudo env PHP_INI_DIR="$INI_DIR" docker-php-ext-install -j"$(nproc)" zip || true
fi

# Если модули собраны, но не подключены — подключаем вручную.
EXT_DIR="$(php -r 'echo ini_get("extension_dir");')"
for ext in gd zip; do
  if ! php -m | grep -qi "^${ext}\$" && [ -f "$EXT_DIR/${ext}.so" ]; then
    echo "extension=${ext}" | sudo tee "$SCAN_DIR/docker-php-ext-${ext}.ini" >/dev/null
  fi
done

php -r 'echo "GD+WebP: ", (function_exists("imagewebp") ? "да" : "НЕТ"), PHP_EOL;'
php -r 'echo "zip: ", (class_exists("ZipArchive") ? "да" : "НЕТ"), PHP_EOL;'
