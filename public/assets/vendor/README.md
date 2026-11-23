# Сторонние библиотеки (хранятся у нас, без CDN)

| Библиотека | Версия | Источник | Лицензия | Куда |
|---|---|---|---|---|
| Mozilla PDF.js (`pdfjs-dist`, legacy-сборка) | 6.5.438 (2026-11-01) | npm `pdfjs-dist@6.5.438`, папка `legacy/build` | Apache-2.0 (`pdfjs/6.5.438/LICENSE`) | `pdfjs/6.5.438/` |
| StPageFlip (`page-flip`) | 2.0.7 | npm `page-flip@2.0.7`, `dist/js/page-flip.browser.js` | MIT (`page-flip/2.0.7/LICENSE`) | `page-flip/2.0.7/` |

PDF.js: `pdf.min.mjs`, `pdf.worker.min.mjs`, `cmaps/` и `standard_fonts/` (кириллица, казахские буквы, CJK без встроенных шрифтов),
`iccs/` и `wasm/` (декодеры JBIG2/JPEG2000 и цветовые профили; лицензии рядом). Модуль скриптинга PDF (quickjs) не включён.
Обновление: `npm pack pdfjs-dist@<версия>`, заменить папку, поправить константы `PDFJS_VERSION`/`PAGEFLIP_VERSION` в `app/Controllers/Site/SiteController.php` и в этой таблице.
