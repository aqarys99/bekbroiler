#!/usr/bin/env bash
# Запуск сайта для просмотра (Codespaces / локально): останавливает старые серверы и запускает новый на порту 8080.
cd "$(dirname "$0")/.." || exit 1
pkill -f "php .*-S 0.0.0.0:8080" 2>/dev/null
pkill -f "php -S" 2>/dev/null
sleep 1
echo "Сайт запускается на порту 8080. Откройте вкладку «Порты» → 8080 → значок глобуса."
echo "Остановить: control + C"
exec php -d upload_max_filesize=25M -d post_max_size=30M -S 0.0.0.0:8080 -t public public/index.php
