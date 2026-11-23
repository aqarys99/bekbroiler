-- Долг 02: попытки ввода пароля в профиле раньше хранились как ip = 'profile:<id>'.
-- Теперь тип попытки — в отдельной колонке scope ('login' | 'profile'), в ip — только адрес (или id пользователя для profile).
ALTER TABLE login_attempts ADD COLUMN scope TEXT NOT NULL DEFAULT 'login';
UPDATE login_attempts SET scope = 'profile', ip = substr(ip, 9) WHERE ip LIKE 'profile:%';
CREATE INDEX idx_login_attempts_scope ON login_attempts (scope, ip, login, created_at);
