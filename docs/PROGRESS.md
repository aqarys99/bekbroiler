# Журнал прогресса

Короткая память проекта. Новая сессия или агент читает **этот файл вместо истории переписки**.
Каждый блок ≤ 15 строк. Подробности — в спеках `docs/specs/` и в коде.

## Текущее состояние
- Ветка разработки: `claude/sharp-galileo-xzk77w`
- Следующая фича: **08** (см. `docs/ROADMAP.md`)
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

## 03. Медиатека и сжатие фото — ✅ 2026-11-23
- Сделано: таблица `media`, загрузка XHR по одному с очередью, сжатие в браузере (canvas, ≤2560, EXIF-ориентация) + сервер (GD: поворот, удаление метаданных перекодированием, 400/1200/1920 WebP+JPEG, лимит пикселей, gif→статичный), экран «Медиатека» (сетка, поиск, фильтр, карточка, alt, «где используется», удаление), пикер «выбрать/загрузить», место на диске, мобильное меню-гамбургер.
- Ключевые файлы: `app/Core/{ImageProcessor,Uploader,DiskUsage,Json}.php`, `app/Models/Media.php`, `app/Media/*`, `app/media_usages.php` (реестр потребителей), `app/Controllers/Admin/MediaController.php`, `admin-views/media/*`, `admin-views/partials/media-field.php`, `public/assets/admin/{uploader,media-picker,boot}.js`, `migrations/004_media.sql`.
- Решения: требуется GD с WebP (иначе 503 `image_unsupported`, установка не проходит), Imagick — опция для HEIC; оригиналы не храним; фильтр показывает только доступные типы; потребитель медиа = провайдер в `app/media_usages.php` + FK `ON DELETE RESTRICT`; фото выводятся только через `media_picture()`; JSON-режим Guard (CSRF в `X-CSRF-Token`); пикер подключается partial'ом `media-field.php`, скрипты один раз через `once()`.
- Тесты: PHPUnit 212 (1 skip root), E2E 213 (вкл. crawl); testid 45/45; связи I-01, I-02, I-07, I-16, I-23–I-27 (через тестовый провайдер `/__test/media-usage`).
- Долги: **в фиче 06** — `uploader.js:123` фильтр реестра выкидывает простаивающий загрузчик страницы (beforeunload не сработает на странице альбома с пикером): удалять только загрузчики вне DOM; HEIC через Imagick не проверен (нет Imagick в среде); webServer Playwright с `-d upload_max_filesize=25M`.

## Режим работы с фичи 04 (решение пользователя 2026-11-23)
- Без reviewer, test-writer и циклов исправлений: одна фича = один агент (сервер + экраны) по ARCHITECTURE, дизайну и шаблону предыдущих фич; после фичи — только `php -l`; общий прогон тестов и проверка безопасности — в конце проекта.

## 04. Новости — ✅ 2026-11-23 (без финального ревью)
- Сделано: список (поиск, фильтр со счётчиками, пагинация, бейдж ҚАЗ), редактор RU/ҚАЗ на своём `editor.js` (contenteditable, Ж/К/H3/список/ссылка/фото из медиатеки, вставка простым текстом), обложка и фото (сортировка) через `media-field.php`, черновик/сейчас/запланировать (Asia/Almaty, без cron), slug с транслитом каз. букв, автосохранение (5 с / ≥15 с), конфликт версий 409, защита от дублей `form_key`, предпросмотр, удаление.
- Ключевые файлы: `migrations/005_news.sql`, `app/Core/{Clock,Slug,HtmlSanitizer}.php`, `app/Models/News.php`, `app/Media/NewsUsageProvider.php`, `app/Controllers/Admin/NewsController.php`, `admin-views/news/*`, `admin-views/partials/editor.php`, `public/assets/admin/{editor,news-form,news-preview}.js`.
- Решения: ҚАЗ-копия русского без изменений = «нет перевода» (`News::isTranslated`, `TRANSLATED_SQL`, `translationStats()` для фичи 08); `News::published()`/`latest(3)` для фич 08/11; требуется расширение `dom`; `media-field.php` перезаписывает `$v,$m,$state` — не использовать эти имена в шаблоне-хозяине.
- Тесты: PHPUnit 480 (1 skip), E2E 04 + crawl зелёные; полный прогон после исправлений шёл, ревью не проводилось.

## 05. Документы и чек-лист — ✅ 2026-11-23 (без ревью, E2E не писались)
- Сделано: экран «Документы» (баннер N из 10, вкладки категорий, бейджи типа, статусы На сайте / Истекает через N дн. / Срок истёк / Нет файла, «Загрузить» у пустых пунктов с предзаполнением формы), форма «Новый документ» (RU/ҚАЗ, категория, пункт чек-листа, даты), замена файла, удаление, порядок вверх/вниз.
- Ключевые файлы: `migrations/006_documents.sql`, `app/Models/{Document,Checklist}.php`, `app/Core/DocumentUploader.php`, `app/Media/DocumentUsageProvider.php`, `app/Controllers/Admin/DocumentsController.php`, `admin-views/documents/index.php`, `public/assets/admin/documents.js`.
- Решения: файлы — `media` type='file', путь `docs/ГГГГ/ММ/<hex>.<ext>` (путь = файл, `Media::filePaths` для file); тип по расширению из белого списка + сигнатура (%PDF, OLE, zip+папка OOXML, finfo для jpg/png); обычная multipart-форма без JS-загрузчика; Content-Disposition attachment для doc/xls/ppt в `public/uploads/.htaccess`; фильтр медиатеки «Документы» показывается всегда; файл удаляется вместе с документом (через `Media::delete`).
- Для фич 08/11: `Checklist::status()` -> items[state ok|warn|missing], done, total, missing; `Document::publishedByCategory()`; истёкший срок считается warn (пункт засчитан), документы с is_published=0 не учитываются (UI публикации нет, по умолчанию 1).
- Проверено: php -l, PHPUnit 480 зелёный (поправлен MediaTest под тип «file»), ручной прогон php -S: загрузка PDF, отказ .php, перемещение, замена, удаление — без 500. Долги: E2E для documents-*; Playwright-проверка JS замены файла; при превышении post_max_size форма даёт 400 (CSRF), а не понятный текст.
- К исправлению на этапе тестирования (05): истёкший документ сейчас `warn` (засчитан) — должен быть `missing`/красный; нет редактирования полей документа (только замена файла/удаление); нет переключателя «показывать на сайте»; при превышении `post_max_size` ответ 400 вместо понятного текста.

## 06. Фотогалерея — ✅ 2026-11-23 (без ревью, E2E не писались)
- Сделано: сетка альбомов (обложка, счётчик, дата, бейдж «Скрыт»), «Новый альбом» (RU/ҚАЗ, описание, дата, показ), страница альбома: загрузка перетаскиванием прямо в альбом (uploader.js, фото сразу добавляется), «Добавить из медиатеки» (пикер, несколько), порядок перетаскиванием и ←/→, «Сделать обложкой» (бейдж), подпись, «Убрать из альбома», «Показывать на сайте», удаление альбома (фото остаются в медиатеке).
- Ключевые файлы: `migrations/007_gallery.sql`, `app/Models/Album.php`, `app/Media/AlbumUsageProvider.php`, `app/Controllers/Admin/GalleryController.php`, `admin-views/gallery/{index,new,show,_fields,_photo}.php`, `public/assets/admin/gallery.js`, стили `.album-*`/`.gphoto*` в admin.css.
- Решения: первое добавленное фото = обложка; убрали обложку — обложкой станет первое оставшееся; без выбранной обложки используется первое фото; FK `ON DELETE RESTRICT` на media (I-02, I-07); фото может быть и в альбоме, и в новости; slug через `Slug` (`album`, `-2` при дубле).
- Для фичи 11: `Album::published()` (cover + photos по порядку, только `is_published=1`, I-08), `Album::publishedBySlug()`.
- Долг из 03 закрыт: `uploader.js` убирает из реестра только загрузчики, чья очередь вне DOM.
- Проверено: php -l, PHPUnit 480 зелёный, ручной прогон Playwright (альбом, 3 фото, обложка, порядок, подпись, пикер, скрытие, удаление) — без 500 и ошибок консоли.
- К исправлению на этапе тестирования (06): нет E2E gallery-*; перетаскивание порядка не проверено в браузере (только кнопки ←/→); нет ручной сортировки самих альбомов (колонка `sort` есть); «сброс кэша сайта» не вызывается — кэша ещё нет (фича 11).

## 07. Видео — ✅ 2026-11-23 (без ревью, E2E не писались)
- Сделано: экран «Видео» (карточки «YouTube — рекомендуем» и «Загрузить файл», список с обложкой/источником/длительностью/статусом, правка названия RU/ҚАЗ, обложка из медиатеки, «На сайте/Скрыто», порядок ↑/↓, удаление); фильтр «Видео» в медиатеке всегда виден; меню «Видео».
- Ключевые файлы: `migrations/008_videos.sql`, `app/Models/Video.php`, `app/Core/{YouTube,VideoUploader,Mp4,Ffmpeg}.php`, `app/Media/VideoUsageProvider.php`, `app/Controllers/Admin/VideosController.php`, `admin-views/videos/index.php`, `public/assets/admin/videos.js`, `bin/process-videos.php`, helper `video_embed()`.
- Решения: YouTube — строгий разбор любых ссылок в `[A-Za-z0-9_-]{11}`, название через oEmbed (5 с), при сбое просим ввести вручную; обложка — ссылка img.youtube.com, не скачивается. MP4 грузится частями (`VideoUploader`: init/status/chunk/finish, размер части = min(5 МБ, лимиты PHP), возобновление через localStorage+status, сборка на сервере, проверка `ftyp`, лимит `video.max_mb`=100, чистка недособранного старше суток), файл — `media` type='video' `videos/ГГГГ/ММ/`; длительность читается из mvhd. ffmpeg (через `which`) опционален: есть → status=processing и `bin/process-videos.php` (cron) делает H.264 720p + кадр-обложку; нет → ready сразу.
- Для фич 08/11: `Video::published()`, `Video::findPublished($id)` (скрытое/удалённое = null → заглушка), `video_embed($video)` (lite-YouTube по клику, youtube-nocookie; для файла `<video preload="none" controls>`). FK `ON DELETE RESTRICT` на media (файл и обложка), I-02.
- Проверено: php -l, PHPUnit 480 зелёный (поправлен MediaTest: «Видео» в фильтре всегда), ручной прогон php -S: ссылка YouTube (сети нет — ручное название), загрузка MP4 тремя частями, отказ не-MP4/слишком большого, правка, скрытие, порядок, ffmpeg-обработка — без 500.
- К исправлению на этапе тестирования (07): **I-09 не сделан** — вставка видео в текст новости (нужна кнопка в `editor.js` и заглушка для удалённого видео) — делать вместе с фичей 11; нет E2E videos-*; JS загрузчика не проверен в браузере (только curl по тем же эндпоинтам); обработку ffmpeg не запускает веб-запрос — нужен cron (`bin/process-videos.php`), добавить в `docs/DEPLOY.md`; обложка YouTube грузится с img.youtube.com (виден IP посетителя — учесть в политике); сброс кэша сайта не вызывается (кэша нет до фичи 11).
