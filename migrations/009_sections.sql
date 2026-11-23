CREATE TABLE pages (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  slug         TEXT    NOT NULL UNIQUE,
  title        TEXT    NOT NULL,
  title_kk     TEXT    NOT NULL DEFAULT '',
  body         TEXT    NOT NULL DEFAULT '',
  body_kk      TEXT    NOT NULL DEFAULT '',
  is_published INTEGER NOT NULL DEFAULT 1,
  sort         INTEGER NOT NULL DEFAULT 0,
  updated_at   TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE TABLE schedule_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  time_range TEXT    NOT NULL,
  text       TEXT    NOT NULL,
  text_kk    TEXT    NOT NULL DEFAULT '',
  sort       INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE staff (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name        TEXT    NOT NULL,
  full_name_kk     TEXT    NOT NULL DEFAULT '',
  position         TEXT    NOT NULL,
  position_kk      TEXT    NOT NULL DEFAULT '',
  category         TEXT    NOT NULL DEFAULT '',
  education        TEXT    NOT NULL DEFAULT '',
  education_kk     TEXT    NOT NULL DEFAULT '',
  experience_years INTEGER NULL,
  photo_media_id   INTEGER NULL REFERENCES media(id) ON DELETE RESTRICT,
  is_published     INTEGER NOT NULL DEFAULT 1,
  sort             INTEGER NOT NULL DEFAULT 0,
  created_at       TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_staff_photo ON staff (photo_media_id);

CREATE TABLE groups (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  name           TEXT    NOT NULL,
  name_kk        TEXT    NOT NULL DEFAULT '',
  age_range      TEXT    NOT NULL DEFAULT '',
  age_range_kk   TEXT    NOT NULL DEFAULT '',
  children_count INTEGER NULL,
  groups_count   INTEGER NULL,
  is_published   INTEGER NOT NULL DEFAULT 1,
  sort           INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE group_teachers (
  group_id INTEGER NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
  staff_id INTEGER NOT NULL REFERENCES staff(id) ON DELETE CASCADE,
  PRIMARY KEY (group_id, staff_id)
);

CREATE TABLE achievements (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  year           INTEGER NOT NULL,
  title          TEXT    NOT NULL,
  title_kk       TEXT    NOT NULL DEFAULT '',
  description    TEXT    NOT NULL DEFAULT '',
  description_kk TEXT    NOT NULL DEFAULT '',
  media_id       INTEGER NULL REFERENCES media(id) ON DELETE RESTRICT,
  is_published   INTEGER NOT NULL DEFAULT 1,
  sort           INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_achievements_media ON achievements (media_id);

CREATE TABLE reviews (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  author       TEXT    NOT NULL,
  author_kk    TEXT    NOT NULL DEFAULT '',
  text         TEXT    NOT NULL,
  text_kk      TEXT    NOT NULL DEFAULT '',
  is_published INTEGER NOT NULL DEFAULT 1,
  sort         INTEGER NOT NULL DEFAULT 0
);

INSERT INTO pages (slug, title, title_kk, body, sort) VALUES
('o-nas', 'О нас', 'Біз туралы',
 '<p>Детский сад «Солнышко» работает с 2001 года. Деятельность учреждения осуществляется в соответствии с Государственным общеобязательным стандартом дошкольного воспитания и обучения и Типовой учебной программой.</p><p><strong>Миссия:</strong> обеспечить каждому ребёнку качественное дошкольное образование, охрану и укрепление здоровья, всестороннее развитие и готовность к школе.</p><p><strong>Цель:</strong> создание благоприятных условий для полноценного проживания ребёнком дошкольного детства и формирования основ базовой культуры личности.</p>', 1),
('programma', 'Образовательная программа', 'Білім беру бағдарламасы',
 '<p>Организованная деятельность строится по пяти образовательным областям с учётом возрастных и индивидуальных особенностей детей.</p><ul><li><strong>Здоровье.</strong> Физическая культура, основы безопасного поведения, закаливание и формирование здорового образа жизни.</li><li><strong>Коммуникация.</strong> Развитие речи, художественная литература, основы грамоты, казахский язык.</li><li><strong>Познание.</strong> Основы математики, сенсорика, конструирование, естествознание и исследовательская деятельность.</li><li><strong>Творчество.</strong> Рисование, лепка, аппликация, музыка и театрализованная деятельность.</li><li><strong>Социум.</strong> Ознакомление с окружающим миром, нравственно-патриотическое воспитание, программа «Рухани жаңғыру».</li></ul><p>Дополнительно: кружки по робототехнике, английскому языку, хореографии, шахматам, логопедические занятия.</p>', 2),
('rezhim', 'Режим дня', 'Күн тәртібі',
 '<p>Распорядок дня в детском саду построен с учётом возраста детей и санитарных норм.</p>', 3),
('pitanie', 'Питание', 'Тамақтану',
 '<p>В детском саду организовано сбалансированное 4-разовое питание по утверждённому меню. Медицинский работник ежедневно контролирует качество блюд и санитарное состояние пищеблока.</p>', 4),
('usloviya', 'Условия', 'Жағдайлар',
 '<ul><li><strong>Групповые комнаты.</strong> Отдельные спальни, игровые и развивающие центры в каждой группе.</li><li><strong>Спортивный зал.</strong> Спортзал, бассейн и оборудованная спортивная площадка.</li><li><strong>Медицинский блок.</strong> Медицинский кабинет, изолятор, постоянный медицинский контроль.</li></ul>', 5);

INSERT INTO schedule_items (time_range, text, sort) VALUES
('07:30–08:20', 'Приём детей, утренняя гимнастика', 1),
('08:20–08:50', 'Завтрак', 2),
('09:00–10:30', 'Организованная учебная деятельность', 3),
('10:30–12:00', 'Прогулка', 4),
('12:15–12:50', 'Обед', 5),
('13:00–15:00', 'Дневной сон', 6),
('15:00–15:30', 'Полдник, оздоровительные процедуры', 7),
('15:30–16:30', 'Кружки, игры, самостоятельная деятельность', 8),
('16:30–17:00', 'Ужин', 9),
('17:00–18:30', 'Прогулка, уход детей домой', 10);
