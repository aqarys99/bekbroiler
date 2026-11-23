CREATE TABLE videos (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  title           TEXT    NOT NULL,
  title_kk        TEXT    NOT NULL DEFAULT '',
  source          TEXT    NOT NULL CHECK (source IN ('youtube','upload')),
  youtube_id      TEXT    NULL,
  media_id        INTEGER NULL REFERENCES media(id) ON DELETE RESTRICT,
  poster_media_id INTEGER NULL REFERENCES media(id) ON DELETE RESTRICT,
  duration        INTEGER NULL,
  is_published    INTEGER NOT NULL DEFAULT 1,
  sort            INTEGER NOT NULL DEFAULT 0,
  status          TEXT    NOT NULL DEFAULT 'ready' CHECK (status IN ('ready','processing','failed')),
  created_by      INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at      TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_videos_media ON videos (media_id);
CREATE INDEX idx_videos_poster ON videos (poster_media_id);
CREATE INDEX idx_videos_pub ON videos (is_published, sort);
