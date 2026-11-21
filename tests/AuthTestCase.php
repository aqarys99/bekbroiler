<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\RedirectException;
use App\Core\View;
use App\Models\User;

/** Общая подготовка: БД со всеми миграциями, пустая сессия, заглушки шаблонов. */
abstract class AuthTestCase extends TestCase
{
    protected \PDO $pdo;
    protected int $ownerId;
    protected int $editorId;

    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
        Config::set(['base_path' => '']);
        $this->pdo = $this->freshDatabase();
        $this->ownerId = User::create('Иванова Мария', 'owner', 'Owner-pass-2026', 'owner');
        $this->editorId = User::create('Петрова Анна', 'editor', 'Editor-pass-2026', 'editor');
        $this->resetRequest();

        $dir = $this->tempDir();
        mkdir($dir . '/layouts');
        mkdir($dir . '/users');
        mkdir($dir . '/profile');
        file_put_contents($dir . '/layouts/admin.php', '<?= $content ?>');
        foreach (['users/index', 'users/form', 'users/password', 'profile/show'] as $t) {
            file_put_contents($dir . '/' . $t . '.php', 'tpl:' . $t . '|<?= $generalError ?? "" ?>');
        }
        View::setPath($dir);
    }

    protected function tearDown(): void
    {
        View::setPath(null);
        $this->resetRequest();
        parent::tearDown();
    }

    protected function resetRequest(): void
    {
        Auth::reset();
        $_SESSION = [];
        $_COOKIE = [];
        $_POST = [];
        $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin';
    }

    /** Имитировать «новый запрос» с той же сессией. */
    protected function nextRequest(): void
    {
        Auth::reset();
        $_POST = [];
        $_GET = [];
    }

    protected function loginAs(string $login, string $password = ''): void
    {
        $password = $password ?: ($login === 'owner' ? 'Owner-pass-2026' : 'Editor-pass-2026');
        $r = Auth::attempt($login, $password, false, '10.0.0.1');
        $this->assertTrue($r->ok());
    }

    protected function post(array $data): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $data + ['_csrf' => csrf_token()];
    }

    /** @return string[] */
    protected function audit(): array
    {
        return array_map(
            static fn($r) => $r['action'] . ':' . ($r['user_id'] ?? 'null') . ':' . ($r['entity_id'] ?? 'null'),
            Database::fetchAll('SELECT * FROM audit_log ORDER BY id')
        );
    }

    protected function redirectOf(callable $fn): RedirectException
    {
        try {
            $fn();
        } catch (RedirectException $e) {
            return $e;
        }
        $this->fail('Ожидался redirect');
    }
}
