<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Menu;
use Tests\TestCase;

final class MediaMenuTest extends TestCase
{
    public function testMediaItemForBothRolesAndActiveOnCard(): void
    {
        Config::set(['base_path' => '']);
        foreach (['owner', 'editor'] as $role) {
            foreach (['/admin/media', '/admin/media/7'] as $path) {
                $items = array_merge(...array_column(Menu::build(['role' => $role], $path), 'items'));
                $media = array_values(array_filter($items, static fn($i) => $i['key'] === 'media'));
                $this->assertCount(1, $media, $role);
                $this->assertSame('nav-media', $media[0]['testid']);
                $this->assertSame('Медиатека', $media[0]['label']);
                $this->assertTrue($media[0]['active']);
            }
        }
    }
}
