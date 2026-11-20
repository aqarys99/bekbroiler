<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\View;
use Tests\TestCase;

final class ViewTest extends TestCase
{
    protected function tearDown(): void
    {
        View::setPath(null);
        parent::tearDown();
    }

    public function testRendersInLayoutWithVariables(): void
    {
        $dir = $this->tempDir();
        mkdir($dir . '/layouts');
        file_put_contents($dir . '/hello.php', 'Hi <?= e($name) ?>');
        file_put_contents($dir . '/layouts/auth.php', '<t><?= e($title) ?></t><?= $content ?>');
        View::setPath($dir);
        $this->assertSame('<t>T</t>Hi &lt;b&gt;', View::render('hello', ['name' => '<b>', 'title' => 'T']));
        $this->assertSame('Hi x', View::render('hello', ['name' => 'x'], null));
    }

    public function testMissingTemplateThrows(): void
    {
        View::setPath($this->tempDir());
        $this->expectException(\RuntimeException::class);
        View::render('nope', [], null);
    }

    public function testDotDotRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        View::render('../app/helpers', [], null);
    }
}
