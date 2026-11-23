CREATE TABLE albums (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  slug           TEXT    NOT NULL UNIQUE,
  title          TEXT    NOT NULL,
  title_kk       TEXT    NOT NULL DEFAULT '',
  description    TEXT    NOT NULL DEFAULT '',
  description_kk TEXT    NOT NULL DEFAULT '',
  cover_media_id INTEGER NULL REFERENCES media(id) ON DELETE RESTRICT,
  event_date     TEXT    NULL,
  is_published   INTEGER NOT NULL DEFAULT 1,
  sort           INTEGER NOT NULL DEFAULT 0,
  created_by     INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at     TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_albums_cover ON albums (cover_media_id);
CREATE INDEX idx_albums_pub ON albums (is_published, event_date);

CREATE TABLE album_media (
  album_id INTEGER NOT NULL REFERENCES albums(id) ON DELETE CASCADE,
  media_id INTEGER NOT NULL REFERENCES media(id) ON DELETE RESTRICT,
  sort     INTEGER NOT NULL DEFAULT 0,
  caption  TEXT    NOT NULL DEFAULT '',
  PRIMARY KEY (album_id, media_id)
);
CREATE INDEX idx_album_media_media ON album_media (media_id);
