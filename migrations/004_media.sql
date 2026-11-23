CREATE TABLE media (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  type          TEXT    NOT NULL CHECK (type IN ('image','video','file')),
  original_name TEXT    NOT NULL,
  path          TEXT    NOT NULL UNIQUE,
  mime          TEXT    NOT NULL,
  size          INTEGER NOT NULL DEFAULT 0,
  source_size   INTEGER NOT NULL DEFAULT 0,
  width         INTEGER NULL,
  height        INTEGER NULL,
  variants      TEXT    NOT NULL DEFAULT '{}',
  alt           TEXT    NOT NULL DEFAULT '',
  alt_kk        TEXT    NOT NULL DEFAULT '',
  status        TEXT    NOT NULL DEFAULT 'ready' CHECK (status IN ('ready','processing','failed')),
  uploaded_by   INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_media_type_created ON media (type, created_at);
CREATE INDEX idx_media_name ON media (original_name COLLATE NOCASE);
