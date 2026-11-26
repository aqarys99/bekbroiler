-- Раздел «Аттестация»: страницы бывают обычными и страницами аттестации (только файлы, без текста).
ALTER TABLE pages ADD COLUMN kind TEXT NOT NULL DEFAULT 'regular' CHECK (kind IN ('regular','attestation'));

-- Страницы из категорий документов, три пустые страницы и всё, что лежит в группе меню «Аттестация»
UPDATE pages SET kind = 'attestation'
WHERE slug IN ('attestatsiya', 'normativnye-dokumenty', 'otchety', 'dlya-roditeley', 'monitoring', 'perspektivnyy-plan', 'godovoy-plan')
   OR slug LIKE 'kategoriya-%'
   OR id IN (SELECT m.page_id FROM menu_items m
             WHERE m.menu = 'main' AND m.page_id IS NOT NULL
               AND m.parent_id IN (SELECT g.id FROM menu_items g WHERE g.menu = 'main' AND g.type = 'group' AND g.title_ru = 'Аттестация'));
CREATE INDEX idx_pages_kind ON pages (kind, sort);
