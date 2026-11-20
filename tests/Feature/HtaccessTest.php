<?php
declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class HtaccessTest extends TestCase
{
    public function testRootHtaccessBlocksServiceFoldersAndRewritesToPublic(): void
    {
        $c = (string)file_get_contents(ROOT . '/.htaccess');
        $this->assertMatchesRegularExpression('/app\|storage\|config\|migrations\|admin-views\|bin/', $c);
        $this->assertStringContainsString('public/$1', $c);
        $this->assertStringContainsString('Options -Indexes', $c);
    }

    /** @dataProvider closedDirs */
    public function testClosedFolderDenies(string $dir): void
    {
        $f = ROOT . '/' . $dir . '/.htaccess';
        $this->assertFileExists($f);
        $this->assertStringContainsString('Require all denied', (string)file_get_contents($f));
    }

    public static function closedDirs(): array
    {
        return array_map(fn($d) => [$d], ['app', 'storage', 'config', 'migrations', 'admin-views', 'bin']);
    }

    public function testUploadsHtaccess(): void
    {
        $c = (string)file_get_contents(ROOT . '/public/uploads/.htaccess');
        $this->assertStringContainsString('php', $c);
        $this->assertStringContainsString('Require all denied', $c);
        $this->assertMatchesRegularExpression('~<IfModule mod_php\.c>\s*php_flag engine off\s*</IfModule>~', $c);
        $stripped = preg_replace('~<IfModule mod_php\.c>.*?</IfModule>~s', '', $c);
        $this->assertStringNotContainsString('php_flag', (string)$stripped);
    }

    public function testPublicHtaccess(): void
    {
        $c = (string)file_get_contents(ROOT . '/public/.htaccess');
        $this->assertStringContainsString('index.php', $c);
        $this->assertStringContainsString('Options -Indexes', $c);
    }
}
