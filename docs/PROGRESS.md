# Журнал прогресса

Короткая память проекта. Новая сессия или агент читает **этот файл вместо истории переписки**.
Каждый блок ≤ 15 строк. Подробности — в спеках `docs/specs/` и в коде.

## Текущее состояние
- Ветка разработки: `claude/sharp-galileo-xzk77w`
- Следующая фича: **03. Медиатека и сжатие фото** (см. `docs/ROADMAP.md`)
- Как продолжить: `/build-feature next`

## 00. Подготовка — ✅ 2026-11-19
- Сделано: лендинг `index.html`, архитектура `docs/ARCHITECTURE.md`, дизайн 8 экранов админки `docs/design/`, агенты `.claude/agents/`, цикл `/build-feature`, CI `.github/workflows/ci.yml`.
- Решения: PHP 8.1+ + SQLite, без фреймворков; видео в основном через YouTube; интерфейс сначала RU, структура данных сразу под RU/ҚАЗ; `@playwright/test` 1.63.0.
- Модели агентов: planner и reviewer — Opus; backend/frontend-builder и test-writer — Sonnet; test-runner — Haiku.

## 01. Каркас — ✅ 2026-11-20
- Сделано: фронт-контроллер, роутер, PDO SQLite (WAL, FK), миграции с автоприменением + `bin/migrate.php`, конфиг, helpers `e() t() url() csrf_*`, View, обработка ошибок (dev/prod, лог), мастер установки `public/install.php` (12 требований, создание owner, блокировка), страницы 403/404/405/500, заглушка `/admin`.
- Ключевые файлы: `app/Core/*` (Router, Database, Migrator, Installer, Requirements, Session, ErrorHandler, View), `app/Controllers/InstallController.php`, `migrations/001_core.sql`, `admin-views/{layouts/auth,install/*,errors/*,stub}.php`, `public/assets/admin/admin.css`, `.htaccess` (корень + public + закрытые папки), `docs/DEPLOY.md`.
- Решения: деплой А (корень = `public/`) и Б (корень = репозиторий, rewrite в `public/`, защита вне mod_rewrite только конкретных имён); install в `public/`; `base_path` только из `detectBasePath()`; установка под `flock` + `BEGIN IMMEDIATE`; запасная папка сессий `storage/sessions`; шрифт — системный стек (Inter локально в 02); тестовые точки подмены `Database::setConnection`, `Config::set`, `View::setPath`; env `APP_STORAGE_DIR`, `APP_ENV=testing`.
- Тесты: PHPUnit 91 (1 skip под root), E2E 40 (+1 skip I-18 до фичи 02); testid 26/26, связи I-17, I-19, I-20, I-21 (I-18 частично).
- Долги: в фиче 11 закрыть `themes/` без mod_rewrite (`themes/.htaccess`); E2E для `error-403` — в фиче 02; `.htaccess` не проверен на живом Apache; нет `tests/e2e/crawl.spec.js` (обход всех ссылок) — добавить в 02.

## 02. Вход и пользователи — ✅ 2026-11-21
- Сделано: вход/выход, защита от подбора (5 на пару IP+логин, 20 на IP, атомарный резерв до password_verify), «Запомнить меня» (хэш, ротация, окно prev 10 с, кража → удаление токенов + bumpVersion), таймаут 2 ч, роли owner/editor (403 по URL), «Пользователи» (owner), профиль со сменой пароля (лимит 5), журнал, макет админки (меню, шапка, крошки), шрифт Inter локально.
- Ключевые файлы: `app/Core/{Auth,Guard,LoginThrottle,RememberMe,Flash,Menu,UserValidator}.php`, `app/Controllers/Admin/*`, `app/Models/User.php`, `app/admin_menu.php`, `migrations/002_auth.sql`, `003_remember_rotation.sql`, `admin-views/{layouts/admin,partials/*,auth,dashboard,users,profile}`.
- Решения: пункт меню добавляет каждая фича в `app/admin_menu.php`; переключатель ҚАЗ скрыт до 13; удаления пользователей нет, только блокировка; owner задаёт пароль вручную; `freshOwner()` перечитывает актора в `immediate`; BUSY → 503 `error.busy`; профильные попытки в `login_attempts` с `ip='profile:<id>'`; `redirect()` в CLI бросает `RedirectException`.
- Тесты: PHPUnit 156 (1 skip root), E2E 134 (вкл. crawl owner/editor); testid 53/53; связи I-14, I-15, I-18, I-19, I-22, I-23, I-24.
- Долги: колонка `login_attempts.ip` хранит не только IP — в фиче 12 при просмотре попыток добавить `scope`; в параллельной серии несколько записей `login_locked` (косметика).
