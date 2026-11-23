CREATE TABLE document_categories (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  title    TEXT NOT NULL,
  title_kk TEXT NOT NULL DEFAULT '',
  sort     INTEGER NOT NULL DEFAULT 0
);
INSERT INTO document_categories (id, title, title_kk, sort) VALUES
  (1, 'Аттестация', 'Аттестация', 1),
  (2, 'Нормативные документы', 'Нормативтік құжаттар', 2),
  (3, 'Отчёты', 'Есептер', 3),
  (4, 'Для родителей', 'Ата-аналарға', 4);

CREATE TABLE attestation_checklist (
  key         TEXT PRIMARY KEY,
  title       TEXT NOT NULL,
  title_kk    TEXT NOT NULL DEFAULT '',
  is_required INTEGER NOT NULL DEFAULT 1,
  sort        INTEGER NOT NULL DEFAULT 0
);
INSERT INTO attestation_checklist (key, title, title_kk, is_required, sort) VALUES
  ('charter', 'Устав организации', 'Ұйым жарғысы', 1, 1),
  ('license', 'Лицензия на образовательную деятельность', 'Білім беру қызметіне лицензия', 1, 2),
  ('self_assessment', 'Отчёт по самооценке', 'Өзін-өзі бағалау есебі', 1, 3),
  ('program', 'Образовательная программа', 'Білім беру бағдарламасы', 1, 4),
  ('annual_plan', 'Годовой план работы', 'Жылдық жұмыс жоспары', 1, 5),
  ('teachers_info', 'Сведения о педагогах', 'Педагогтер туралы мәліметтер', 1, 6),
  ('child_monitoring', 'Мониторинг развития детей', 'Балалардың дамуын мониторингтеу', 1, 7),
  ('material_base', 'Материально-техническая база', 'Материалдық-техникалық база', 1, 8),
  ('sanitary', 'Санитарно-эпидемиологическое заключение', 'Санитарлық-эпидемиологиялық қорытынды', 1, 9),
  ('rules', 'Правила внутреннего распорядка', 'Ішкі тәртіп ережелері', 1, 10);

CREATE TABLE documents (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  category_id   INTEGER NOT NULL REFERENCES document_categories(id) ON DELETE RESTRICT,
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
  updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_documents_cat_sort ON documents (category_id, sort);
CREATE INDEX idx_documents_media ON documents (media_id);
CREATE INDEX idx_documents_checklist ON documents (checklist_key);
