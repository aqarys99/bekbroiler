# Выкладка на хостинг (PS.kz и аналоги)

Требования: Apache + `.htaccess`, PHP 8.1+, расширения `pdo_sqlite`, `fileinfo`, `mbstring`, `gd` (с WebP) или `imagick`, желательно `zip`. Composer и Node на хостинге не нужны.

## Вариант А. Корень сайта = `public/` (рекомендуется)

1. Загрузите проект в `~/sadik/` (вне `public_html`).
2. В панели хостинга смените корень домена на `~/sadik/public` либо замените `public_html` символической ссылкой на `~/sadik/public` (SSH).
3. Откройте `https://домен/install.php`, пройдите проверку сервера и создайте первого пользователя.

Наружу видно только `public/`. `base_path` = `''`.

## Вариант Б. Корень сайта = корень проекта

Если корень домена сменить нельзя (PS.kz по умолчанию отдаёт `public_html/`):

1. Загрузите весь проект в `public_html/`.
2. Корневой `.htaccess` закрывает `app`, `storage`, `config`, `migrations`, `admin-views`, `bin`, `tests`, `vendor`, `docs`, точечные файлы и служебные расширения, а остальные запросы переписывает в `public/`. Прямой адрес `/public/...` перенаправляется на путь без `public`.
3. Дополнительно в каждой закрытой папке лежит свой `.htaccess` с `Require all denied` (работает и без `mod_rewrite`).
4. Откройте `https://домен/install.php`.

В пакет выкладки не включайте: `index.html` лендинга из корня репозитория (перенесётся в тему), `tests/`, `docs/`, `node_modules/`, `vendor/`, `.git*`.

Сайт в подпапке (`https://домен/sadik/`): установщик сам определит `base_path` (`/sadik`) и запишет его в `config/config.php`.

## Установка и обновление

- Установка: `install.php` проверяет сервер, создаёт БД (`storage/database.sqlite`), применяет миграции, записывает `config/config.php` и `storage/install.lock`.
- Обновление: загрузите новые файлы. Новые файлы `migrations/NNN_*.sql` применятся при первом же запросе; по SSH можно выполнить `php bin/migrate.php`.
- Повторная установка (только вручную, при необходимости): удалите `storage/install.lock` и `storage/database.sqlite` (и файлы `-wal`, `-shm`, `config/config.php`). Все данные будут потеряны.

## Права на файлы

`storage/`, `config/`, `public/uploads/` должны быть доступны на запись (755/775). `config.php`, `database.sqlite`, `install.lock` создаются с правами 0640; если хостинг запускает PHP от другого пользователя и чтение невозможно, используется 0644 (запись в лог).

## Проверка `.htaccess` вручную

Должны вернуть 403 или 404:

- `/storage/database.sqlite`
- `/config/config.php`
- `/app/helpers.php`
- `/migrations/001_core.sql`
- `/.git/HEAD`
- `/uploads/test.php` (положите тестовый файл и удалите после проверки)

Должны работать:

- `/install.php` (до установки — форма, после — 403 «Установка уже выполнена»)
- `/`
- `/admin`
- `/assets/admin/admin.css`
