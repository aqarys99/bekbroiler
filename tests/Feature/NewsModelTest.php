<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\ValidationException;
use App\Media\MediaInUseException;
use App\Models\Media;
use App\Models\News;
use App\Models\NewsConflictException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthTestCase;
use Tests\Support\NewsHelpers;

/** Модель News: состояние и часовой пояс (I-29), перевод (I-10), выборки (I-03, I-04), версии, form_key, медиа (I-28). */
final class NewsModelTest extends AuthTestCase
{
    use NewsHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('editor');
    }

    protected function tearDown(): void
    {
        @unlink(Clock::testFile());
        parent::tearDown();
    }

    private function id(array $over = []): int
    {
        return $this->create($over)['id'];
    }

    // ---------------------------------------------------------------- Clock

    public function testClockFromLocalAlmatyToUtc(): void
    {
        $this->assertSame('2026-11-25 04:00:00', Clock::fromLocal('2026-11-25', '09:00')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-24 19:00:00', Clock::fromLocal('2026-11-25', '00:00')->format('Y-m-d H:i:s'), 'полночь по Алматы — вчера в UTC');
        $this->assertSame('2026-11-25 18:59:00', Clock::fromLocal('2026-11-25', '23:59')->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', Clock::fromLocal('2026-11-25', '09:00')->getTimezone()->getName());
    }

    #[DataProvider('badLocal')]
    public function testClockFromLocalRejectsGarbage(string $date, string $time): void
    {
        $this->assertNull(Clock::fromLocal($date, $time));
    }

    public static function badLocal(): array
    {
        return [['2026-02-30', '09:00'], ['2026-13-01', '09:00'], ['2026-11-25', '24:00'], ['2026-11-25', '09:60'], ['2026-11-25', '9:00'],
            ['25.11.2026', '09:00'], ['', ''], ['2026-11-25', ''], ['', '09:00'], ['2026-11-25', '09:00:00'], ['2026-11-25x', '09:00'], ['2026-1-5', '09:00']];
    }

    public function testClockFromLocalTrimsInput(): void
    {
        $this->assertNotNull(Clock::fromLocal(' 2026-11-25 ', ' 09:00 '));
    }

    public function testClockToLocalAndDayRollover(): void
    {
        $this->assertSame('24.11.2026 23:59', Clock::toLocal('2026-11-24 18:59:59')->format('d.m.Y H:i'));
        $this->assertSame('25.11.2026 00:00', Clock::toLocal('2026-11-24 19:00:00')->format('d.m.Y H:i'));
        $this->assertSame('+05:00', Clock::offsetLabel(Clock::toLocal('2026-11-24 19:00:00')));
    }

    public function testClockTestFileOverridesAndFallsBack(): void
    {
        $this->clock('2026-11-25 04:00:00');
        $this->assertSame('2026-11-25 04:00:00', Clock::nowUtc()->format('Y-m-d H:i:s'));
        file_put_contents(Clock::testFile(), 'not json');
        $this->assertEqualsWithDelta(time(), Clock::nowUtc()->getTimestamp(), 5);
        file_put_contents(Clock::testFile(), json_encode(['now' => 'вчера']));
        $this->assertEqualsWithDelta(time(), Clock::nowUtc()->getTimestamp(), 5);
        $this->clock(null);
        $this->assertEqualsWithDelta(time(), Clock::nowUtc()->getTimestamp(), 5);
    }

    public function testTimezoneChangeShiftsInputNotStoredUtc(): void
    {
        $this->clock('2026-11-20 00:00:00');
        $id = $this->id(['publish_mode' => 'later', 'publish_date' => '2026-11-25', 'publish_time' => '09:00']);
        $this->assertSame('2026-11-25 04:00:00', News::find($id)['published_at']);
        Config::set(['timezone' => 'Europe/Moscow']);
        $this->assertSame('2026-11-25 06:00:00', Clock::fromLocal('2026-11-25', '09:00')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-25 04:00:00', News::find($id)['published_at'], 'UTC в БД не меняется');
        $this->assertSame('25.11.2026 07:00', Clock::toLocal('2026-11-25 04:00:00')->format('d.m.Y H:i'));
    }

    public function testInvalidTimezoneFallsBackToAlmaty(): void
    {
        Config::set(['timezone' => 'Mars/Olympus']);
        $this->assertSame('Asia/Almaty', Clock::timezone()->getName());
    }

    // ---------------------------------------------------------------- state()

    public function testStateBoundaries(): void
    {
        $now = new \DateTimeImmutable('2026-11-25 04:00:00', new \DateTimeZone('UTC'));
        $row = static fn(string $at) => ['status' => 'published', 'published_at' => $at];
        $this->assertSame('published', News::state($row('2026-11-25 04:00:00'), $now), 'published_at == now');
        $this->assertSame('published', News::state($row('2026-11-25 03:59:59'), $now));
        $this->assertSame('scheduled', News::state($row('2026-11-25 04:00:01'), $now));
        $this->assertSame('draft', News::state(['status' => 'draft', 'published_at' => null], $now));
        $this->assertSame('draft', News::state(['status' => 'draft', 'published_at' => '2020-01-01 00:00:00'], $now));
        $this->assertSame('draft', News::state(['status' => 'published', 'published_at' => null], $now));
        $this->assertSame('draft', News::state([], $now));
    }

    public function testStateUsesClockWhenNowOmitted(): void
    {
        $row = ['status' => 'published', 'published_at' => '2026-11-25 04:00:00'];
        $this->clock('2026-11-25 03:59:59');
        $this->assertSame('scheduled', News::state($row));
        $this->clock('2026-11-25 04:00:00');
        $this->assertSame('published', News::state($row));
    }

    public function testMidnightAlmatyScheduleSwitchesAtUtc1900(): void
    {
        $this->clock('2026-11-24 12:00:00');
        $id = $this->id(['publish_mode' => 'later', 'publish_date' => '2026-11-25', 'publish_time' => '00:00']);
        $this->assertSame('2026-11-24 19:00:00', News::find($id)['published_at']);
        $this->clock('2026-11-24 18:59:59');
        $this->assertSame('scheduled', News::find($id)['state']);
        $this->assertSame(0, News::published()['total']);
        $this->clock('2026-11-24 19:00:00');
        $this->assertSame('published', News::find($id)['state']);
        $this->assertSame(1, News::published()['total']);
        $this->assertSame('25.11.2026', News::latest(1)[0]['date'], 'в Алматы уже 25-е');
    }

    public function testSameStateEverywhereAtTheMomentOfSwitch(): void
    {
        $this->clock('2026-11-20 00:00:00');
        $id = $this->id(['publish_mode' => 'later', 'publish_date' => '2026-11-25', 'publish_time' => '09:00']);
        foreach ([['2026-11-25 03:59:59', 'scheduled', 0], ['2026-11-25 04:00:00', 'published', 1], ['2026-11-26 00:00:00', 'published', 1]] as [$at, $state, $pub]) {
            $this->clock($at);
            $this->assertSame($state, News::find($id)['state'], $at);
            $this->assertSame($state, News::list()['items'][0]['state'], $at);
            $this->assertSame($state, News::latest(3)[0]['state'], $at);
            $this->assertSame($pub, News::published()['total'], $at);
            $this->assertSame($pub, News::counts('')['published'], $at);
            $this->assertSame($pub === 1, News::findPublishedBySlug($this->slugOf($id)) !== null, $at);
        }
    }

    private function slugOf(int $id): string
    {
        return (string)Database::fetchValue('SELECT slug FROM news WHERE id = ?', [$id]);
    }

    // ---------------------------------------------------------------- isTranslated()

    public static function translationCases(): array
    {
        return [
            'нет KK' => ['T', '<p>b</p>', '', '', false],
            'только заголовок KK, текст есть у RU' => ['T', '<p>b</p>', 'Т', '', false],
            'заголовок и текст KK' => ['T', '<p>b</p>', 'Т', '<p>б</p>', true],
            'RU без текста, KK только заголовок' => ['T', '', 'Т', '', true],
            'копия целиком' => ['T', '<p>b</p>', 'T', '<p>b</p>', false],
            'копия с пробелами по краям' => ['T', '<p>b</p>', "  T \n", " <p>b</p>\n", false],
            'тот же заголовок, другой текст' => ['T', '<p>b</p>', 'T', '<p>другой</p>', true],
            'другой заголовок, тот же текст' => ['T', '<p>b</p>', 'Тақырып', '<p>b</p>', true],
            'RU без текста, KK копия заголовка' => ['T', '', 'T', '', false],
            'пустой заголовок, есть текст' => ['T', '<p>b</p>', '', '<p>б</p>', false],
            'заголовок из пробелов' => ['T', '<p>b</p>', '   ', '<p>б</p>', false],
            'отсутствуют ключи' => ['T', '', '', '', false],
        ];
    }

    #[DataProvider('translationCases')]
    public function testIsTranslated(string $t, string $b, string $kt, string $kb, bool $expected): void
    {
        $this->assertSame($expected, News::isTranslated($this->row($t, $b, $kt, $kb)));
    }

    public function testIsTranslatedMissingKeys(): void
    {
        $this->assertFalse(News::isTranslated([]));
        $this->assertFalse(News::isTranslated(['title' => 'T']));
    }

    public function testSqlAndPhpRuleAgree(): void
    {
        $ids = [];
        foreach (self::translationCases() as $name => [$t, $b, $kt, $kb, $expected]) {
            if ($t === '' || mb_strlen(trim($kt)) > 120) {
                continue;
            }
            $id = $this->id(['title_ru' => $t, 'body_ru' => $b, 'title_kk' => $kt, 'body_kk' => $kb]);
            $ids[$id] = $name;
        }
        $zeroTranslated = 0;
        foreach (News::list('', 'all', 1, 100)['items'] as $item) {
            $full = News::find($item['id']);
            $this->assertSame(News::isTranslated($full), $item['kk_ready'], $ids[$item['id']] ?? '');
            $zeroTranslated += $item['kk_ready'] ? 1 : 0;
        }
        $stats = News::translationStats();
        $this->assertSame(count($ids), $stats['total']);
        $this->assertSame($zeroTranslated, $stats['translated']);
    }

    public function testTranslationFlipsAfterFillClearAndCopy(): void
    {
        $id = $this->id(['title_ru' => 'Тест', 'body_ru' => '<p>текст</p>']);
        $this->assertSame(['total' => 1, 'translated' => 0], News::translationStats());
        $this->assertFalse(News::list()['items'][0]['kk_ready']);

        $this->save($id, ['title_ru' => 'Тест', 'body_ru' => '<p>текст</p>', 'title_kk' => 'Сынақ', 'body_kk' => '<p>мәтін</p>']);
        $this->assertSame(['total' => 1, 'translated' => 1], News::translationStats());
        $this->assertTrue(News::list()['items'][0]['kk_ready']);

        $this->save($id, ['title_ru' => 'Тест', 'body_ru' => '<p>текст</p>', 'title_kk' => 'Тест', 'body_kk' => '<p>текст</p>']);
        $this->assertSame(0, News::translationStats()['translated'], 'копия русского');

        $this->save($id, ['title_ru' => 'Тест', 'body_ru' => '<p>текст</p>', 'title_kk' => 'Тест', 'body_kk' => '<p>текст!</p>']);
        $this->assertSame(1, News::translationStats()['translated'], 'отличается текстом');

        $this->save($id, ['title_ru' => 'Тест', 'body_ru' => '<p>текст</p>']);
        $this->assertSame(0, News::translationStats()['translated'], 'очищено');
    }

    private function save(int $id, array $over): array
    {
        $cur = News::find($id);
        $prep = News::prepare($this->form($over), $cur);
        $this->assertSame([], $prep['errors']);
        return News::save($id, $prep['data'], $this->editorId, (int)$cur['version']);
    }

    // ---------------------------------------------------------------- выборки

    public function testPublishedAndFindBySlugOnlyVisible(): void
    {
        $this->clock('2026-11-24 00:00:00');
        $pub = $this->create(['title_ru' => 'Опубликована']);
        $draft = $this->create(['title_ru' => 'Черновик', 'publish_mode' => 'draft']);
        $future = $this->create(['title_ru' => 'Будущая', 'publish_mode' => 'later', 'publish_date' => '2026-11-25', 'publish_time' => '09:00']);
        $this->assertSame(['opublikovana'], array_column(News::published()['items'], 'slug'));
        $this->assertNotNull(News::findPublishedBySlug($pub['slug']));
        $this->assertNull(News::findPublishedBySlug($draft['slug']));
        $this->assertNull(News::findPublishedBySlug($future['slug']));
        $this->assertNull(News::findPublishedBySlug('net-takoy'));
        $this->clock('2026-11-25 04:00:00');
        $this->assertSame(['budushchaya', 'opublikovana'], array_column(News::published()['items'], 'slug'));
        $this->assertNotNull(News::findPublishedBySlug($future['slug']));
    }

    public function testPublishedPaginationAndOrder(): void
    {
        $this->clock('2026-11-24 10:00:00');
        foreach (range(1, 5) as $i) {
            $this->clock('2026-11-24 10:00:0' . $i);
            $this->create(['title_ru' => "Н$i"]);
        }
        $p1 = News::published(1, 2);
        $this->assertSame(5, $p1['total']);
        $this->assertSame(3, $p1['pages']);
        $this->assertSame(['Н5', 'Н4'], array_column($p1['items'], 'title'));
        $this->assertSame(['Н1'], array_column(News::published(99, 2)['items'], 'title'));
        $this->assertSame(['Н5', 'Н4'], array_column(News::published(-3, 2)['items'], 'title'));
    }

    public function testLatestMixedStatesAndFields(): void
    {
        $this->clock('2026-11-20 10:00:00');
        $this->create(['title_ru' => 'Старая']);
        $this->clock('2026-11-21 10:00:00');
        $this->create(['title_ru' => 'Черновик', 'publish_mode' => 'draft']);
        $c = $this->create(['title_ru' => 'Запланированная', 'publish_mode' => 'later', 'publish_date' => '2026-11-30', 'publish_time' => '09:00']);
        $this->clock('2026-11-22 10:00:00');
        $this->create(['title_ru' => 'Свежая']);
        $this->create(['title_ru' => 'Тоже свежая']); // тот же момент, больший id — выше
        Database::query("UPDATE news SET updated_at = '2026-11-21 10:00:00' WHERE status = 'draft'");
        $latest = News::latest(3);
        $this->assertCount(3, $latest);
        $this->assertSame(['Запланированная', 'Тоже свежая', 'Свежая'], array_column($latest, 'title'));
        $this->assertSame(['scheduled', 'published', 'published'], array_column($latest, 'state'));
        $this->assertSame('/admin/news/' . $c['id'] . '/edit', $latest[0]['edit_url']);
        $this->assertSame('30.11.2026', $latest[0]['date']);
        $this->assertEqualsCanonicalizing(['id', 'title', 'state', 'edit_url', 'date', 'published_at'], array_keys($latest[0]));
        $this->assertCount(5, News::latest(10));
        $this->assertSame('draft', array_column(News::latest(5), 'state', 'title')['Черновик']);
    }

    public function testLatestEmpty(): void
    {
        $this->assertSame([], News::latest(3));
        $this->assertSame(['total' => 0, 'translated' => 0], News::translationStats());
        $this->assertSame(['all' => 0, 'published' => 0, 'draft' => 0, 'scheduled' => 0], News::counts(''));
        $l = News::list();
        $this->assertSame([0, 1, 1], [$l['total'], $l['page'], $l['pages']]);
    }

    // ---------------------------------------------------------------- list / counts

    public function testSearchCaseInsensitiveCyrillicAndKazakh(): void
    {
        $this->create(['title_ru' => 'Осенний УТРЕННИК', 'title_kk' => 'Күзгі ҚОНАҚ', 'body_kk' => '<p>х</p>']);
        $this->create(['title_ru' => 'Прогулка', 'title_kk' => 'Ән-ҮЙ']);
        $this->assertSame(1, News::list('утренник')['total']);
        $this->assertSame(1, News::list('УТРЕН')['total']);
        $this->assertSame(1, News::list('қонақ')['total'], 'по казахскому заголовку, нижний регистр');
        $this->assertSame(1, News::list('ҚОНАҚ')['total']);
        $this->assertSame(1, News::list('әН-үй')['total']);
        $this->assertSame(0, News::list('осен', 'draft')['total']);
        $this->assertSame(2, News::list('')['total']);
        $this->assertSame(1, News::counts('қонақ')['all']);
    }

    public function testSearchWildcardsAreLiteralAndLongQueryTrimmed(): void
    {
        $this->create(['title_ru' => 'Скидка 50% на всё']);
        $this->create(['title_ru' => 'Скидка 50 на всё']);
        $this->create(['title_ru' => 'a_b']);
        $this->create(['title_ru' => 'axb']);
        $this->assertSame(1, News::list('50%')['total']);
        $this->assertSame(1, News::list('a_b')['total']);
        $this->assertSame(0, News::list('%%%')['total']);
        $this->assertSame(0, News::list('\\')['total']);
        $this->assertSame(0, News::list("' OR 1=1 --")['total']);
        $this->assertSame(4, News::list('  ')['total'], 'пробелы по краям обрезаны -> пустой запрос');
        $this->assertSame(0, News::list(str_repeat('ж', 500))['total']);
        $this->assertSame(100, mb_strlen(News::cleanQuery(str_repeat('ж', 500))));
        $this->assertSame('x', News::cleanQuery('  x  '));
    }

    public function testCountsEqualRealRowsInEachFilterWithQuery(): void
    {
        $this->clock('2026-11-24 00:00:00');
        $this->create(['title_ru' => 'Праздник А']);
        $this->create(['title_ru' => 'Праздник Б', 'publish_mode' => 'draft']);
        $this->create(['title_ru' => 'Праздник В', 'publish_mode' => 'later', 'publish_date' => '2026-11-26', 'publish_time' => '09:00']);
        $this->create(['title_ru' => 'Другое', 'publish_mode' => 'draft']);
        foreach (['', 'праздник', 'другое', 'нет'] as $q) {
            $counts = News::counts($q);
            foreach (['all', 'published', 'draft', 'scheduled'] as $f) {
                $this->assertSame($counts[$f], News::list($q, $f)['total'], "q=$q f=$f");
            }
        }
        $this->assertSame(['all' => 3, 'published' => 1, 'draft' => 1, 'scheduled' => 1], News::counts('праздник'));
    }

    public function testUnknownStatusIsAll(): void
    {
        $this->assertSame('all', News::normalizeState('xxx'));
        $this->assertSame('all', News::normalizeState(['published']));
        $this->assertSame('all', News::normalizeState(null));
        $this->assertSame('draft', News::normalizeState('draft'));
    }

    public function testListPaginationClampsAndOrder(): void
    {
        $this->clock('2026-11-24 10:00:00');
        foreach (range(1, 25) as $i) {
            $this->clock(sprintf('2026-11-24 10:%02d:00', $i));
            $this->create(['title_ru' => "Н$i"]);
        }
        $p1 = News::list('', 'all', 1);
        $this->assertSame([25, 2, 20], [$p1['total'], $p1['pages'], $p1['per_page']]);
        $this->assertCount(20, $p1['items']);
        $this->assertSame('Н25', $p1['items'][0]['title']);
        $this->assertCount(5, News::list('', 'all', 2)['items']);
        $this->assertSame(2, News::list('', 'all', 999)['page']);
        $this->assertSame(1, News::list('', 'all', -5)['page']);
        $this->assertSame(1, News::list('', 'all', 0)['page']);
    }

    public function testListScheduledOnTopThenByDate(): void
    {
        $this->clock('2026-11-24 10:00:00');
        $this->create(['title_ru' => 'Опубликована']);
        $this->create(['title_ru' => 'Будущая', 'publish_mode' => 'later', 'publish_date' => '2026-12-01', 'publish_time' => '09:00']);
        $this->create(['title_ru' => 'Черновик', 'publish_mode' => 'draft']);
        Database::query("UPDATE news SET updated_at = '2026-11-24 11:00:00' WHERE status = 'draft'");
        $this->assertSame(['Будущая', 'Черновик', 'Опубликована'], array_column(News::list()['items'], 'title'));
    }

    public function testListRowHasAuthorCoverAndKkReady(): void
    {
        $p = $this->photo('image', 'Альт');
        $this->create(['cover_media_id' => (string)$p]);
        $row = News::list()['items'][0];
        $this->assertSame('Петрова Анна', $row['author_name']);
        $this->assertSame($p, (int)$row['cover']['id']);
        $this->assertFalse($row['kk_ready']);
        Database::query('UPDATE news SET author_id = NULL');
        $this->assertNull(News::list()['items'][0]['author_name']);
    }

    // ---------------------------------------------------------------- slug

    public function testUniqueSlugSuffixesAndEmpty(): void
    {
        $a = $this->create(['title_ru' => 'Праздник']);
        $b = $this->create(['title_ru' => 'Праздник']);
        $c = $this->create(['title_ru' => 'Праздник']);
        $this->assertSame(['prazdnik', 'prazdnik-2', 'prazdnik-3'], [$a['slug'], $b['slug'], $c['slug']]);
        $this->assertSame('prazdnik', News::uniqueSlug('prazdnik', $a['id']), 'своя же запись не конфликтует');
        $this->assertSame('prazdnik-4', News::uniqueSlug('prazdnik', null));
        $this->assertSame('novost', News::uniqueSlug('', null));
        $d = $this->create(['title_ru' => '!!!']);
        $this->assertSame('novost', $d['slug']);
        $this->assertSame('novost-2', $this->create(['title_ru' => '???'])['slug']);
    }

    public function testUniqueSlugKeepsMaxLength(): void
    {
        $long = str_repeat('a', 80);
        $this->create(['title_ru' => $long]);
        $slug = News::uniqueSlug($long, null);
        $this->assertLessThanOrEqual(80, strlen($slug));
        $this->assertStringEndsWith('-2', $slug);
    }

    public function testManualSlugTakenIsValidationError(): void
    {
        $a = $this->create(['title_ru' => 'Первая']);
        $prep = News::prepare($this->form(['title_ru' => 'Вторая', 'slug' => 'Pervaya', 'slug_auto' => '0']), null);
        $this->assertSame('Этот адрес уже занят новостью «Первая»', $prep['errors']['slug']);
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM news'));
        // своя же новость — не конфликт
        $prep = News::prepare($this->form(['title_ru' => 'Первая', 'slug' => $a['slug'], 'slug_auto' => '0']), News::find($a['id']));
        $this->assertArrayNotHasKey('slug', $prep['errors']);
        // гонка: адрес занят между prepare и save
        $prep = News::prepare($this->form(['title_ru' => 'Вторая', 'slug' => 'moy-adres', 'slug_auto' => '0']), null);
        $this->create(['title_ru' => 'Перехватчик', 'slug' => 'moy-adres', 'slug_auto' => '0']);
        $this->expectException(ValidationException::class);
        News::save(null, $prep['data'], $this->editorId, null);
    }

    public function testManualSlugNormalised(): void
    {
        $r = $this->create(['slug' => 'Мой Адрес!', 'slug_auto' => '0']);
        $this->assertSame('moy-adres', $r['slug']);
        $r2 = $this->create(['title_ru' => 'Из заголовка', 'slug' => '', 'slug_auto' => '0']);
        $this->assertSame('iz-zagolovka', $r2['slug'], 'пустое поле -> из заголовка');
    }

    // ---------------------------------------------------------------- валидация prepare()

    public function testValidationLimits(): void
    {
        $e = News::prepare($this->form(['title_ru' => str_repeat('я', 121), 'title_kk' => str_repeat('ә', 121),
            'excerpt_ru' => str_repeat('я', 301), 'excerpt_kk' => str_repeat('ә', 301)]), null)['errors'];
        $this->assertSame(['title_ru', 'title_kk', 'excerpt_ru', 'excerpt_kk'], array_keys($e));
        $ok = News::prepare($this->form(['title_ru' => str_repeat('я', 120), 'excerpt_ru' => str_repeat('я', 300)]), null)['errors'];
        $this->assertSame([], $ok);
    }

    public function testTitleRequiredTrimmed(): void
    {
        foreach (['', '   ', "\t\n", "\x01"] as $t) {
            $e = News::prepare($this->form(['title_ru' => $t]), null)['errors'];
            $this->assertSame('Введите заголовок', $e['title_ru'] ?? null, var_export($t, true));
        }
    }

    public function testNonStringFieldsDoNotCrash(): void
    {
        $e = News::prepare(['title_ru' => ['x'], 'body_ru' => ['<p>x</p>'], 'publish_mode' => ['now'], 'photos' => 'abc', 'cover_media_id' => ['1']], null)['errors'];
        $this->assertArrayHasKey('title_ru', $e);
    }

    public function testBodyTooLarge(): void
    {
        $big = '<p>' . str_repeat('ж', 100001) . '</p>'; // > 200 000 байт в UTF-8
        $e = News::prepare($this->form(['body_ru' => $big]), null)['errors'];
        $this->assertSame('Текст слишком большой', $e['body_ru'] ?? null);
        $e = News::prepare($this->form(['body_kk' => $big]), null)['errors'];
        $this->assertArrayHasKey('body_kk', $e);
        $this->assertSame([], News::prepare($this->form(['body_ru' => '<p>' . str_repeat('a', 199000) . '</p>']), null)['errors']);
        $this->assertSame([], News::prepare($this->form(['body_ru' => $big]), null, false)['errors'], 'предпросмотр не падает');
    }

    public function testPhotoValidation(): void
    {
        $img = $this->photo();
        $file = $this->photo('file');
        $e = News::prepare($this->form(['cover_media_id' => '99999']), null)['errors'];
        $this->assertSame('Фото удалено из медиатеки — выберите другое', $e['cover_media_id']);
        $this->assertArrayHasKey('cover_media_id', News::prepare($this->form(['cover_media_id' => (string)$file]), null)['errors']);
        $this->assertArrayHasKey('photos', News::prepare($this->form(['photos' => [(string)$img, '99999']]), null)['errors']);
        $this->assertArrayHasKey('photos', News::prepare($this->form(['photos' => [(string)$file]]), null)['errors']);
        $dup = News::prepare($this->form(['photos' => [(string)$img, (string)$img]]), null);
        $this->assertSame([], $dup['errors']);
        $this->assertSame([$img], $dup['data']['photos'], 'дубли убираются');
        $this->assertSame([], News::prepare($this->form(['cover_media_id' => '']), null)['errors']);
    }

    public function testTooManyPhotos(): void
    {
        $ids = [];
        for ($i = 0; $i < 51; $i++) {
            $ids[] = (string)$this->photo();
        }
        $e = News::prepare($this->form(['photos' => $ids]), null)['errors'];
        $this->assertSame('Не больше 50 фото', $e['photos'] ?? null);
        $this->assertSame([], News::prepare($this->form(['photos' => array_slice($ids, 0, 50)]), null)['errors']);
        Config::set(['news' => ['max_photos' => 2, 'per_page' => 20]]);
        $this->assertSame('Не больше 2 фото', News::prepare($this->form(['photos' => array_slice($ids, 0, 3)]), null)['errors']['photos']);
    }

    public function testScheduleValidation(): void
    {
        $this->clock('2026-11-24 00:00:00'); // 05:00 по Алматы
        $bad = [['2026-11-24', '04:59'], ['2026-11-24', '05:00'], ['2026-11-23', '12:00'], ['', ''], ['2026-02-30', '09:00'], ['мусор', 'мусор']];
        foreach ($bad as [$d, $t]) {
            $e = News::prepare($this->form(['publish_mode' => 'later', 'publish_date' => $d, 'publish_time' => $t]), null)['errors'];
            $this->assertSame('Укажите будущие дату и время', $e['publish_date'] ?? null, "$d $t");
        }
        $ok = News::prepare($this->form(['publish_mode' => 'later', 'publish_date' => '2026-11-24', 'publish_time' => '05:01']), null);
        $this->assertSame([], $ok['errors']);
        $this->assertSame('2026-11-24 00:01:00', $ok['data']['published_at']);
        $this->assertSame('published', $ok['data']['status']);
    }

    public function testScheduleIgnoredForDraftAndNow(): void
    {
        $this->clock('2026-11-24 00:00:00');
        foreach (['draft', 'now'] as $m) {
            $e = News::prepare($this->form(['publish_mode' => $m, 'publish_date' => '1999-01-01']), null)['errors'];
            $this->assertSame([], $e, $m);
        }
    }

    // ---------------------------------------------------------------- статус по intent/mode

    public function testStatusRules(): void
    {
        $this->clock('2026-11-24 00:00:00');
        $d = News::prepare($this->form(['intent' => 'draft', 'publish_mode' => 'now']), null)['data'];
        $this->assertSame(['draft', null], [$d['status'], $d['published_at']]);
        $d = News::prepare($this->form(['intent' => 'submit', 'publish_mode' => 'draft']), null)['data'];
        $this->assertSame(['draft', null], [$d['status'], $d['published_at']]);
        $d = News::prepare($this->form(['intent' => 'submit', 'publish_mode' => 'now']), null)['data'];
        $this->assertSame(['published', '2026-11-24 00:00:00'], [$d['status'], $d['published_at']]);
        $d = News::prepare($this->form(['intent' => 'draft', 'publish_mode' => 'later', 'publish_date' => '2026-12-01', 'publish_time' => '09:00']), null)['data'];
        $this->assertSame(['draft', null], [$d['status'], $d['published_at']], 'черновик не планируется');
        $d = News::prepare($this->form(['publish_mode' => 'weird']), null)['data'];
        $this->assertSame('draft', $d['status'], 'неизвестный режим -> черновик');
    }

    public function testPublishedAtKeptForAlreadyPublished(): void
    {
        $this->clock('2026-11-20 10:00:00');
        $id = $this->id();
        $this->clock('2026-11-24 10:00:00');
        $this->save($id, ['title_ru' => 'Новое название']);
        $this->assertSame('2026-11-20 10:00:00', News::find($id)['published_at']);
    }

    public function testNowOnScheduledPublishesImmediately(): void
    {
        $this->clock('2026-11-20 10:00:00');
        $id = $this->id(['publish_mode' => 'later', 'publish_date' => '2026-11-25', 'publish_time' => '09:00']);
        $this->assertSame('scheduled', News::find($id)['state']);
        $this->clock('2026-11-21 10:00:00');
        $r = $this->save($id, ['publish_mode' => 'now']);
        $this->assertSame(['scheduled', 'published'], [$r['before'], $r['after']]);
        $this->assertSame('2026-11-21 10:00:00', News::find($id)['published_at']);
    }

    public function testUnpublishAndRepublishChangesDate(): void
    {
        $this->clock('2026-11-20 10:00:00');
        $id = $this->id();
        $this->save($id, ['publish_mode' => 'draft']);
        $n = News::find($id);
        $this->assertSame(['draft', 'draft', null], [$n['state'], $n['status'], $n['published_at']]);
        $this->clock('2026-11-22 10:00:00');
        $this->save($id, ['publish_mode' => 'now']);
        $this->assertSame('2026-11-22 10:00:00', News::find($id)['published_at']);
    }

    public function testAuditOnStateChanges(): void
    {
        $this->clock('2026-11-20 10:00:00');
        $id = $this->id(['publish_mode' => 'draft']);
        $this->save($id, ['publish_mode' => 'later', 'publish_date' => '2026-11-25', 'publish_time' => '09:00']);
        $this->save($id, ['publish_mode' => 'now']);
        $this->save($id, ['publish_mode' => 'now', 'title_ru' => 'Ещё']);
        $this->save($id, ['publish_mode' => 'draft']);
        $actions = array_map(static fn($a) => explode(':', $a)[0], array_values(array_filter($this->audit(), static fn($a) => str_starts_with($a, 'news.'))));
        $this->assertSame(['news.create', 'news.update', 'news.schedule', 'news.update', 'news.publish', 'news.update', 'news.update', 'news.unpublish'], $actions);
    }

    // ---------------------------------------------------------------- сохранение: версии, form_key

    public function testVersionIncrementsAndStaleSaveConflicts(): void
    {
        $id = $this->id(['title_ru' => 'Исходный']);
        $this->assertSame(1, (int)News::find($id)['version']);
        $this->save($id, ['title_ru' => 'Первая вкладка']);
        $this->assertSame(2, (int)News::find($id)['version']);
        $prep = News::prepare($this->form(['title_ru' => 'Вторая вкладка (устарела)']), News::find($id));
        try {
            News::save($id, $prep['data'], $this->editorId, 1);
            $this->fail('ожидался конфликт');
        } catch (NewsConflictException) {
        }
        $this->assertSame('Первая вкладка', News::find($id)['title'], 'данные первой вкладки не перезаписаны');
        $this->assertSame(2, (int)News::find($id)['version']);
        News::save($id, $prep['data'], $this->editorId, 2);
        $this->assertSame('Вторая вкладка (устарела)', News::find($id)['title']);
    }

    public function testSaveUnknownIdThrows(): void
    {
        $prep = News::prepare($this->form(), null);
        $this->expectException(\OutOfBoundsException::class);
        News::save(9999, $prep['data'], $this->editorId, 1);
    }

    public function testFormKeyDuplicateUpdatesSameRow(): void
    {
        $key = bin2hex(random_bytes(16));
        $a = $this->create(['form_key' => $key, 'title_ru' => 'Один', 'intent' => 'draft']);
        $b = $this->create(['form_key' => $key, 'title_ru' => 'Два', 'intent' => 'draft']);
        $this->assertSame($a['id'], $b['id']);
        $this->assertFalse($b['created']);
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM news'));
        $this->assertSame('Два', News::find($a['id'])['title']);
        $this->assertSame(1, (int)Database::fetchValue("SELECT COUNT(*) FROM news_translations"));
        $this->assertSame($a['id'], News::findByFormKey($key)['id']);
        $this->assertNull(News::findByFormKey(str_repeat('0', 32)));
    }

    public function testBadFormKeyIgnored(): void
    {
        foreach (['', 'short', str_repeat('Z', 32), str_repeat('a', 33)] as $k) {
            $prep = News::prepare($this->form(['form_key' => $k]), null);
            $this->assertNull($prep['data']['form_key'], $k);
        }
        $a = $this->id(['form_key' => 'bad']);
        $b = $this->id(['form_key' => 'bad']);
        $this->assertNotSame($a, $b, 'без валидного form_key дубль не склеивается');
    }

    public function testKkRowAlwaysCreated(): void
    {
        $id = $this->id();
        $this->assertSame(1, (int)Database::fetchValue("SELECT COUNT(*) FROM news_translations WHERE news_id = ? AND lang = 'kk'", [$id]));
    }

    // ---------------------------------------------------------------- хранится только санитизированное

    public function testBodiesStoredSanitizedInBothLanguages(): void
    {
        $id = $this->id([
            'body_ru' => '<p onclick="1">a<script>1</script></p><iframe src="//e"></iframe><a href="javascript:1">j</a>',
            'body_kk' => '<p style="x">ә<svg onload=1></svg></p><img src=x onerror=1>',
            'title_kk' => 'Т',
        ]);
        $n = News::find($id);
        $this->assertSame('<p>a</p>j', $n['body']);
        $this->assertSame('<p>ә</p>', $n['kk_body']);
        foreach ([$n['body'], $n['kk_body']] as $b) {
            $this->assertDoesNotMatchRegularExpression('/<(script|iframe|svg|img)|onclick|onerror|javascript:/i', $b);
        }
    }

    public function testExcerptStoredAsPlainText(): void
    {
        $id = $this->id(['excerpt_ru' => '<script>alert(1)</script> & "q"']);
        $this->assertSame('<script>alert(1)</script> & "q"', News::find($id)['excerpt']);
    }

    public function testTitleControlCharsStripped(): void
    {
        $id = $this->id(['title_ru' => "A\x00B\x07C\nD"]);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08]/', News::find($id)['title']);
        $this->assertStringStartsWith('A B C', News::find($id)['title']);
    }

    // ---------------------------------------------------------------- медиа (I-01, I-02, I-28)

    public function testGalleryOrderAndBodyPlaces(): void
    {
        [$a, $b, $c, $x, $y] = [$this->photo(), $this->photo(), $this->photo(), $this->photo(), $this->photo()];
        $id = $this->id([
            'photos' => [(string)$c, (string)$a, (string)$b],
            'body_ru' => '<p><img data-media-id="' . $x . '"></p>', 'body_kk' => '<p><img data-media-id="' . $y . '"><img data-media-id="' . $x . '"></p>',
        ]);
        $this->assertSame([$c, $a, $b], array_column(News::find($id)['photos'], 'id'));
        $body = Database::fetchAll("SELECT media_id FROM news_media WHERE news_id = ? AND place = 'body' ORDER BY sort", [$id]);
        $this->assertSame([$x, $y], array_map('intval', array_column($body, 'media_id')), 'из обоих языков, без дублей, по порядку появления');
        $this->assertSame([$c, $a, $b], array_map('intval', array_column(
            Database::fetchAll("SELECT media_id FROM news_media WHERE news_id = ? AND place = 'gallery' ORDER BY sort", [$id]),
            'media_id'
        )));
        $this->save($id, ['photos' => [(string)$b, (string)$c]]);
        $this->assertSame([$b, $c], array_column(News::find($id)['photos'], 'id'));
        $this->assertSame([], Media::usages($a));
        $this->assertSame([], Media::usages($x), 'удалили img из текста -> использование снято');
    }

    public function testBodyImageWithMissingIdIsStrippedBeforeLinking(): void
    {
        $id = $this->id(['body_ru' => '<p>t</p><img data-media-id="424242">']);
        $this->assertSame('<p>t</p>', News::find($id)['body']);
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM news_media'));
    }

    public function testUsageLabelRolesAndTwoNewsForOnePhoto(): void
    {
        $p = $this->photo();
        $this->create(['title_ru' => 'Первая', 'cover_media_id' => (string)$p, 'photos' => [(string)$p], 'body_ru' => '<p><img data-media-id="' . $p . '"></p>']);
        $this->create(['title_ru' => 'Вторая', 'photos' => [(string)$p]]);
        $labels = array_column(Media::usages($p), 'label');
        $this->assertEqualsCanonicalizing(['Новость «Первая» (обложка, фото, в тексте)', 'Новость «Вторая» (фото)'], $labels);
        $urls = array_column(Media::usages($p), 'url');
        $this->assertContains('/admin/news/1/edit', $urls);
    }

    public function testUsedPhotoCannotBeDeletedUntilReleased(): void
    {
        $p = $this->photo();
        $id = $this->id(['cover_media_id' => (string)$p]);
        try {
            Media::delete($p);
            $this->fail('MediaInUseException');
        } catch (MediaInUseException) {
        }
        $this->assertNotNull(Media::find($p));
        $this->save($id, ['cover_media_id' => '']);
        $this->assertNull(News::find($id)['cover_media_id']);
        $this->assertSame([], Media::usages($p));
        $this->assertNotNull(Media::delete($p));
    }

    public function testDirectDeleteBlockedByForeignKeys(): void
    {
        $cover = $this->photo();
        $gal = $this->photo();
        $this->id(['cover_media_id' => (string)$cover, 'photos' => [(string)$gal]]);
        foreach ([$cover, $gal] as $mid) {
            try {
                Database::query('DELETE FROM media WHERE id = ?', [$mid]);
                $this->fail("FK не сработал для $mid");
            } catch (\PDOException $e) {
                $this->assertStringContainsString('FOREIGN KEY', $e->getMessage());
            }
        }
    }

    public function testReplacingCoverFreesOldPhoto(): void
    {
        [$old, $new] = [$this->photo(), $this->photo()];
        $id = $this->id(['cover_media_id' => (string)$old]);
        $this->save($id, ['cover_media_id' => (string)$new]);
        $this->assertSame([], Media::usages($old));
        $this->assertCount(1, Media::usages($new));
    }

    public function testCoverReplacedButStillInGalleryKeepsUsage(): void
    {
        [$old, $new] = [$this->photo(), $this->photo()];
        $id = $this->id(['cover_media_id' => (string)$old, 'photos' => [(string)$old]]);
        $this->save($id, ['cover_media_id' => (string)$new, 'photos' => [(string)$old]]);
        $this->assertSame(['Новость «Осенний утренник» (фото)'], array_column(Media::usages($old), 'label'));
    }

    public function testDeleteFreesAllMediaAndKeepsFiles(): void
    {
        [$cover, $gal, $body] = [$this->photo(), $this->photo(), $this->photo()];
        $id = $this->id(['title_kk' => 'Т', 'cover_media_id' => (string)$cover, 'photos' => [(string)$gal], 'body_ru' => '<p><img data-media-id="' . $body . '"></p>']);
        $this->assertNotNull(News::delete($id));
        $this->assertNull(News::find($id));
        foreach (['news', 'news_translations', 'news_media'] as $t) {
            $this->assertSame(0, (int)Database::fetchValue("SELECT COUNT(*) FROM $t"), $t);
        }
        foreach ([$cover, $gal, $body] as $m) {
            $this->assertSame([], Media::usages($m));
            $this->assertNotNull(Media::find($m), 'фото остаётся в медиатеке');
        }
        $this->assertNull(News::delete($id), 'повторное удаление -> null');
        $this->assertNotNull(Media::delete($cover));
    }

    public function testPhotoDeletedBetweenPrepareAndSave(): void
    {
        $p = $this->photo();
        $prep = News::prepare($this->form(['photos' => [(string)$p]]), null);
        Database::query('DELETE FROM media WHERE id = ?', [$p]);
        try {
            News::save(null, $prep['data'], $this->editorId, null);
            $this->fail('ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('photos', $e->errors());
        }
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM news'), 'транзакция откатилась');
    }

    // ---------------------------------------------------------------- renderBody / bodyForEditor

    public function testRenderBodyReplacesImagesAndHidesMissing(): void
    {
        $p = $this->photo();
        $html = '<p>a</p><img data-media-id="' . $p . '"><img data-media-id="777">';
        $out = News::renderBody($html, 'thumb');
        $this->assertStringContainsString('<picture', $out);
        $this->assertStringNotContainsString('data-media-id', $out);
        $this->assertStringNotContainsString('777', $out);
        $this->assertSame('<p>без картинок</p>', News::renderBody('<p>без картинок</p>'));
    }

    public function testBodyForEditorAddsSrcAndAlt(): void
    {
        $p = $this->photo('image', 'Мой "альт"');
        $out = News::bodyForEditor('<img data-media-id="' . $p . '">');
        $this->assertStringContainsString('src="', $out);
        $this->assertStringContainsString('alt="Мой &quot;альт&quot;"', $out);
        $this->assertSame('', News::bodyForEditor('<img data-media-id="777">'), 'удалённое фото убирается');
    }
}
