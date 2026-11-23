-- Язык интерфейса админки у пользователя ('ru' | 'kk').
ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'ru';
