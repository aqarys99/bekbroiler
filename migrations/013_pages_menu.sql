-- Страницы и меню «как в WordPress»: документы принадлежат страницам, меню сайта хранится в menu_items.

ALTER TABLE pages ADD COLUMN has_schedule INTEGER NOT NULL DEFAULT 0;
UPDATE pages SET has_schedule = 1 WHERE slug = 'rezhim';

-- Каждая категория документов -> страница с тем же названием
INSERT OR IGNORE INTO pages (slug, title, title_kk, body, body_kk, is_published, sort)
SELECT CASE id WHEN 1 THEN 'attestatsiya' WHEN 2 THEN 'normativnye-dokumenty' WHEN 3 THEN 'otchety'
               WHEN 4 THEN 'dlya-roditeley' ELSE 'kategoriya-' || id END,
       title, title_kk, '', '', 1, 10 + sort
FROM document_categories;

-- Пустые страницы раздела «Аттестация»
INSERT OR IGNORE INTO pages (slug, title, title_kk, body, body_kk, is_published, sort) VALUES
  ('monitoring', 'Мониторинг', 'Мониторинг', '', '', 1, 21),
  ('perspektivnyy-plan', 'Перспективный план', 'Перспективалық жоспар', '', '', 1, 22),
  ('godovoy-plan', 'Годовой план', 'Жылдық жоспар', '', '', 1, 23);

-- documents: page_id (CASCADE), category_id остаётся для совместимости, но необязателен
CREATE TABLE documents_new (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id   INTEGER NULL REFERENCES document_categories(id) ON DELETE SET NULL,
  page_id       INTEGER NULL REFERENCES pages(id) ON DELETE CASCADE,
  title         TEXT NOT NULL,
  title_kk      TEXT NOT NULL DEFAULT '',
  media_id      INTEGER NOT NULL REFERENCES media(id) ON DELETE RESTRICT,
  doc_date      TEXT NULL,
  valid_until   TEXT NULL,
  checklist_key TEXT NULL REFERENCES attestation_checklist(key) ON DELETE SET NULL,
  sort          INTEGER NOT NULL DEFAULT 0,
  is_published  INTEGER NOT NULL DEFAULT 1,
  created_by    INTEGER NULL REFERENCES users(id) ON DELETE SET NULL,
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  view_mode     TEXT NOT NULL DEFAULT 'reader'
);
INSERT INTO documents_new (id, category_id, page_id, title, title_kk, media_id, doc_date, valid_until, checklist_key, sort, is_published, created_by, created_at, updated_at, view_mode)
SELECT d.id, d.category_id,
       (SELECT p.id FROM pages p WHERE p.slug = CASE d.category_id WHEN 1 THEN 'attestatsiya' WHEN 2 THEN 'normativnye-dokumenty'
            WHEN 3 THEN 'otchety' WHEN 4 THEN 'dlya-roditeley' ELSE 'kategoriya-' || d.category_id END),
       d.title, d.title_kk, d.media_id, d.doc_date, d.valid_until, d.checklist_key, d.sort, d.is_published, d.created_by, d.created_at, d.updated_at, d.view_mode
FROM documents d;
DROP TABLE documents;
ALTER TABLE documents_new RENAME TO documents;
CREATE INDEX idx_documents_page_sort ON documents (page_id, sort);
CREATE INDEX idx_documents_media ON documents (media_id);
CREATE INDEX idx_documents_checklist ON documents (checklist_key);

-- Меню сайта
CREATE TABLE menu_items (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  menu         TEXT NOT NULL CHECK (menu IN ('main','footer')),
  parent_id    INTEGER NULL REFERENCES menu_items(id) ON DELETE CASCADE,
  sort         INTEGER NOT NULL DEFAULT 0,
  type         TEXT NOT NULL CHECK (type IN ('page','section','link','group')),
  page_id      INTEGER NULL REFERENCES pages(id) ON DELETE CASCADE,
  section_key  TEXT NULL,
  url          TEXT NULL,
  title_ru     TEXT NOT NULL DEFAULT '',
  title_kk     TEXT NOT NULL DEFAULT '',
  target_blank INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_menu_items_menu ON menu_items (menu, parent_id, sort);
CREATE INDEX idx_menu_items_page ON menu_items (page_id);

INSERT INTO menu_items (menu, sort, type, section_key) VALUES ('main', 1, 'section', 'home');
INSERT INTO menu_items (menu, sort, type, page_id) SELECT 'main', 2, 'page', id FROM pages WHERE slug = 'o-nas';
INSERT INTO menu_items (menu, sort, type, title_ru, title_kk) VALUES ('main', 3, 'group', 'Аттестация', 'Аттестация');
INSERT INTO menu_items (menu, parent_id, sort, type, page_id)
SELECT 'main', (SELECT id FROM menu_items WHERE menu = 'main' AND type = 'group' AND title_ru = 'Аттестация'), p.sort, 'page', p.id
FROM pages p
WHERE p.slug IN ('attestatsiya', 'normativnye-dokumenty', 'otchety', 'dlya-roditeley', 'monitoring', 'perspektivnyy-plan', 'godovoy-plan')
   OR p.slug LIKE 'kategoriya-%';
INSERT INTO menu_items (menu, sort, type, section_key) VALUES
  ('main', 4, 'section', 'news'), ('main', 5, 'section', 'gallery'), ('main', 6, 'section', 'staff'), ('main', 7, 'section', 'contacts');

INSERT INTO menu_items (menu, sort, type, page_id)
SELECT 'footer', p.sort, 'page', p.id FROM pages p WHERE p.slug IN ('programma', 'rezhim', 'pitanie', 'usloviya');
INSERT INTO menu_items (menu, sort, type, section_key) VALUES ('footer', 10, 'section', 'documents'), ('footer', 11, 'section', 'contacts');
