ALTER TABLE users ADD COLUMN auth_version INTEGER NOT NULL DEFAULT 1;

CREATE TABLE remember_tokens (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  selector     TEXT    NOT NULL UNIQUE,
  token_hash   TEXT    NOT NULL,
  expires_at   TEXT    NOT NULL,
  created_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  last_used_at TEXT    NULL
);
CREATE INDEX idx_remember_tokens_user ON remember_tokens (user_id);

CREATE INDEX idx_login_attempts_ip_login ON login_attempts (ip, login, created_at);
