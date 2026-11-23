<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use Tests\TestCase;

final class MediaHelpersTest extends TestCase
{
    private function media(string $alt = 'Дети'): array
    {
        $v = [];
        foreach (['thumb' => [400, 300], 'medium' => [1200, 900], 'large' => [1920, 1440]] as $s => [$w, $h]) {
            $v[$s] = ['w' => $w, 'h' => $h, 'webp' => "images/2026/11/abc-$s.webp", 'jpg' => "images/2026/11/abc-$s.jpg"];
        }
        return ['variants' => json_encode($v), 'alt' => $alt];
    }

    public function testFormatBytes(): void
    {
        $this->assertSame('0 Б', format_bytes(0));
        $this->assertSame('512 Б', format_bytes(512));
        $this->assertSame('312 КБ', format_bytes(312 * 1024));
        $this->assertSame('9,8 МБ', format_bytes((int)(9.8 * 1048576)));
        $this->assertSame('1,2 ГБ', format_bytes((int)(1.2 * 1073741824)));
        $this->assertSame('1,0 МБ', format_bytes(1048575));
    }

    public function testMediaUrl(): void
    {
        Config::set(['base_path' => '/sadik']);
        $m = $this->media();
        $this->assertSame('/sadik/uploads/images/2026/11/abc-medium.webp', media_url($m));
        $this->assertSame('/sadik/uploads/images/2026/11/abc-thumb.jpg', media_url($m, 'thumb', 'jpg'));
        $this->assertSame('', media_url($m, 'huge'));
    }

    public function testMediaPicture(): void
    {
        Config::set(['base_path' => '']);
        $html = media_picture($this->media('Дети "в" <b>парке</b>'), 'medium', ['class' => 'cover']);
        $this->assertStringContainsString('<source type="image/webp" srcset="/uploads/images/2026/11/abc-thumb.webp 400w, /uploads/images/2026/11/abc-medium.webp 1200w, /uploads/images/2026/11/abc-large.webp 1920w">', $html);
        $this->assertStringContainsString('src="/uploads/images/2026/11/abc-medium.jpg"', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('width="1200" height="900"', $html);
        $this->assertStringContainsString('class="cover"', $html);
        $this->assertStringContainsString('alt="Дети &quot;в&quot; &lt;b&gt;парке&lt;/b&gt;"', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testMediaPictureNullAndBadAttrs(): void
    {
        $this->assertSame('', media_picture(null, 'medium'));
        $html = media_picture($this->media(), 'thumb', ['onerror' => 'x()', 'src' => 'evil', 'a b' => 'c', 'alt' => 'Свой']);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('evil', $html);
        $this->assertStringContainsString('alt="Свой"', $html);
    }

    public function testSmallPhotoDedupesSrcset(): void
    {
        $v = [];
        foreach (['thumb', 'medium', 'large'] as $s) {
            $v[$s] = ['w' => 300, 'h' => 200, 'webp' => "images/x-$s.webp", 'jpg' => "images/x-$s.jpg"];
        }
        $html = media_picture(['variants' => $v, 'alt' => ''], 'large');
        $this->assertSame(1, substr_count($html, '300w'));
    }
}
