#!/usr/bin/env bash
# Ставит модули PHP, которые требует мастер установки: GD с WebP/JPEG, zip.
set -e
# В образе подключён репозиторий Yarn с просроченным ключом — он ломает apt-get update. Yarn не нужен.
sudo rm -f /etc/apt/sources.list.d/yarn.list /etc/apt/sources.list.d/yarn*.list
sudo apt-get update || echo "apt-get update завершился с предупреждениями — продолжаем"
sudo apt-get install -y --no-install-recommends libjpeg-dev libpng-dev libwebp-dev libfreetype6-dev libzip-dev
sudo docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype
sudo docker-php-ext-install -j"$(nproc)" gd zip
php -r 'echo "GD+WebP: ", (function_exists("imagewebp") ? "да" : "НЕТ"), PHP_EOL;'
php -m | grep -Ei '^(gd|zip|pdo_sqlite|fileinfo|mbstring|dom)$' || true
