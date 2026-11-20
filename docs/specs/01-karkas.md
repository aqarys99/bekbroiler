# 01. Каркас

## Цель
Владелец садика загружает папку на хостинг, открывает `install.php`, проходит проверку сервера, вводит название садика и данные первого пользователя (`owner`) — система создаёт БД, применяет миграции, пишет конфиг и блокирует повторную установку. Разработчики получают ядро (роутер, БД, миграции, вид, ошибки, хелперы) и рабочую инфраструктуру тестов, на которой строятся все следующие фичи.

## Входит / не входит
- Входит:
  - Структура папок по ARCHITECTURE §4 (только то, что нужно ядру сейчас, см. «Файлы»).
  - Фронт-контроллер `public/index.php`, автозагрузчик без Composer, `app/bootstrap.php`.
  - `Router` (GET/POST, HEAD как GET, параметры в URL, 404/405, слеш в конце), `HttpException`, `redirect()`, `abort()`.
  - `Database` (PDO SQLite, `foreign_keys=ON`, WAL, `busy_timeout`), `Migrator` (таблица `schema_migrations`, файлы `migrations/NNN_*.sql`), CLI `bin/migrate.php`, автоприменение новых миграций на установленном сайте.
  - `Config` + `config/config.example.php`; `config/config.php` создаёт установщик.
  - Хелперы `e()`, `t()`, `url()`, `asset()`, `config()`, `csrf_token()`, `csrf_field()`, `csrf_verify()`; `Session` (только старт сессии с безопасными cookie — нужен для CSRF установщика).
  - `View` (шаблон в layout), `Logger`, `ErrorHandler` (dev — детали, prod — страница 500 без деталей, лог в `storage/logs`).
  - `Audit::log()` — запись в `audit_log` (первая запись делает установщик).
  - `.htaccess`: в `public/`, в `public/uploads/`, в корне репозитория (вариант «корень сайта = корень проекта»), запрещающие `.htaccess` в `app/`, `storage/`, `config/`, `migrations/`, `admin-views/`, `bin/`.
  - `install.php`: мастер установки (проверка требований и прав на запись, создание БД, миграции, первый `owner` с `password_hash`, настройка `site_name`, блокировка повторного запуска).
  - Временная страница-заглушка `/` и `/admin` после установки; страницы 404/405/500.
  - Dev-инфраструктура тестов: `composer.json` (только `require-dev` phpunit), `phpunit.xml`, `tests/bootstrap.php`, `tests/TestCase.php`, `package.json` (`@playwright/test` строго `1.63.0`), `playwright.config.js`, `tests/e2e/helpers.js`, `tests/e2e/bin/reset.php`, `tests/e2e/bin/db.php`.
  - `docs/DEPLOY.md` — два варианта выкладки на PS.kz (см. «Деплой»).
- Не входит:
  - Вход/выход, проверка паролей, защита от подбора (запись в `login_attempts`), роли, CSRF-проверка в формах админки, макет админки с меню — фича 02 (таблица `login_attempts` создаётся сейчас, но не используется).
  - Переключатель языка RU/ҚАЗ из `Login.dc.html`, `kk.php` — фича 13 (в 01 переключателя нет).
  - Заголовки безопасности (CSP и др.) — фича 12. Резервные копии — фича 12.
  - Темы `themes/*`, публичный сайт, кэш — фича 11. Настройки — фича 10.
  - Поиск `ffmpeg` установщиком — фича 07.
  - Изменение `.github/workflows/deploy.yml` (пока выкладывается только лендинг; переключить на приложение после фичи 02, см. «Вопросы»).

## Изменения БД
Новый файл `migrations/001_core.sql`. Таблицу `schema_migrations` создаёт сам `Migrator` (не миграция).

```sql
CREATE TABLE users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  name          TEXT    NOT NULL,
  login         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
  password_hash TEXT    NOT NULL,
  role          TEXT    NOT NULL DEFAULT 'editor' CHECK (role IN ('owner','editor')),
  is_active     INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0,1)),
  last_login_at TEXT    NULL,
  created_at    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE TABLE login_attempts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  ip         TEXT NOT NULL,
  login      TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_login_attempts_ip    ON login_attempts (ip, created_at);
CREATE INDEX idx_login_attempts_login ON login_attempts (login, created_at);

CREATE TABLE settings (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL            -- JSON; проверяется в коде (json_encode/json_decode), без CHECK json_valid
);

CREATE TABLE audit_log (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  action     TEXT    NOT NULL,   -- create / update / delete / publish / login / ...
  entity     TEXT    NOT NULL,   -- имя сущности в ед. числе: user, news, document, setting ...
  entity_id  INTEGER NULL,
  created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_audit_log_created ON audit_log (created_at);
CREATE INDEX idx_audit_log_entity  ON audit_log (entity, entity_id);
```

`schema_migrations`: `version TEXT PRIMARY KEY` (например `001`), `name TEXT NOT NULL` (имя файла), `applied_at TEXT NOT NULL`.

Соглашения для всех фич: время в БД — UTC, формат `Y-m-d H:i:s`; показ — в часовом поясе из `config('timezone')` (по умолчанию `Asia/Almaty`). Булевы — `INTEGER 0/1`. JSON — `TEXT`.

## Маршруты
| Метод | URL | Контроллер::метод | Роль | Что делает | Ответ |
|---|---|---|---|---|---|
| GET | `/install.php` | `InstallController::show` | гость | Не установлено → экран проверки требований + форма. Установлено (`storage/install.lock` есть) → экран «Установка уже выполнена» | 200 / 403 |
| POST | `/install.php` | `InstallController::store` | гость | Проверка CSRF, требований, полей → установка → экран «Готово». Установлено → экран «Установка уже выполнена», ничего не меняется | 200 / 400 (CSRF) / 422 (ошибки полей или требований) / 403 (установлено) / 500 |
| GET | `/` | `StubController::index` | гость | Заглушка: название садика из `settings.site_name` + «Админка будет доступна после фичи 02» | 200 |
| GET | `/{admin_path}` (по умолчанию `/admin`) | `StubController::index` | гость | То же самое (в фиче 02 заменяется на вход) | 200 |
| GET | `/__test/error` | `Testing\ErrorController::throw` | только при `APP_ENV=testing`, иначе маршрут не регистрируется | Бросает `RuntimeException('Test error')` для проверки страницы 500 и лога | 500 |
| любой | любой незарегистрированный путь | `Router` | — | Страница 404 | 404 |
| POST и др. | путь есть, метода нет (напр. `POST /`) | `Router` | — | Страница 405, заголовок `Allow: GET, HEAD` | 405 |
| любой, кроме `/install.php` и статики | любой | `bootstrap` | — | Сайт не установлен → редирект на `/install.php` | 302 |

`/install.php` — отдельный файл `public/install.php`, который подключает `app/bootstrap.php` и вызывает `InstallController` напрямую (не через роутер), чтобы работать даже при сломанном `.htaccess`.

## Ядро: контракты (backend-builder)

### Файлы (создать)
```
.htaccess                         ← вариант Б (корень сайта = корень проекта)
composer.json, phpunit.xml, package.json, playwright.config.js
public/index.php, public/install.php, public/.htaccess
public/assets/admin/admin.css, public/assets/admin/install.js
public/uploads/.htaccess, public/uploads/{images,videos,docs}/.gitkeep
app/.htaccess, app/autoload.php, app/bootstrap.php, app/routes.php, app/helpers.php
app/Core/{Config,Router,HttpException,Database,Migrator,Session,View,Logger,ErrorHandler,Requirements,Installer,Audit}.php
app/Controllers/{InstallController,StubController}.php, app/Controllers/Testing/ErrorController.php
app/lang/ru.php
admin-views/.htaccess
admin-views/layouts/auth.php      ← карточка по центру, стиль Login.dc.html
admin-views/install/{form,done,locked}.php, admin-views/stub.php
admin-views/errors/{404,405,500}.php
migrations/.htaccess, migrations/001_core.sql
bin/.htaccess, bin/migrate.php
storage/.htaccess, storage/{logs,cache,backups}/.gitkeep
config/.htaccess, config/config.example.php
tests/bootstrap.php, tests/TestCase.php, tests/Unit/*, tests/Feature/*
tests/e2e/helpers.js, tests/e2e/bin/reset.php, tests/e2e/bin/db.php, tests/e2e/01-karkas.spec.js
tests/e2e/integration/I-17..I-21.spec.js (часть связей — PHPUnit, см. «План тестов»)
docs/DEPLOY.md
```
`.gitignore` дописать: `/storage/install.lock`, `/storage/testing/`.

Отличие от ARCHITECTURE §4: `install.php` лежит в `public/` (иначе он недоступен из интернета при корне сайта = `public/`). В варианте Б корневой `.htaccess` переписывает `/install.php` → `public/install.php`.

### Автозагрузка и bootstrap
- `app/autoload.php`: `spl_autoload_register`, PSR-4 `App\` → `app/`. Composer в продакшене не нужен; `composer.json` содержит `autoload-dev` `Tests\` → `tests/` и `autoload` `App\` → `app/` (для удобства PHPUnit), но приложение работает без `vendor/`.
- `app/bootstrap.php`: константа `ROOT`, `date_default_timezone_set('UTC')`, `ErrorHandler::register()`, загрузка конфига, хелперы. Пути:
  - каталог хранилища: `getenv('APP_STORAGE_DIR') ?: ROOT.'/storage'`;
  - файл конфига: `getenv('APP_CONFIG_FILE') ?: ROOT.'/config/config.php'`;
  - переменные окружения учитываются всегда (на хостинге их нет), `APP_ENV=testing` дополнительно включает тестовые маршруты и файл подмены требований (см. Requirements).
- Если конфига нет → используется `config.example.php` в режиме `production`/`debug=false`, сайт считается не установленным.

### `public/index.php`
1. Для `php -S` (`PHP_SAPI === 'cli-server'`): если запрошен существующий файл внутри `public/` (не `index.php`) → `return false` (сервер отдаёт файл сам); путь `/uploads/…` с расширением `php|phtml|phar` → 404.
2. `require ../app/bootstrap.php`; `ob_start()`.
3. Не установлено → `302 /install.php` (с учётом `base_path`).
4. Установлено → `Migrator::migrate()` (применяет только новые файлы, см. ниже) → `Router::dispatch($method, $path)`.

### Config
- `config/config.example.php` возвращает массив:
  `env` (`production`|`development`|`testing`), `debug` (bool), `base_path` (`''`, без слеша в конце; для сайта в подпапке — `/sadik`), `admin_path` (`admin`), `timezone` (`Asia/Almaty`), `locale` (`ru`), `db` => [`driver` => `sqlite`, `path` => `storage/database.sqlite` (относительно `ROOT`, либо абсолютный)].
- `config('db.path', $default)` — доступ через точку. `db.driver` ≠ `sqlite` → исключение «Драйвер не поддерживается» (MySQL — вне этой фичи).
- Установщик пишет `config.php` через `var_export`, атомарно (временный файл + `rename`), права 0640; `env=production`, `debug=false`.

### Router
- `$router->get($pattern, $handler)`, `$router->post(...)`; `$handler` — `[Class::class, 'method']` или `Closure`; вызывается с `array $params`. Возвращённая строка выводится; `null` — контроллер вывел сам.
- Параметры: `{name}` → `[^/]+`; `{name:regex}` (напр. `{id:\d+}`, `{slug:[a-z0-9-]+}`). Значения передаются после `rawurldecode`.
- Путь берётся из `REQUEST_URI` без query-string и без `base_path`. Вариант Б: `SCRIPT_NAME` = `/public/index.php`, но `base_path` берётся только из конфига — префикс `/public` не появляется в URL.
- `HEAD` обрабатывается как `GET` (без тела).
- Слеш в конце (кроме `/`): GET/HEAD → `301` на путь без слеша (query сохраняется); POST — сопоставляется как без слеша.
- Нет совпадения по пути → `HttpException(404)`. Путь совпал, метод нет → `HttpException(405)` + `Allow` со списком методов.
- Маршруты, зарегистрированные дважды (метод + шаблон) → `LogicException` при регистрации.
- `abort(int $code)` бросает `HttpException`; `redirect(string $url, int $code = 302)` — заголовок `Location` + `exit`.

### Database
- `Database::connection(): PDO` — один объект на запрос. `ERRMODE_EXCEPTION`, `DEFAULT_FETCH_MODE=FETCH_ASSOC`, `EMULATE_PREPARES=false`.
- После подключения: `PRAGMA foreign_keys = ON`, `PRAGMA busy_timeout = 5000`, `PRAGMA journal_mode = WAL`, `PRAGMA synchronous = NORMAL`. Если `journal_mode` вернул не `wal` (файловая система хостинга не поддерживает) — работа продолжается, в лог пишется предупреждение.
- Методы: `query(string $sql, array $params = []): PDOStatement`, `fetch(...)`, `fetchAll(...)`, `fetchValue(...)`, `insert(string $table, array $data): int` (имена колонок — только из кода, значения — плейсхолдеры), `transaction(callable $fn)` (откат при исключении и проброс исключения).
- Каталог БД создаётся при необходимости; файл БД — права 0640.

### Migrator
- Файлы `migrations/*.sql`, учитываются только имена `^\d{3}_[a-z0-9_]+\.sql$`, порядок по номеру. Дубли номера → исключение.
- `pending(): array`, `migrate(): array` (возвращает применённые). Каждая миграция — в своей транзакции (`BEGIN IMMEDIATE`) вместе с записью в `schema_migrations`; ошибка → откат этой миграции, исключение с именем файла, следующие не применяются.
- Повторный запуск ничего не делает. Параллельные запросы: второй ждёт блокировку (`busy_timeout`) и после неё видит миграцию применённой — без дублей.
- На установленном сайте `public/index.php` вызывает `migrate()` каждый запрос; дешёвая проверка: сравнить список файлов с `schema_migrations` (один `SELECT`).
- `bin/migrate.php` (CLI, `PHP_SAPI === 'cli'`, иначе выход): применяет и печатает список, код выхода 1 при ошибке.

### Session и CSRF
- `Session::start()`: имя `sadik_sid`, cookie `HttpOnly`, `SameSite=Lax`, `Secure` при HTTPS, `use_strict_mode=1`. Запускается лениво из `csrf_token()`.
- `csrf_token()`: 64 hex (`bin2hex(random_bytes(32))`), хранится в сессии, один на сессию. `csrf_field()`: `<input type="hidden" name="_csrf" value="…">`. `csrf_verify(?string $token): bool` — `hash_equals`. Подключение проверки ко всем POST админки — фича 02; в 01 проверку вызывает `InstallController::store`.

### Хелперы (`app/helpers.php`)
- `e(mixed $v): string` — `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`; `null` → `''`; числа приводятся к строке.
- `t(string $key, array $replace = []): string` — словарь `app/lang/{locale}.php` (плоский массив `'раздел.ключ' => 'текст'`), подстановки `:name`. Нет ключа → возвращается сам ключ, при `debug=true` — запись `warning` в лог. Не экранирует: в шаблонах `e(t(...))`.
- `url(string $path = '/', array $query = []): string` — `base_path` + путь + `?query` (`http_build_query`).
- `asset(string $path): string` — `url('/assets/'.$path)` + `?v=filemtime` (если файл есть).
- `config(string $key, mixed $default = null)`.
- `admin_url(string $path = '')` — `url('/'.config('admin_path').$path)`.

### View
- `View::render(string $template, array $data = [], ?string $layout = 'layouts/auth'): string`. Шаблоны — `admin-views/{template}.php`; переменные через `extract` в изолированной области; layout получает `$content` (уже готовый HTML) и `$title`.
- Шаблон не найден → `RuntimeException` (→ 500). Имя шаблона с `..` → `InvalidArgumentException`.

### Logger и ErrorHandler
- `Logger::error|warning|info(string $msg, array $context = [])` → `storage/logs/app-YYYY-MM-DD.log`, строка: `[Y-m-d H:i:s] LEVEL сообщение {json контекста}`. Папка не доступна для записи → `error_log()`; исключение из логгера наружу не выходит.
- `ErrorHandler::register()`: `set_error_handler` (ошибки → `ErrorException`, с учётом `error_reporting()`), `set_exception_handler`, `register_shutdown_function` (фатальные `E_ERROR|E_PARSE|E_CORE_ERROR|E_COMPILE_ERROR`). `display_errors=0` всегда.
- `HttpException` 404/405/403 → соответствующая страница, в лог не пишется (404 — нет). Остальные исключения → лог `error` (класс, сообщение, файл:строка, трассировка, метод и URI; **тело POST не логируется** — там пароли) → очистка буферов вывода → `500`.
- `debug=true`: страница 500 содержит класс, сообщение, файл:строку и трассировку (всё через `e()`). `debug=false`: только общий текст, без путей и сообщений.
- Страницы ошибок рендерятся без обращения к БД; если `View` сам падает — выводится встроенный минимальный HTML с тем же текстом.

### Requirements
`Requirements::check(): array` — список `['key','label','status' => ok|warn|fail,'value','hint']`.
| key | Условие | Статус при невыполнении |
|---|---|---|
| `php` | `PHP_VERSION >= 8.1.0` | fail |
| `pdo_sqlite` | `extension_loaded('pdo_sqlite')` | fail |
| `fileinfo` | `extension_loaded('fileinfo')` | fail |
| `mbstring` | `extension_loaded('mbstring')` | fail |
| `image` | `gd` с `imagewebp` **или** `imagick` | fail |
| `zip` | `extension_loaded('zip')` (нужен резервным копиям, фича 12) | warn |
| `upload_max_filesize` | ≥ 64 МБ | warn |
| `post_max_size` | ≥ 64 МБ | warn |
| `writable_storage` | `storage/` (и `logs/`, `cache/`, `backups/` — создаются, если нет) доступны на запись | fail |
| `writable_config` | каталог `config/` доступен на запись (каталог файла `APP_CONFIG_FILE`) | fail |
| `writable_uploads` | `public/uploads/` доступен на запись | fail |

При `APP_ENV=testing`: если есть `{storage}/fake-requirements.json` (`{"pdo_sqlite":"fail","zip":"warn"}`), указанные статусы подменяются. В других окружениях файл игнорируется.

### Installer
`Installer::isInstalled(): bool` — существует `{storage}/install.lock`.
`Installer::install(array $input): int` (id владельца), по шагам:
1. Взять эксклюзивную блокировку `{storage}/install.lock.tmp` (`fopen 'x'` / `flock`); не удалось или `install.lock` уже есть → `AlreadyInstalledException` (→ экран «уже выполнена», 403).
2. Повторно проверить требования (fail → ошибка 422, ничего не создаётся).
3. Валидация (см. таблицу элементов). Логин: `trim` + `mb_strtolower`; имена: `trim`, схлопывание пробелов; пароль не обрезается.
4. Создать БД, `Migrator::migrate()`.
5. Если в `users` уже есть `owner` → ошибка «В базе уже есть владелец…», ничего не меняется.
6. В транзакции: `users` (role `owner`, `password_hash(PASSWORD_DEFAULT)`), `settings` `site_name` = JSON-строка, `Audit::log($ownerId, 'create', 'user', $ownerId)`.
7. Записать `config.php`, затем `install.lock` (JSON: `installed_at`, `migrations`).
8. Ошибка на шагах 4–7 → откат транзакции; если БД/конфиг созданы этим запуском — удалить их; лог `error`; пользователю — общий текст `install.error.failed` (422/500 не раскрывают детали при `debug=false`).
Повторная установка возможна только вручную: удалить `storage/install.lock` и `storage/database.sqlite` (написано в `docs/DEPLOY.md` и на экране «уже выполнена» — без путей к файлам, только «обратитесь к разработчику»).

## Интерфейс (frontend-builder)
- `admin-views/layouts/auth.php`: фон `#F4F5F7`, карточка белая, `max-width 440px` (для формы установки — `560px`), рамка `#E2E5EA`, радиус 20px, padding 36px 32px, тень `0 8px 24px rgba(28,34,48,.06)`, вверху логотип-солнце (SVG из `Login.dc.html`), заголовок 24px/700, подзаголовок `#5B6372`. Шрифт: `'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif` **без загрузки с Google Fonts** (иначе ошибки в консоли без интернета и утечка данных посетителей).
- Поля: высота ≥ 48px, рамка `#C9CED6`, радиус 10px, `<label for>` у каждого поля, ошибка поля — текст `#B42318` под полем + `aria-invalid="true"` + `aria-describedby`. Кнопка главная: 52px, `#1F5BD8`, белый текст, 600, 16px; ховер `#1747AE`.
- Список требований: строка = иконка + название + значение; `ok` — `#17703A` на `#E6F4EA`, `warn` — `#9A4D00` на `#FDF0E1`, `fail` — `#B42318` на `#FDECEA`; под `warn/fail` — подсказка `hint`.
- `public/assets/admin/install.js` (без сборки, без зависимостей): переключатель «показать пароль» для обоих полей пароля; блокировка кнопки «Установить» после первого `submit` (`disabled` + текст «Устанавливаем…»). Без JS форма работает полностью.
- Все тексты — через `t()`, все значения — через `e()`.
- Атрибут `lang="ru"` у `<html>`, `<title>` — `t('install.title')` / заголовок страницы ошибки.

## Элементы интерфейса (каждая кнопка)
| testid | Экран | Элемент | Действие пользователя | Ожидаемый результат | Ошибки/пограничные случаи |
|---|---|---|---|---|---|
| install-redirect | Любой URL до установки | (поведение) | Открыть `/`, `/admin`, `/abc` на неустановленном сайте | `302` на `/install.php`, открывается экран установки | `/assets/admin/admin.css` отдаётся как файл (200), без редиректа; `/install.php` не редиректит сам на себя |
| install-requirements | Установка | Блок «Проверка сервера» | Открыть `/install.php` | Видны все 11 строк из таблицы Requirements, у каждой `data-testid="install-req-{key}"` и `data-status="ok|warn|fail"` | Строки `warn` не блокируют установку |
| install-req-{key} | Установка | Строка требования (11 шт.) | — | Текст названия, текущее значение (версия PHP, лимит в МБ, «есть/нет», «запись разрешена/запрещена») | `fail` → красный цвет и подсказка, `warn` → оранжевый и подсказка |
| install-requirements-failed | Установка | Плашка «Сервер не готов к установке» | Открыть при хотя бы одном `fail` (через `fake-requirements.json`) | Красная плашка над формой, кнопка `install-submit` `disabled`, поля формы неактивны | Принудительный POST (в обход `disabled`) → 422, требования показаны, БД и конфиг не созданы |
| install-recheck | Установка | Кнопка «Проверить снова» | Нажать после исправления прав/расширений | Страница перезагружается (GET `/install.php`), статусы пересчитаны | Показывается всегда; это обычная ссылка GET, поэтому уже введённые в форму данные сбрасываются (форма пустая) |
| install-site-name | Установка | Поле «Название детского сада» | Ввести «Детский сад «Солнышко»» | Сохраняется в `settings.site_name`, показывается на заглушке | Пусто / только пробелы → «Укажите название детского сада»; < 2 или > 150 символов → «От 2 до 150 символов»; HTML `<b>x</b>` сохраняется как текст и выводится экранированным |
| install-owner-name | Установка | Поле «Ваше имя» | Ввести «Иванова Мария Петровна» | `users.name` | Пусто → «Укажите имя»; > 100 символов → «Не более 100 символов»; двойные пробелы схлопываются |
| install-login | Установка | Поле «Логин» | Ввести `Admin` | Сохраняется как `admin` | Пусто → «Укажите логин»; < 3 / > 50 → «От 3 до 50 символов»; символы вне `a-z 0-9 . _ -` (кириллица, пробел) → «Только латинские буквы, цифры, точка, дефис и подчёркивание» |
| install-password | Установка | Поле «Пароль» | Ввести пароль ≥ 8 символов | В БД только `password_hash`, проверяется `password_verify` | Пусто → «Придумайте пароль»; < 8 → «Не короче 8 символов»; > 64 символов или > 72 байт → «Слишком длинный пароль»; после ошибки поле пустое (пароль не возвращается в HTML) |
| install-password-confirm | Установка | Поле «Повторите пароль» | Ввести тот же пароль | Установка продолжается | Не совпадает → «Пароли не совпадают» (ошибка у этого поля) |
| install-password-toggle | Установка | Кнопка-глаз у пароля | Нажать | Оба поля пароля `type=text`, `aria-label` «Скрыть пароль», `aria-pressed=true`; повторное нажатие — обратно `password` | `type="button"` — форму не отправляет |
| install-submit | Установка | Кнопка «Установить» | Нажать при верно заполненных полях | Создаются БД, 4 таблицы + `schema_migrations` с `001`, пользователь `owner`, `settings.site_name`, запись `audit_log` (create/user), `config.php`, `install.lock`; показывается экран «Готово» | Любая ошибка полей → 422, ошибки у полей, введённые название/имя/логин сохранены, ничего не создано; двойной клик → кнопка блокируется после первого, в БД ровно 1 пользователь; две параллельные отправки → вторая получает экран «уже выполнена», 1 пользователь |
| install-error | Установка | Общая плашка ошибки | Отправить форму с неверным/отсутствующим `_csrf` | 400, плашка «Сессия устарела. Обновите страницу и попробуйте снова.», ничего не создано | Сбой при установке (напр. ошибка миграции) → плашка «Не удалось завершить установку…», файлы БД/конфига удалены, подробности только в логе; в БД уже есть `owner` → «В базе уже есть владелец…» |
| install-done | Установка → Готово | Заголовок «Установка завершена» | — | Текст: логин владельца (`e()`), напоминание запомнить пароль | Обновление страницы (повторный POST) → экран «уже выполнена», 403, второй пользователь не создан |
| install-go-admin | Установка → Готово | Кнопка «Перейти в админку» | Нажать | Переход на `/admin` (с `base_path`), открывается заглушка | — |
| install-locked | Установка уже выполнена | Сообщение «Установка уже выполнена» | Открыть `/install.php` (GET или POST) после установки | 403, форма и список требований не показываются, данные в БД не меняются | POST с валидным CSRF и новыми данными → 403, пароль владельца прежний |
| install-locked-admin | Установка уже выполнена | Ссылка «Открыть админку» | Нажать | Переход на `/admin` | — |
| stub-site-name | Заглушка `/` и `/admin` | Заголовок — название садика | Открыть после установки | Название из `settings.site_name`, экранированное | Название с `<script>` выводится текстом, скрипт не выполняется |
| stub-message | Заглушка | Текст «Установка завершена. Админка будет доступна после фичи 02.» | Открыть `/` или `/admin` | 200, текст виден | `/admin/` → 301 на `/admin`; `HEAD /` → 200 без тела; `POST /` → 405 |
| error-404 | Страница 404 | Заголовок «Страница не найдена» | Открыть `/net-takoy-stranicy` после установки | Код 404, текст «Возможно, адрес набран с ошибкой или страница удалена.» | `/uploads/x.php` под `php -S` → 404; путь с кириллицей/`%00` → 404, не 500 |
| error-404-home | Страница 404 | Ссылка «На главную» | Нажать | Переход на `/` | — |
| error-405 | Страница 405 | Заголовок «Метод не поддерживается» | `POST /` | Код 405, заголовок `Allow: GET, HEAD` | — |
| error-405-home | Страница 405 | Ссылка «На главную» | Нажать | Переход на `/` | — |
| error-500 | Страница 500 | Заголовок «Что-то пошло не так» | Открыть `/__test/error` при `debug=false` | Код 500, текст «Мы уже записали ошибку. Попробуйте обновить страницу через минуту.»; в HTML нет `Test error`, путей к файлам, слова `RuntimeException`; в `storage/logs/app-<дата>.log` есть строка с `Test error` и `/__test/error` | При `APP_ENV≠testing` маршрут `/__test/error` → 404 |
| error-500-details | Страница 500 (режим разработки) | Блок «Подробности» | Открыть `/__test/error` при `debug=true` | Виден класс `RuntimeException`, сообщение `Test error`, файл:строка и трассировка, всё экранировано | При `debug=false` блока нет в HTML |
| error-500-home | Страница 500 | Ссылка «На главную» | Нажать | Переход на `/` | — |
| error-403 | Страница 403 | Заголовок «Доступ запрещён» (`admin-views/errors/403.php`) и ссылка `error-403-home` «На главную» | Открыть `/install.php` после установки | Код 403, текст страницы ошибки, ссылка ведёт на `/` | — |

Итого строк: 26 (строка `install-req-{key}` покрывает 11 элементов).

## Связи с другими фичами
Существующие строки, которые фича затрагивает: нет прямых (I-14, I-15 опираются на таблицы `users` и `audit_log`, созданные здесь; их тестирует фича 02).

Новые строки в `docs/INTEGRATION.md`:
| ID | Откуда | Куда | Что должно происходить | Фича-владелец |
|---|---|---|---|---|
| I-17 | Миграции | Все фичи | Новый файл `migrations/NNN_*.sql` применяется на уже установленном сайте при первом запросе (и через `bin/migrate.php`) ровно один раз; сбой миграции откатывает её и не ломает остальные данные | 01 |
| I-18 | Установщик | Вход (02) | Владелец, созданный в `install.php`, входит в админку тем же логином (без учёта регистра) и паролем, роль `owner` | 01, 02 |
| I-19 | Установщик | Заглушка / шапка админки (02) / Настройки (10) / Публичный сайт (11) | Название садика из установщика хранится в `settings.site_name` и показывается везде из этого ключа; смена в Настройках меняет его во всех местах | 01, 02, 10, 11 |
| I-20 | Обработка ошибок | Все разделы | Исключение в любом контроллере → 500 без деталей при `debug=false` и запись в `storage/logs`; неизвестный URL → 404, чужой метод → 405 в общем стиле | 01 |
| I-21 | Установка | Все маршруты | До установки любой URL ведёт на `install.php`; после установки `install.php` отдаёт 403 и не может создать второго владельца или перезаписать конфиг | 01 |

## Тексты интерфейса
Ключи для `app/lang/ru.php`:
```
'app.name' => 'Сайт детского сада',
'install.title' => 'Установка сайта',
'install.subtitle' => 'Проверим сервер и создадим первого пользователя',
'install.requirements' => 'Проверка сервера',
'install.requirements_failed' => 'Сервер не готов к установке. Исправьте пункты, отмеченные красным, и нажмите «Проверить снова».',
'install.recheck' => 'Проверить снова',
'install.req.php' => 'PHP 8.1 или новее',
'install.req.pdo_sqlite' => 'Расширение pdo_sqlite (база данных)',
'install.req.fileinfo' => 'Расширение fileinfo (проверка файлов)',
'install.req.mbstring' => 'Расширение mbstring (русский и казахский текст)',
'install.req.image' => 'Обработка фото: GD с WebP или Imagick',
'install.req.zip' => 'Расширение zip (резервные копии)',
'install.req.upload_max_filesize' => 'Размер загружаемого файла (upload_max_filesize) от 64 МБ',
'install.req.post_max_size' => 'Размер запроса (post_max_size) от 64 МБ',
'install.req.writable_storage' => 'Запись в папку storage',
'install.req.writable_config' => 'Запись в папку config',
'install.req.writable_uploads' => 'Запись в папку public/uploads',
'install.req.yes' => 'есть',
'install.req.no' => 'нет',
'install.req.writable' => 'запись разрешена',
'install.req.not_writable' => 'запись запрещена',
'install.hint.extension' => 'Включите расширение в панели хостинга (раздел «Версия PHP» / «Расширения») или напишите в поддержку хостинга.',
'install.hint.php' => 'Выберите PHP 8.1 или новее в панели хостинга.',
'install.hint.limit' => 'Увеличьте значение в настройках PHP панели хостинга. Установка возможна, но крупные файлы не загрузятся.',
'install.hint.writable' => 'Дайте папке права на запись (755 или 775) в файловом менеджере хостинга.',
'install.section.site' => 'Детский сад',
'install.section.owner' => 'Первый пользователь (ответственный за сайт)',
'install.site_name' => 'Название детского сада',
'install.owner_name' => 'Ваше имя',
'install.login' => 'Логин',
'install.login_hint' => 'Латинские буквы, цифры, точка, дефис, подчёркивание',
'install.password' => 'Пароль',
'install.password_hint' => 'Не короче 8 символов',
'install.password_confirm' => 'Повторите пароль',
'install.password_show' => 'Показать пароль',
'install.password_hide' => 'Скрыть пароль',
'install.submit' => 'Установить',
'install.submitting' => 'Устанавливаем…',
'install.done.title' => 'Установка завершена',
'install.done.text' => 'Ваш логин: :login. Запомните пароль — восстановить его можно только через другого ответственного или разработчика.',
'install.done.go_admin' => 'Перейти в админку',
'install.locked.title' => 'Установка уже выполнена',
'install.locked.text' => 'Сайт уже установлен. Чтобы установить заново, обратитесь к разработчику.',
'install.locked.open_admin' => 'Открыть админку',
'install.error.csrf' => 'Сессия устарела. Обновите страницу и попробуйте снова.',
'install.error.failed' => 'Не удалось завершить установку. Ошибка записана в журнал. Попробуйте ещё раз или обратитесь к разработчику.',
'install.error.owner_exists' => 'В базе уже есть владелец. Повторная установка невозможна — обратитесь к разработчику.',
'install.error.requirements' => 'Сервер не готов к установке.',
'validation.site_name.required' => 'Укажите название детского сада',
'validation.site_name.length' => 'От 2 до 150 символов',
'validation.owner_name.required' => 'Укажите имя',
'validation.owner_name.length' => 'Не более 100 символов',
'validation.login.required' => 'Укажите логин',
'validation.login.length' => 'От 3 до 50 символов',
'validation.login.format' => 'Только латинские буквы, цифры, точка, дефис и подчёркивание',
'validation.password.required' => 'Придумайте пароль',
'validation.password.min' => 'Не короче 8 символов',
'validation.password.max' => 'Слишком длинный пароль',
'validation.password.confirm' => 'Пароли не совпадают',
'stub.title' => 'Сайт устанавливается',
'stub.message' => 'Установка завершена. Админка будет доступна после фичи 02.',
'error.404.title' => 'Страница не найдена',
'error.404.text' => 'Возможно, адрес набран с ошибкой или страница удалена.',
'error.405.title' => 'Метод не поддерживается',
'error.405.text' => 'Эта страница не принимает такой запрос.',
'error.500.title' => 'Что-то пошло не так',
'error.500.text' => 'Мы уже записали ошибку. Попробуйте обновить страницу через минуту.',
'error.500.details' => 'Подробности (видны только в режиме разработки)',
'error.403.title' => 'Доступ запрещён',
'error.home' => 'На главную',
```

## Деплой (описать в `docs/DEPLOY.md`)
**Вариант А — корень сайта = `public/` (рекомендуемый, если хостинг позволяет).** Проект кладётся в `~/sadik/` (вне `public_html`); в панели PS.kz корень домена меняется на `~/sadik/public`, либо `public_html` заменяется символической ссылкой на `~/sadik/public` (через SSH, если доступен). Наружу видно только `public/`. `base_path = ''`.

**Вариант Б — корень сайта = корень проекта (если корень сменить нельзя; PS.kz по умолчанию отдаёт `public_html/`).** Весь проект загружается в `public_html/`. Корневой `.htaccess`:
- `Options -Indexes`; `DirectoryIndex index.php`;
- `RewriteEngine On`; запрет (`[F]` → 403) путей `^(app|storage|config|migrations|admin-views|bin|tests|vendor|node_modules|docs|themes/[^/]+/[^/]+\.php)(/|$)` и файлов `\.(sqlite|sql|log|md|lock|json|xml|dist|js\.map)$` вне `public/`, а также любых путей, начинающихся с точки (`.git`, `.env`, `.github`);
- все остальные запросы: `RewriteCond %{REQUEST_URI} !^/public/` → `RewriteRule ^(.*)$ public/$1 [L]`; прямой запрос `/public/...` → `301` на тот же путь без `/public`.
- Дублирующая защита, работающая без `mod_rewrite`: в каждой закрытой папке свой `.htaccess` с `Require all denied` (и `<IfModule !mod_authz_core.c> Deny from all </IfModule>` для Apache 2.2).
- В пакет выкладки не входят: `index.html` лендинга из корня репозитория (перенесётся в тему в фиче 11), `tests/`, `docs/`, `node_modules/`, `vendor/`, `.git*`.

`public/.htaccess` (оба варианта): `Options -Indexes`, `RewriteEngine On`, `RewriteCond %{REQUEST_FILENAME} !-f` + `!-d` → `index.php [QSA,L]`, запрет точечных файлов.
`public/uploads/.htaccess`: `<FilesMatch "\.(php\d?|phtml|phar|pl|py|cgi|sh)$"> Require all denied </FilesMatch>`, `<IfModule mod_php.c> php_flag engine off </IfModule>` (только внутри `IfModule`, иначе 500 на PHP-FPM), `Options -Indexes -ExecCGI`.

Проверка `.htaccess` на Apache вручную (чек-лист в `DEPLOY.md`): `/storage/database.sqlite`, `/config/config.php`, `/app/helpers.php`, `/migrations/001_core.sql`, `/.git/HEAD`, `/uploads/test.php` → 403/404; `/install.php`, `/`, `/admin`, `/assets/admin/admin.css` → работают.

## Разделение работы
**backend-builder:** всё из «Ядро: контракты» — `app/` (кроме шаблонов), `public/index.php`, `public/install.php`, все `.htaccess`, `migrations/001_core.sql`, `bin/migrate.php`, `config/config.example.php`, `app/lang/ru.php` (все ключи выше), `composer.json`, `phpunit.xml`, `tests/bootstrap.php`, `tests/TestCase.php`, `package.json`, `playwright.config.js`, `tests/e2e/helpers.js`, `tests/e2e/bin/{reset,db}.php`, `.gitignore`, `docs/DEPLOY.md`.
**frontend-builder:** `admin-views/layouts/auth.php`, `admin-views/install/{form,done,locked}.php`, `admin-views/stub.php`, `admin-views/errors/{404,405,500}.php`, `public/assets/admin/admin.css` (CSS-переменные цветов из CLAUDE.md: `--bg`, `--text`, `--accent`, `--border`, `--ok/--ok-bg`, `--warn/--warn-bg`, `--danger/--danger-bg`), `public/assets/admin/install.js`. Все `data-testid` из таблицы элементов. Данные шаблонам передаёт backend: `$requirements`, `$hasFail`, `$errors` (поле → ключ перевода), `$old` (без паролей), `$generalError`, `$login`, `$siteName`, `$debug`, `$exception`.

### Тестовая инфраструктура (backend-builder)
- `composer.json`: `"require": {"php": ">=8.1"}`, `"require-dev": {"phpunit/phpunit": "^10.5"}` — больше ничего.
- `phpunit.xml`: наборы `Unit` (`tests/Unit`) и `Feature` (`tests/Feature`), `bootstrap="tests/bootstrap.php"`, `failOnWarning`, `failOnRisky`.
- `tests/bootstrap.php`: `vendor/autoload.php` + `app/autoload.php`; создаёт временный каталог `sys_get_temp_dir()/sadik-test-<random>`; выставляет `APP_ENV=testing`, `APP_STORAGE_DIR`, `APP_CONFIG_FILE`; удаляет каталог в `register_shutdown_function`. `tests/TestCase.php`: `freshDatabase()` (новый файл SQLite + все миграции), `tempDir()`.
- `package.json`: `"private": true`, `"devDependencies": {"@playwright/test": "1.63.0"}` (точно, без `^`/`~`), скрипт `"test:e2e": "playwright test --trace off"`.
- `playwright.config.js`: `testDir: 'tests/e2e'`, `workers: 1`, `fullyParallel: false` (общая БД), `use.baseURL: 'http://127.0.0.1:8080'`, `webServer: { command: 'php -S 127.0.0.1:8080 -t public public/index.php', url: 'http://127.0.0.1:8080/install.php', reuseExistingServer: !process.env.CI, env: { APP_ENV: 'testing', APP_STORAGE_DIR: <абс. путь storage/testing>, APP_CONFIG_FILE: <абс. путь storage/testing/config.php> } }`. Каталог `public/uploads` в тестах общий (для 01 не используется).
- `tests/e2e/bin/reset.php` (CLI, только `APP_ENV=testing` или флаг `--force-testing`, пути из тех же env): очищает `storage/testing`; опции `--installed` (вызывает `Installer::install` с `OWNER` из helpers), `--debug=0|1` (пишет в тестовый конфиг), `--fail=pdo_sqlite,zip:warn` (пишет `fake-requirements.json`).
- `tests/e2e/bin/db.php "<SELECT ...>"` — печатает JSON строк из тестовой БД; разрешён только `SELECT`.
- `tests/e2e/helpers.js`: `OWNER = { name, login: 'owner', password: 'Owner-pass-2026', siteName: 'Детский сад «Солнышко»' }`; `resetApp({ installed=false, debug=false, fail=[] })` (через `execFileSync('php', ['tests/e2e/bin/reset.php', ...])`); `dbQuery(sql)`; `trackPageErrors(page)` — собирает `console` уровня `error`, `pageerror` и ответы ≥ 500, `expectNoPageErrors()` в `afterEach` (тест `error-500` явно разрешает свой 500); `readLog()` — содержимое `storage/testing/logs/app-*.log`.

## Критерии приёмки
- [ ] Структура папок соответствует разделу «Файлы»; `find app public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l` — без ошибок на PHP 8.1 и 8.3.
- [ ] Приложение работает без `vendor/` (удалить `vendor/` → `/install.php` и `/` отвечают 200).
- [ ] На чистой копии `GET /` → 302 `/install.php`; после установки `GET /` → 200 с названием садика.
- [ ] Установка с верными данными: в БД таблицы `users`, `login_attempts`, `settings`, `audit_log`, `schema_migrations` (строка `001`); 1 пользователь `owner`, `password_verify(введённый пароль, hash) === true`, хэш начинается с `$2y$` или `$argon2`; `settings.site_name` — валидный JSON; 1 запись `audit_log` (`create`, `user`, id владельца); `config.php` содержит `debug => false`; есть `install.lock`.
- [ ] После установки `GET` и `POST /install.php` → 403, число пользователей и хэш пароля не меняются, `config.php` не перезаписан (mtime прежний).
- [ ] Двойной клик и 2 параллельных POST создают ровно 1 пользователя.
- [ ] Каждая ошибка валидации из таблицы элементов выводится у своего поля; при любой ошибке не создаются ни БД, ни конфиг, ни lock.
- [ ] POST без/с неверным `_csrf` → 400, ничего не создано.
- [ ] `PRAGMA foreign_keys` = 1 и `PRAGMA journal_mode` = `wal` на соединении из `Database::connection()` (на локальной ФС).
- [ ] Повторный `Migrator::migrate()` не применяет ничего; новая миграция `002_*.sql` применяется ровно 1 раз; миграция с ошибкой SQL откатывается целиком, `schema_migrations` её не содержит, предыдущие данные целы.
- [ ] Роутер: `{id:\d+}` не совпадает с `abc` (→ 404); `POST` на GET-маршрут → 405 + `Allow`; `/admin/` → 301 `/admin`; `HEAD /` → 200 без тела.
- [ ] `debug=false`: страница 500 не содержит сообщения исключения, имени класса и путей; в логе за текущую дату есть сообщение, файл:строка и URI; пароль из POST в лог не попадает.
- [ ] `debug=true`: страница 500 показывает класс, сообщение и трассировку.
- [ ] `e('<script>"\'&')` === `&lt;script&gt;&quot;&#039;&amp;`; `t('нет.ключа')` возвращает `нет.ключа`; `t('install.done.text', ['login'=>'a'])` подставляет логин; `url('/x', ['a'=>1])` с `base_path='/sadik'` → `/sadik/x?a=1`.
- [ ] Каждый интерактивный элемент экранов 01 имеет `data-testid` из таблицы; в `admin-views/` нет строк на русском вне `t()`; нет ни одного `TODO`.
- [ ] В консоли браузера на всех экранах 01 нет ошибок; запросов к внешним доменам (fonts.googleapis.com и др.) нет.
- [ ] Установка на `php -S` завершается за ≤ 3 с; страница-заглушка отвечает ≤ 200 мс (после первого запроса) при уже применённых миграциях.
- [ ] `package.json` содержит `"@playwright/test": "1.63.0"` без диапазона; `composer.json` не содержит `require` кроме `php`.
- [ ] `docs/DEPLOY.md` описывает варианты А и Б и чек-лист проверки `.htaccess`.
- [ ] Строки I-17…I-21 добавлены в `docs/INTEGRATION.md`, каждая покрыта тестом (I-18 и I-19 — в части фичи 01).

## План тестов
- PHPUnit `tests/Unit`:
  - `HelpersTest` — `e()` (null, число, кавычки, невалидный UTF-8), `t()` (ключ, подстановка, нет ключа), `url()`/`asset()`/`admin_url()` с `base_path` `''` и `/sadik`, `csrf_token()` стабилен в сессии и 64 hex, `csrf_verify` (верный/неверный/null).
  - `RouterTest` — статичный путь, `{name}`, `{id:\d+}`, декодирование `%D0%B0`, 404, 405 + Allow, HEAD, слеш в конце (301 для GET, совпадение для POST), дубль маршрута → `LogicException`, query-string игнорируется.
  - `ConfigTest` — точечная нотация, значение по умолчанию, неизвестный драйвер → исключение.
  - `ViewTest` — рендер в layout, переменные, нет шаблона → исключение, `..` → исключение.
  - `RequirementsTest` — 11 ключей; подмена через `fake-requirements.json` только при `APP_ENV=testing`; папка без прав на запись → `fail`.
  - `LoggerTest` — файл `app-YYYY-MM-DD.log`, формат строки, недоступная папка не бросает исключение.
- PHPUnit `tests/Feature`:
  - `DatabaseTest` — `foreign_keys=1`, `journal_mode=wal`, `busy_timeout=5000`, `transaction` откатывает при исключении, `insert` возвращает id.
  - `MigratorTest` — пустая БД → `001` применена, 4 таблицы есть; повтор — 0 применённых; `002` тестовая из временной папки применяется 1 раз (**I-17**); битая миграция откатывается, следующие не применяются; файл `readme.sql`/`1_x.sql` игнорируется; дубль номера → исключение; FK `audit_log.user_id` → `SET NULL` при удалении пользователя; `CHECK role` отклоняет `admin`.
  - `InstallerTest` — успешная установка (все проверки из критериев); `password_verify` и поиск по логину `ADMIN`/`admin` без учёта регистра (**I-18**, часть 01); `settings.site_name` читается обратно (**I-19**); каждая ошибка валидации; повторная установка → `AlreadyInstalledException` (**I-21**); владелец уже есть в БД → ошибка; сбой миграции → БД и конфиг удалены; `config.php` валиден (`require` возвращает массив, `debug=false`).
  - `ErrorHandlerTest` — `debug=false`/`true` разметка 500, лог содержит сообщение и не содержит значения поля `password` из `$_POST` (**I-20**).
  - `HtaccessTest` — в корневом `.htaccess` есть запрет `app|storage|config|migrations|admin-views|bin` и переписывание в `public/`; в каждой закрытой папке есть `.htaccess` с `Require all denied`; в `public/uploads/.htaccess` запрет `php` и `php_flag` только внутри `IfModule`.
- E2E `tests/e2e/01-karkas.spec.js`: по одному тесту на каждую строку «Элементы интерфейса» (имя теста начинается с testid; `install-req-{key}` — один тест, проверяющий все 11 строк и `data-status`), включая пограничные случаи из столбца «Ошибки» (двойной клик, CSRF, `fake-requirements`, XSS в названии, 405, 301, HEAD, 404 для `/uploads/x.php`). `afterEach` — `expectNoPageErrors()`.
- E2E `tests/e2e/integration/`:
  - `I-17.spec.js` — после `resetApp({installed:true})` и первого запроса `dbQuery('SELECT version FROM schema_migrations')` содержит ровно одну строку `001`. Применение новой миграции `002` проверяется в PHPUnit `MigratorTest` (класть файлы в общую папку `migrations/` из E2E нельзя).
  - `I-19.spec.js` — название, введённое в мастере, видно на `/` и `/admin` (`stub-site-name`).
  - `I-20.spec.js` — `/__test/error` → 500 без деталей + запись в логе; `/net` → 404; `POST /` → 405.
  - `I-21.spec.js` — до установки `/admin` → `/install.php`; после установки `/install.php` → 403, повторная отправка формы не меняет пароль владельца (проверка хэша через `dbQuery`).
  - I-18 в E2E — в фиче 02 (вход появится там).

## Вопросы
1. **Где лежит `install.php`.** ARCHITECTURE §4 показывает его в корне проекта, но при корне сайта = `public/` он недоступен. По умолчанию: `public/install.php`, в варианте Б корневой `.htaccess` переписывает на него. ARCHITECTURE §4 стоит поправить одной строкой.
2. **Автоприменение миграций.** На FTP-хостинге без SSH нельзя запустить CLI. По умолчанию: `public/index.php` применяет новые миграции при первом запросе после выкладки (+ `bin/migrate.php` для SSH).
3. **Строгость требований.** ARCHITECTURE §11 требует `zip` и GD с WebP. По умолчанию: обработка фото (GD+WebP или Imagick) — обязательна, `zip` и лимиты загрузки — предупреждение (нужны только фичам 07 и 12).
4. **Шрифт Inter.** Дизайн грузит его с Google Fonts — это ошибки в консоли без интернета и передача IP посетителей. По умолчанию: системный стек со `'Inter'` первым, без внешней загрузки; файл `Inter.woff2` (лицензия OFL) положить в `public/assets/admin/fonts/` в фиче 02, если будет доступен.
5. **Текст заглушки** «…после фичи 02» виден и на `/` любому посетителю, если выложить сайт до фичи 11. По умолчанию: оставить как просили; `deploy.yml` в фиче 01 не трогаем (на хостинге остаётся лендинг), переключить выкладку на приложение — после фичи 02.
6. **Права на файлы.** По умолчанию: `config.php`, `database.sqlite`, `install.lock` — 0640; если хостинг запускает PHP от другого пользователя и 0640 ломает чтение — откат на 0644 с предупреждением в лог.
