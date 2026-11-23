<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Admin\AuditController;
use App\Core\Backup;
use App\Core\Database;
use App\Core\SecurityHeaders;
use Tests\TestCase;

final class BackupTest extends TestCase
{
    private string|false $oldStorage;
    private string|false $oldUploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freshDatabase();
        $this->oldStorage = getenv('APP_STORAGE_DIR');
        $this->oldUploads = getenv('APP_UPLOADS_DIR');
        $root = $this->tempDir();
        mkdir($root . '/storage/backups', 0775, true);
        mkdir($root . '/uploads/images', 0775, true);
        file_put_contents($root . '/uploads/images/a.jpg', 'jpegdata');
        putenv('APP_STORAGE_DIR=' . $root . '/storage');
        putenv('APP_UPLOADS_DIR=' . $root . '/uploads');
    }

    protected function tearDown(): void
    {
        putenv($this->oldStorage === false ? 'APP_STORAGE_DIR' : 'APP_STORAGE_DIR=' . $this->oldStorage);
        putenv($this->oldUploads === false ? 'APP_UPLOADS_DIR' : 'APP_UPLOADS_DIR=' . $this->oldUploads);
        parent::tearDown();
    }

    public function testAutoBackupOncePerDayAndValidSqlite(): void
    {
        $name = Backup::auto();
        $this->assertNotNull($name);
        $this->assertNull(Backup::auto());
        $this->assertCount(1, Backup::listDb());
        $copy = new \PDO('sqlite:' . Backup::path($name));
        $this->assertGreaterThan(0, (int)$copy->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    }

    public function testPruneKeepsFourteen(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            file_put_contents(Backup::dir() . sprintf('/db-202601%02d-000000.sqlite', $i), 'x');
        }
        $this->assertSame(6, Backup::prune());
        $list = Backup::listDb();
        $this->assertCount(14, $list);
        $this->assertSame('db-20260120-000000.sqlite', $list[0]['name']);
    }

    public function testNameValidationBlocksTraversal(): void
    {
        $this->assertFalse(Backup::validName('../config.php'));
        $this->assertFalse(Backup::delete('../x'));
        $this->assertNull(Backup::path('db-1.sqlite'));
    }

    public function testArchiveContainsDbUploadsAndConfig(): void
    {
        if (!Backup::zipAvailable()) {
            $this->markTestSkipped('нет zip');
        }
        $zipPath = Backup::buildArchive();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $this->assertNotFalse($zip->locateName('database.sqlite'));
        $this->assertNotFalse($zip->locateName('public/uploads/images/a.jpg'));
        $this->assertNotFalse($zip->locateName('README.txt'));
        $zip->close();
        unlink($zipPath);
        $this->assertSame([], glob(Backup::dir() . '/.tmp-*'));
    }

    public function testSecurityHeaders(): void
    {
        $site = SecurityHeaders::build(false, false, '2gis.kz');
        $admin = SecurityHeaders::build(true, true);
        $this->assertSame('DENY', $admin['X-Frame-Options']);
        $this->assertSame('SAMEORIGIN', $site['X-Frame-Options']);
        $this->assertArrayHasKey('Strict-Transport-Security', $admin);
        $this->assertArrayNotHasKey('Strict-Transport-Security', $site);
        $this->assertStringContainsString("script-src 'self';", $site['Content-Security-Policy']);
        $this->assertStringNotContainsString('unsafe-inline', explode('style-src', $site['Content-Security-Policy'])[0]);
        $this->assertStringContainsString('https://2gis.kz', $site['Content-Security-Policy']);
        $this->assertStringContainsString('youtube-nocookie.com', $admin['Content-Security-Policy']);
    }

    public function testAuditLabels(): void
    {
        $this->assertSame('Создал новость', AuditController::label('news.create', 'news'));
        $this->assertSame('Вошёл в админку', AuditController::label('login', 'user'));
        $this->assertSame('неизвестное', AuditController::label('неизвестное', 'x'));
    }
}
