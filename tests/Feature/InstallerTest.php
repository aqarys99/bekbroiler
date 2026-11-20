<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\AlreadyInstalledException;
use App\Core\Config;
use App\Core\Database;
use App\Core\Installer;
use App\Core\InstallException;
use App\Core\ValidationException;
use Tests\TestCase;

final class InstallerTest extends TestCase
{
    private string $dir;
    private string $oldStorage;
    private string $oldConfig;

    protected function setUp(): void
    {
        $this->oldStorage = (string)getenv('APP_STORAGE_DIR');
        $this->oldConfig = (string)getenv('APP_CONFIG_FILE');
        $this->dir = $this->tempDir();
        putenv('APP_STORAGE_DIR=' . $this->dir . '/storage');
        putenv('APP_CONFIG_FILE=' . $this->dir . '/config/config.php');
        Config::reset();
        Database::setConnection(null);
    }

    protected function tearDown(): void
    {
        Database::setConnection(null);
        putenv('APP_STORAGE_DIR=' . $this->oldStorage);
        putenv('APP_CONFIG_FILE=' . $this->oldConfig);
        parent::tearDown();
    }

    private function input(array $o = []): array
    {
        return $o + [
            'site_name' => 'Детский сад «Солнышко»', 'owner_name' => 'Иванова  Мария', 'login' => 'Admin',
            'password' => 'Secret-pass-1', 'password_confirm' => 'Secret-pass-1',
        ];
    }

    private function assertNothingCreated(): void
    {
        $this->assertFileDoesNotExist($this->dir . '/config/config.php');
        $this->assertFileDoesNotExist($this->dir . '/storage/database.sqlite');
        $this->assertFalse(Installer::isInstalled());
    }

    public function testSuccessfulInstall(): void
    {
        $id = Installer::install($this->input());
        $this->assertSame(1, $id);
        $this->assertTrue(Installer::isInstalled());

        $u = Database::fetch('SELECT * FROM users WHERE id = ?', [$id]);
        $this->assertSame('owner', $u['role']);
        $this->assertSame('admin', $u['login']);
        $this->assertSame('Иванова Мария', $u['name']);
        $this->assertTrue(password_verify('Secret-pass-1', $u['password_hash']));
        $this->assertMatchesRegularExpression('/^\$(2y|argon2)/', $u['password_hash']);
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM users'));
        $this->assertSame('001', Database::fetchValue('SELECT version FROM schema_migrations'));
        $log = Database::fetchAll('SELECT * FROM audit_log');
        $this->assertCount(1, $log);
        $this->assertSame(['create', 'user', $id], [$log[0]['action'], $log[0]['entity'], (int)$log[0]['entity_id']]);

        // I-19: название читается обратно из settings.site_name
        $this->assertSame('Детский сад «Солнышко»', json_decode((string)Database::fetchValue("SELECT value FROM settings WHERE key='site_name'")));

        $cfg = require $this->dir . '/config/config.php';
        $this->assertIsArray($cfg);
        $this->assertFalse($cfg['debug']);
        $this->assertSame('production', $cfg['env']);
        $lock = json_decode((string)file_get_contents($this->dir . '/storage/install.lock'), true);
        $this->assertArrayHasKey('installed_at', $lock);
    }

    /** I-18 (часть 01): поиск владельца без учёта регистра */
    public function testOwnerFoundCaseInsensitive(): void
    {
        Installer::install($this->input());
        foreach (['admin', 'ADMIN', 'Admin'] as $l) {
            $row = Database::fetch('SELECT role, password_hash FROM users WHERE login = ?', [$l]);
            $this->assertSame('owner', $row['role']);
            $this->assertTrue(password_verify('Secret-pass-1', $row['password_hash']));
        }
    }

    /** I-21 */
    public function testSecondInstallRefused(): void
    {
        Installer::install($this->input());
        $cfgMtime = filemtime($this->dir . '/config/config.php');
        $hash = Database::fetchValue('SELECT password_hash FROM users');
        try {
            Installer::install($this->input(['login' => 'second', 'password' => 'Other-pass-22', 'password_confirm' => 'Other-pass-22']));
            $this->fail('expected AlreadyInstalledException');
        } catch (AlreadyInstalledException) {
        }
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM users'));
        $this->assertSame($hash, Database::fetchValue('SELECT password_hash FROM users'));
        $this->assertSame($cfgMtime, filemtime($this->dir . '/config/config.php'));
    }

    public function testHeldLockMeansAlreadyInstalled(): void
    {
        mkdir($this->dir . '/storage', 0775, true);
        $h = fopen($this->dir . '/storage/install.lock.tmp', 'c');
        flock($h, LOCK_EX);
        try {
            $this->expectException(AlreadyInstalledException::class);
            Installer::install($this->input());
        } finally {
            fclose($h);
        }
    }

    /** @dataProvider invalidProvider */
    public function testValidationErrors(array $override, string $field, string $key): void
    {
        try {
            Installer::install($this->input($override));
            $this->fail('ValidationException expected');
        } catch (ValidationException $e) {
            $this->assertSame($key, $e->errors()[$field] ?? null);
        }
        $this->assertNothingCreated();
    }

    public static function invalidProvider(): array
    {
        return [
            'site empty'     => [['site_name' => '  '], 'site_name', 'validation.site_name.required'],
            'site short'     => [['site_name' => 'a'], 'site_name', 'validation.site_name.length'],
            'site long'      => [['site_name' => str_repeat('я', 151)], 'site_name', 'validation.site_name.length'],
            'name empty'     => [['owner_name' => ''], 'owner_name', 'validation.owner_name.required'],
            'name long'      => [['owner_name' => str_repeat('я', 101)], 'owner_name', 'validation.owner_name.length'],
            'login empty'    => [['login' => ''], 'login', 'validation.login.required'],
            'login short'    => [['login' => 'ab'], 'login', 'validation.login.length'],
            'login long'     => [['login' => str_repeat('a', 51)], 'login', 'validation.login.length'],
            'login cyr'      => [['login' => 'админ'], 'login', 'validation.login.format'],
            'login space'    => [['login' => 'a b c'], 'login', 'validation.login.format'],
            'pass empty'     => [['password' => '', 'password_confirm' => ''], 'password', 'validation.password.required'],
            'pass short'     => [['password' => '1234567', 'password_confirm' => '1234567'], 'password', 'validation.password.min'],
            'pass long'      => [['password' => str_repeat('a', 65), 'password_confirm' => str_repeat('a', 65)], 'password', 'validation.password.max'],
            'pass 72 bytes'  => [['password' => str_repeat('я', 40), 'password_confirm' => str_repeat('я', 40)], 'password', 'validation.password.max'],
            'pass mismatch'  => [['password_confirm' => 'different-1'], 'password_confirm', 'validation.password.confirm'],
        ];
    }

    public function testRequirementsFailBlocksInstall(): void
    {
        mkdir($this->dir . '/storage', 0775, true);
        file_put_contents($this->dir . '/storage/fake-requirements.json', '{"pdo_sqlite":"fail"}');
        try {
            Installer::install($this->input());
            $this->fail('InstallException expected');
        } catch (InstallException $e) {
            $this->assertSame(422, $e->status());
            $this->assertSame('install.error.requirements', $e->messageKey());
        }
        $this->assertNothingCreated();
    }

    public function testExistingOwnerInDbRefused(): void
    {
        $dbFile = $this->dir . '/storage/database.sqlite';
        $pdo = \App\Core\Database::open($dbFile);
        (new \App\Core\Migrator($pdo))->migrate();
        $pdo->exec("INSERT INTO users (name, login, password_hash, role) VALUES ('X','x','h','owner')");
        unset($pdo);
        try {
            Installer::install($this->input());
            $this->fail('InstallException expected');
        } catch (InstallException $e) {
            $this->assertSame('install.error.owner_exists', $e->messageKey());
        }
        $this->assertFileDoesNotExist($this->dir . '/config/config.php');
        $this->assertFalse(Installer::isInstalled());
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function testFailureCleansUpAndHidesDetails(): void
    {
        // каталог БД нельзя создать: «родитель» — обычный файл
        file_put_contents($this->dir . '/blocker', 'x');
        Config::set(['db' => ['driver' => 'sqlite', 'path' => $this->dir . '/blocker/db.sqlite']]);
        try {
            Installer::install($this->input());
            $this->fail('InstallException expected');
        } catch (InstallException $e) {
            $this->assertSame('install.error.failed', $e->messageKey());
            $this->assertSame(500, $e->status());
        }
        $this->assertFileDoesNotExist($this->dir . '/config/config.php');
        $this->assertFalse(Installer::isInstalled());
        $logs = glob($this->dir . '/storage/logs/app-*.log') ?: [];
        $this->assertNotEmpty($logs);
    }
}
