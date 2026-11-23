CREATE TABLE news (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  slug           TEXT    NOT NULL UNIQUE,
  title          TEXT    NOT NULL,
  excerpt        TEXT    NOT NULL DEFAULT '',
  body           TEXT    NOT NULL DEFAULT '',
  cover_media_id INTEGER NULL REFERENCES media(id) ON DELETE RESTRICT,
  status         TEXT    NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published')),
  published_at   TEXT    NULL,
  author_id      INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  updated_by     INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  version        INTEGER NOT NULL DEFAULT 1,
  form_key       TEXT    NULL UNIQUE,
  created_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_news_status_pub ON news (status, published_at);
CREATE INDEX idx_news_cover ON news (cover_media_id);

CREATE TABLE news_translations (
  news_id    INTEGER NOT NULL REFERENCES news(id) ON DELETE CASCADE,
  lang       TEXT    NOT NULL CHECK (lang IN ('kk')),
  title      TEXT    NOT NULL DEFAULT '',
  excerpt    TEXT    NOT NULL DEFAULT '',
  body       TEXT    NOT NULL DEFAULT '',
  updated_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  PRIMARY KEY (news_id, lang)
);

CREATE TABLE news_media (
  news_id  INTEGER NOT NULL REFERENCES news(id) ON DELETE CASCADE,
  media_id INTEGER NOT NULL REFERENCES media(id) ON DELETE RESTRICT,
  place    TEXT    NOT NULL CHECK (place IN ('gallery','body')),
  sort     INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (news_id, place, media_id)
);
CREATE INDEX idx_news_media_media ON news_media (media_id);
