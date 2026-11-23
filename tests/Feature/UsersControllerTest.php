<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\Admin\ProfileController;
use App\Controllers\Admin\UsersController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Guard;
use App\Models\User;
use Tests\AuthTestCase;

final class UsersControllerTest extends AuthTestCase
{
    private function actions(): array
    {
        return array_values(array_filter($this->audit(), static fn($a) => !str_starts_with($a, 'login')));
    }

    private function clearAudit(): void
    {
        Database::query('DELETE FROM audit_log');
    }

    public function testStoreCreatesAndAudits(): void
    {
        $this->loginAs('owner');
        $this->clearAudit();
        $this->post(['name' => ' Новая   Учитель ', 'login' => 'Teacher.1', 'role' => 'editor', 'password' => 'Teacher-pass-1']);
        $r = $this->redirectOf(fn() => (new UsersController())->store());
        $this->assertSame('/admin/users', $r->url);
        $u = User::findByLogin('teacher.1');
        $this->assertSame('Новая Учитель', $u['name']);
        $this->assertTrue(password_verify('Teacher-pass-1', $u['password_hash']));
        $this->assertSame(['create:' . $this->ownerId . ':' . $u['id']], $this->actions());
    }

    public function testStoreValidationNoAudit(): void
    {
        $this->loginAs('owner');
        $this->clearAudit();
        $this->post(['name' => '', 'login' => 'OWNER', 'role' => 'admin', 'password' => 'short']);
        http_response_code(200);
        $out = (new UsersController())->store();
        $this->assertSame(422, http_response_code());
        $this->assertStringContainsString('tpl:users/form', $out);
        $this->assertSame([], $this->audit());
        $this->assertSame(2, (int)Database::fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function testDuplicateLoginCaseInsensitive(): void
    {
        $this->loginAs('owner');
        $this->post(['name' => 'X', 'login' => 'EDITOR', 'role' => 'editor', 'password' => 'Teacher-pass-1']);
        (new UsersController())->store();
        $this->assertSame(422, http_response_code());
        $this->assertSame(2, (int)Database::fetchValue('SELECT COUNT(*) FROM users'));
    }

    public function testUpdateAuditsAndNoChange(): void
    {
        $this->loginAs('owner');
        $this->clearAudit();
        $p = ['id' => (string)$this->editorId];
        $this->post(['name' => 'Петрова Анна', 'login' => 'editor', 'role' => 'editor']);
        $this->redirectOf(fn() => (new UsersController())->update($p));
        $this->assertSame([], $this->audit(), 'без изменений — без записи');

        $this->nextRequest();
        $this->post(['name' => 'Новое Имя', 'login' => 'editor', 'role' => 'owner']);
        $this->redirectOf(fn() => (new UsersController())->update($p));
        $this->assertSame(
            ['update:' . $this->ownerId . ':' . $this->editorId, 'role_change:' . $this->ownerId . ':' . $this->editorId],
            $this->audit()
        );
    }

    public function testSelfProtection(): void
    {
        $this->loginAs('owner');
        $this->clearAudit();
        $p = ['id' => (string)$this->ownerId];
        $this->post([]);
        (new UsersController())->block($p);
        $this->assertSame(422, http_response_code());
        $this->assertSame(1, (int)User::find($this->ownerId)['is_active']);

        $this->nextRequest();
        $this->post(['name' => 'Иванова Мария', 'login' => 'owner', 'role' => 'editor']);
        (new UsersController())->update($p);
        $this->assertSame(422, http_response_code());
        $this->assertSame('owner', User::find($this->ownerId)['role']);
        $this->assertSame([], $this->audit());
    }

    public function testLastOwnerProtectedAgainstSecondOwnerDemotions(): void
    {
        $second = User::create('Второй', 'owner2', 'Owner2-pass-2026', 'owner');
        $this->loginAs('owner');
        // owner понижает owner2: можно (останется один)
        $this->post(['name' => 'Второй', 'login' => 'owner2', 'role' => 'editor']);
        $this->redirectOf(fn() => (new UsersController())->update(['id' => (string)$second]));
        $this->assertSame('editor', User::find($second)['role']);
        $this->assertSame(1, User::countActiveOwners());

        // вернуть owner2 в owner и логин за него: он пытается понизить owner, пока тот единственный другой
        User::update($second, 'Второй', 'owner2', 'owner');
        $this->resetRequest();
        $this->loginAs('owner2', 'Owner2-pass-2026');
        $this->post(['name' => 'Иванова Мария', 'login' => 'owner', 'role' => 'editor']);
        $this->redirectOf(fn() => (new UsersController())->update(['id' => (string)$this->ownerId]));
        $this->assertSame('editor', User::find($this->ownerId)['role']);

        // теперь owner2 — последний активный: понизить/заблокировать нельзя
        $this->assertSame(1, User::countActiveOwners());
        $this->nextRequest();
        $this->post([]);
        (new UsersController())->block(['id' => (string)$second]);
        $this->assertSame(422, http_response_code());
        $this->assertSame(1, User::countActiveOwners());
    }

    public function testBlockInvalidatesSessionsAndTokens(): void
    {
        $this->loginAs('editor');
        $editorSession = $_SESSION;
        \App\Core\RememberMe::issue($this->editorId);
        $this->resetRequest();
        $this->loginAs('owner');
        $this->clearAudit();
        $this->post([]);
        $r = $this->redirectOf(fn() => (new UsersController())->block(['id' => (string)$this->editorId]));
        $this->assertSame('/admin/users', $r->url);
        $this->assertSame(0, (int)User::find($this->editorId)['is_active']);
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM remember_tokens'));
        $this->assertSame(['block:' . $this->ownerId . ':' . $this->editorId], $this->audit());

        // повторная блокировка — без записи
        $this->nextRequest();
        $this->post([]);
        $this->redirectOf(fn() => (new UsersController())->block(['id' => (string)$this->editorId]));
        $this->assertCount(1, $this->audit());

        // старая сессия редактора мертва
        $this->resetRequest();
        $_SESSION = $editorSession;
        $this->assertNull(Auth::user());

        // разблокировка
        $this->resetRequest();
        $this->loginAs('owner');
        $this->post([]);
        $this->redirectOf(fn() => (new UsersController())->unblock(['id' => (string)$this->editorId]));
        $this->assertSame(1, (int)User::find($this->editorId)['is_active']);
        $this->assertContains('unblock:' . $this->ownerId . ':' . $this->editorId, $this->audit());
        $this->resetRequest();
        $this->assertTrue(Auth::attempt('editor', 'Editor-pass-2026', false, '10.0.0.1')->ok());
    }

    public function testResetPassword(): void
    {
        $this->loginAs('editor');
        $editorSession = $_SESSION;
        $this->resetRequest();
        $this->loginAs('owner');
        $this->clearAudit();
        $this->post(['password' => 'New-pass-12345']);
        $this->redirectOf(fn() => (new UsersController())->resetPassword(['id' => (string)$this->editorId]));
        $this->assertSame(['password_reset:' . $this->ownerId . ':' . $this->editorId], $this->audit());
        $this->assertTrue(password_verify('New-pass-12345', User::find($this->editorId)['password_hash']));
        $this->resetRequest();
        $_SESSION = $editorSession;
        $this->assertNull(Auth::user());

        // себе — 403, GET формы себе — на профиль
        $this->resetRequest();
        $this->loginAs('owner');
        $this->post(['password' => 'New-pass-12345']);
        try {
            (new UsersController())->resetPassword(['id' => (string)$this->ownerId]);
            $this->fail('403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $r = $this->redirectOf(fn() => (new UsersController())->passwordForm(['id' => (string)$this->ownerId]));
        $this->assertSame('/admin/profile', $r->url);
    }

    public function testNotFound(): void
    {
        $this->loginAs('owner');
        $this->expectException(HttpException::class);
        (new UsersController())->edit(['id' => '9999']);
    }

    public function testProfilePasswordChange(): void
    {
        $this->loginAs('editor');
        $this->clearAudit();
        $this->post(['current_password' => 'bad', 'new_password' => 'Brand-new-pass1', 'new_password_confirm' => 'Brand-new-pass1']);
        (new ProfileController())->changePassword();
        $this->assertSame(422, http_response_code());
        $this->assertSame([], $this->audit());
        $this->assertSame(0, (int)Database::fetchValue("SELECT COUNT(*) FROM login_attempts WHERE scope = 'login'"));

        $this->nextRequest();
        $this->post(['current_password' => 'Editor-pass-2026', 'new_password' => 'Editor-pass-2026', 'new_password_confirm' => 'Editor-pass-2026']);
        (new ProfileController())->changePassword();
        $this->assertSame(422, http_response_code());

        $this->nextRequest();
        $this->post(['current_password' => 'Editor-pass-2026', 'new_password' => 'Brand-new-pass1', 'new_password_confirm' => 'Different-pass1']);
        (new ProfileController())->changePassword();
        $this->assertSame(422, http_response_code());

        $this->nextRequest();
        $this->post(['current_password' => 'Editor-pass-2026', 'new_password' => 'Brand-new-pass1', 'new_password_confirm' => 'Brand-new-pass1']);
        $this->redirectOf(fn() => (new ProfileController())->changePassword());
        $this->assertSame(['password_change:' . $this->editorId . ':' . $this->editorId], $this->audit());
        $this->nextRequest();
        $this->assertNotNull(Auth::user(), 'текущая сессия остаётся');
    }

    public function testGuardOwnerRoleAndCsrf(): void
    {
        $this->loginAs('editor');
        $h = Guard::wrap(static fn() => 'ok', 'owner');
        try {
            $h([]);
            $this->fail('403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->nextRequest();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_csrf' => 'wrong'];
        try {
            Guard::wrap(static fn() => 'ok')([]);
            $this->fail('400');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
        $this->post([]);
        $this->assertSame('ok', Guard::wrap(static fn() => 'ok')([]));
    }

    public function testGuardGuestRedirects(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin/users?x=1';
        $r = $this->redirectOf(fn() => Guard::wrap(static fn() => 'ok', 'owner')([]));
        $this->assertSame('/admin/login?next=' . rawurlencode('/admin/users?x=1'), $r->url);
        $this->nextRequest();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $r = $this->redirectOf(fn() => Guard::wrap(static fn() => 'ok')([]));
        $this->assertSame('/admin/login', $r->url);
    }

    /** Owner понижен после Guard, но до действия контроллера: перечитывание $me даёт 403. */
    public function testOwnerDemotedInsideWindowGets403(): void
    {
        $this->loginAs('owner');
        Auth::user(); // Guard уже получил пользователя с ролью owner
        Database::query("UPDATE users SET role='editor' WHERE id = ?", [$this->ownerId]);
        $this->post(['name' => 'Хак', 'login' => 'hack1', 'role' => 'editor', 'password' => 'Teacher-pass-1']);
        $calls = [
            'index' => fn() => (new UsersController())->index(),
            'create' => fn() => (new UsersController())->create(),
            'store' => fn() => (new UsersController())->store(),
            'update' => fn() => (new UsersController())->update(['id' => (string)$this->editorId]),
            'block' => fn() => (new UsersController())->block(['id' => (string)$this->editorId]),
            'unblock' => fn() => (new UsersController())->unblock(['id' => (string)$this->editorId]),
            'resetPassword' => fn() => (new UsersController())->resetPassword(['id' => (string)$this->editorId]),
        ];
        $hash = Database::fetchValue('SELECT password_hash FROM users WHERE id = ?', [$this->editorId]);
        foreach ($calls as $name => $fn) {
            try {
                $fn();
                $this->fail("$name не вернул 403");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(), $name);
            }
        }
        $this->assertSame(2, (int)Database::fetchValue('SELECT COUNT(*) FROM users'));
        $this->assertSame($hash, Database::fetchValue('SELECT password_hash FROM users WHERE id = ?', [$this->editorId]));
    }
}
