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
        return array_map(fn($d) => [$d], ['app', 'storage', 'config', 'migrations', 'admin-views', 'bin', 'docs', 'tests']);
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

    public function testUploadsHtaccessWebpNosniffAndNoHandler(): void
    {
        $c = (string)file_get_contents(ROOT . '/public/uploads/.htaccess');
        $this->assertStringContainsString('AddType image/webp .webp', $c);
        $this->assertMatchesRegularExpression('~Header set X-Content-Type-Options "nosniff"~', $c);
        $this->assertStringContainsString('RemoveHandler .php', $c);
        $this->assertStringContainsString('SetHandler none', $c);
    }

    public function testPublicHtaccess(): void
    {
        $c = (string)file_get_contents(ROOT . '/public/.htaccess');
        $this->assertStringContainsString('index.php', $c);
        $this->assertStringContainsString('Options -Indexes', $c);
    }

    /** Шаблон FilesMatch из корневого .htaccess (вне IfModule mod_rewrite) */
    private function filesMatchPattern(): string
    {
        $c = (string)file_get_contents(ROOT . '/.htaccess');
        $outside = (string)preg_replace('~<IfModule mod_rewrite\.c>.*?</IfModule>~s', '', $c);
        $this->assertSame(1, preg_match('~<FilesMatch\s+"([^"]+)"~', $outside, $m), 'FilesMatch вне mod_rewrite не найден');
        return '~' . str_replace('~', '\~', $m[1]) . '~';
    }

    /** @dataProvider publicFiles */
    public function testFilesMatchDoesNotBlockPublicFiles(string $name): void
    {
        $this->assertSame(0, preg_match($this->filesMatchPattern(), $name), "$name не должен блокироваться");
    }

    public static function publicFiles(): array
    {
        return [['sitemap.xml'], ['data.json'], ['manifest.json'], ['feed.xml'], ['robots.txt'], ['style.css']];
    }

    /** @dataProvider secretFiles */
    public function testFilesMatchBlocksServiceFiles(string $name): void
    {
        $this->assertSame(1, preg_match($this->filesMatchPattern(), $name), "$name должен блокироваться");
    }

    public static function secretFiles(): array
    {
        return [['composer.json'], ['composer.lock'], ['package.json'], ['package-lock.json'], ['CLAUDE.md'],
            ['.env'], ['x.sqlite'], ['phpunit.xml'], ['deploy.yml']];
    }

    public function testSessionFallsBackToStorageSessions(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open недоступен');
        }
        // Session::start() в CLI — заглушка, поэтому запускаем встроенный сервер с недоступным save_path
        $tmp = sys_get_temp_dir() . '/sadik-sess-' . bin2hex(random_bytes(4));
        mkdir($tmp . '/storage', 0775, true);
        $router = $tmp . '/router.php';
        file_put_contents($router, "<?php define('ROOT', " . var_export(ROOT, true) . ");"
            . "require ROOT.'/app/autoload.php'; require ROOT.'/app/helpers.php';"
            . "echo csrf_token(); echo '|' . session_save_path();");
        $port = random_int(20000, 40000);
        $proc = proc_open(
            [PHP_BINARY, '-d', 'session.save_path=/nonexistent-sadik/sess', '-S', "127.0.0.1:$port", $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $tmp,
            ['APP_ENV' => 'testing', 'APP_STORAGE_DIR' => $tmp . '/storage', 'APP_CONFIG_FILE' => $tmp . '/c.php']
        );
        try {
            $body = false;
            for ($i = 0; $i < 30 && $body === false; $i++) {
                usleep(100000);
                $body = @file_get_contents("http://127.0.0.1:$port/");
            }
            $this->assertNotFalse($body, 'сервер не ответил');
            [$token, $savePath] = explode('|', (string)$body, 2);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
            $this->assertSame($tmp . '/storage/sessions', $savePath);
            $this->assertNotEmpty(glob($tmp . '/storage/sessions/sess_*'));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($tmp);
        }
    }
}
