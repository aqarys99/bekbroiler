<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Core\Clock;
use App\Models\Media;
use App\Models\News;

/** Общие помощники PHPUnit-тестов новостей (использовать в наследниках Tests\AuthTestCase). */
trait NewsHelpers
{
    protected function photo(string $type = 'image', string $alt = ''): int
    {
        $h = bin2hex(random_bytes(8));
        return Media::create(['type' => $type, 'original_name' => $h . '.jpg', 'path' => 'images/' . $h, 'mime' => 'image/jpeg', 'alt' => $alt,
            'variants' => json_encode(['thumb' => ['w' => 1, 'h' => 1, 'jpg' => "images/$h-t.jpg", 'webp' => "images/$h-t.webp"]])]);
    }

    protected function form(array $over = []): array
    {
        return $over + ['title_ru' => 'Осенний утренник', 'publish_mode' => 'now', 'intent' => 'submit', 'slug_auto' => '1', 'form_key' => bin2hex(random_bytes(16))];
    }

    /** Создать новость через prepare()+save(); ошибки валидации валят тест. */
    protected function create(array $over = []): array
    {
        $prep = News::prepare($this->form($over), null);
        $this->assertSame([], $prep['errors']);
        return News::save(null, $prep['data'], $this->editorId, null);
    }

    protected function clock(?string $nowUtc): void
    {
        if ($nowUtc === null) {
            @unlink(Clock::testFile());
            return;
        }
        file_put_contents(Clock::testFile(), json_encode(['now' => $nowUtc]));
    }

    /** Строка в формате News::isTranslated(). */
    protected function row(string $title, string $body, string $kkTitle, string $kkBody): array
    {
        return ['title' => $title, 'body' => $body, 'kk_title' => $kkTitle, 'kk_body' => $kkBody];
    }
}
