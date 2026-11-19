# Журнал прогресса

Короткая память проекта. Новая сессия или агент читает **этот файл вместо истории переписки**.
Каждый блок ≤ 15 строк. Подробности — в спеках `docs/specs/` и в коде.

## Текущее состояние
- Ветка разработки: `claude/sharp-galileo-xzk77w`
- Следующая фича: **01. Каркас** (см. `docs/ROADMAP.md`)
- Как продолжить: `/build-feature next`

## 00. Подготовка — ✅ 2026-11-19
- Сделано: лендинг `index.html`, архитектура `docs/ARCHITECTURE.md`, дизайн 8 экранов админки `docs/design/`, агенты `.claude/agents/`, цикл `/build-feature`, CI `.github/workflows/ci.yml`.
- Решения: PHP 8.1+ + SQLite, без фреймворков; видео в основном через YouTube; интерфейс сначала RU, структура данных сразу под RU/ҚАЗ; `@playwright/test` 1.63.0.
- Модели агентов: planner и reviewer — Opus; backend/frontend-builder и test-writer — Sonnet; test-runner — Haiku.
