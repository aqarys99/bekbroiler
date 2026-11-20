<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Requirements;
use Tests\TestCase;

final class RequirementsTest extends TestCase
{
    private const KEYS = ['php', 'pdo_sqlite', 'fileinfo', 'mbstring', 'image', 'zip',
        'upload_max_filesize', 'post_max_size', 'writable_storage', 'writable_config', 'writable_uploads'];

    private function paths(): array
    {
        $d = $this->tempDir();
        return ['storage' => $d . '/s', 'config' => $d . '/c', 'uploads' => $d . '/u'];
    }

    public function testAllElevenKeys(): void
    {
        $rows = Requirements::check($this->paths());
        $this->assertSame(self::KEYS, array_column($rows, 'key'));
        foreach ($rows as $r) {
            $this->assertContains($r['status'], ['ok', 'warn', 'fail']);
            $this->assertNotSame('', $r['label']);
        }
    }

    public function testFakeFileAppliesInTestingOnly(): void
    {
        $p = $this->paths();
        mkdir($p['storage'], 0775, true);
        file_put_contents($p['storage'] . '/fake-requirements.json', '{"pdo_sqlite":"fail","zip":"warn"}');
        $by = array_column(Requirements::check($p), null, 'key');
        $this->assertSame('fail', $by['pdo_sqlite']['status']);
        $this->assertSame('warn', $by['zip']['status']);
        $this->assertNotSame('', $by['pdo_sqlite']['hint']);
        $this->assertTrue(Requirements::hasFail(Requirements::check($p)));

        putenv('APP_ENV=production');
        try {
            $by = array_column(Requirements::check($p), null, 'key');
            $this->assertSame('ok', $by['pdo_sqlite']['status']);
        } finally {
            putenv('APP_ENV=testing');
        }
    }

    public function testUnwritableFolderFails(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root игнорирует права на запись');
        }
        $p = $this->paths();
        mkdir($p['uploads'], 0555, true);
        $by = array_column(Requirements::check($p), null, 'key');
        $this->assertSame('fail', $by['writable_uploads']['status']);
        chmod($p['uploads'], 0775);
    }

    public function testMissingFoldersAreCreated(): void
    {
        $p = $this->paths();
        $by = array_column(Requirements::check($p), null, 'key');
        $this->assertSame('ok', $by['writable_storage']['status']);
        $this->assertDirectoryExists($p['storage'] . '/logs');
        $this->assertDirectoryExists($p['storage'] . '/backups');
        $this->assertDirectoryExists($p['storage'] . '/cache');
    }

    public function testSessionFolderRequirementPresent(): void
    {
        $rows = Requirements::check($this->paths());
        $sess = array_values(array_filter($rows, fn($r) => str_contains($r['key'], 'session')));
        $this->assertCount(1, $sess, 'ожидается пункт требований для папки сессий');
        $this->assertSame('ok', $sess[0]['status']);
        $this->assertNotSame('', $sess[0]['label']);
        $this->assertNotSame($sess[0]['key'], $sess[0]['label'], 'нет перевода install.req.<key>');
    }
}
