-- Как PDF открывается на сайте по умолчанию: 'reader' (читалка) | 'book' (книга).
ALTER TABLE documents ADD COLUMN view_mode TEXT NOT NULL DEFAULT 'reader';
