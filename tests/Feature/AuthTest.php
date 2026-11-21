<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth;
use App\Core\AuthResult;
use App\Core\Database;
use App\Models\User;
use Tests\AuthTestCase;

final class AuthTest extends AuthTestCase
{
    public function testSuccessfulLogin(): void
    {
        $_SESSION['_csrf'] = 'old';
        $r = Auth::attempt(' OWNER ', 'Owner-pass-2026', false, '10.0.0.1');
        $this->assertSame(AuthResult::OK, $r->status);
        $this->assertSame($this->ownerId, $_SESSION['user_id']);
        $this->assertNotSame('old', $_SESSION['_csrf']);
        $this->assertNotNull(User::find($this->ownerId)['last_login_at']);
        $this->assertContains('login:' . $this->ownerId . ':' . $this->ownerId, $this->audit());
        $this->assertSame($this->ownerId, Auth::user()['id'] ?? null);
    }

    public function testWrongPasswordAndUnknownLoginSameResult(): void
    {
        $a = Auth::attempt('owner', 'wrong-pass', false, '10.0.0.1');
        $b = Auth::attempt('nobody', 'wrong-pass', false, '10.0.0.1');
        $this->assertSame(AuthResult::INVALID, $a->status);
        $this->assertSame(AuthResult::INVALID, $b->status);
        $this->assertSame(2, (int)Database::fetchValue('SELECT COUNT(*) FROM login_attempts'));
        $this->assertSame(['login_failed:null:' . $this->ownerId, 'login_failed:null:null'], $this->audit());
    }

    public function testBlockedUserWithRightPassword(): void
    {
        User::setActive($this->editorId, false);
        $r = Auth::attempt('editor', 'Editor-pass-2026', false, '10.0.0.1');
        $this->assertSame(AuthResult::BLOCKED, $r->status);
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM login_attempts'));
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertSame(AuthResult::INVALID, Auth::attempt('editor', 'bad', false, '10.0.0.1')->status);
    }

    public function testLockAfterFiveAndNoPasswordVerifyAfter(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->assertSame(AuthResult::INVALID, Auth::attempt('owner', 'bad' . $i, false, '10.0.0.1')->status);
        }
        $fifth = Auth::attempt('owner', 'bad5', false, '10.0.0.1');
        $this->assertSame(AuthResult::LOCKED, $fifth->status);
        $this->assertSame(15, $fifth->minutes);
        $sixth = Auth::attempt('owner', 'Owner-pass-2026', false, '10.0.0.1');
        $this->assertSame(AuthResult::LOCKED, $sixth->status);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertSame(5, (int)Database::fetchValue('SELECT COUNT(*) FROM login_attempts'));
        $this->assertSame(1, (int)Database::fetchValue("SELECT COUNT(*) FROM audit_log WHERE action = 'login_locked'"));
        $this->assertSame(5, (int)Database::fetchValue("SELECT COUNT(*) FROM audit_log WHERE action = 'login_failed'"));
        // после 15 минут — вход работает
        Database::query('UPDATE login_attempts SET created_at = ?', [gmdate('Y-m-d H:i:s', time() - 901)]);
        $this->assertTrue(Auth::attempt('owner', 'Owner-pass-2026', false, '10.0.0.1')->ok());
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM login_attempts'));
    }

    public function testRehashOnLogin(): void
    {
        Database::query('UPDATE users SET password_hash = ? WHERE id = ?',
            [password_hash('Owner-pass-2026', PASSWORD_BCRYPT, ['cost' => 4]), $this->ownerId]);
        $this->assertTrue(Auth::attempt('owner', 'Owner-pass-2026', false, '10.0.0.1')->ok());
        $hash = (string)User::find($this->ownerId)['password_hash'];
        $this->assertFalse(password_needs_rehash($hash, PASSWORD_DEFAULT));
        $this->assertTrue(password_verify('Owner-pass-2026', $hash));
    }

    public function testTimeout(): void
    {
        $this->loginAs('owner');
        $_SESSION['_last_activity'] = time() - 7100;
        $this->nextRequest();
        $this->assertNotNull(Auth::user());
        $this->assertGreaterThanOrEqual(time() - 1, $_SESSION['_last_activity']);

        $_SESSION['_last_activity'] = time() - 7201;
        $this->nextRequest();
        $this->assertNull(Auth::user());
        $this->assertSame('timeout', Auth::endReason());
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testAuthVersionEndsSession(): void
    {
        $this->loginAs('editor');
        Database::query('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?', [$this->editorId]);
        $this->nextRequest();
        $this->assertNull(Auth::user());
        $this->assertSame('version', Auth::endReason());
    }

    public function testBlockedAndDeletedEndSession(): void
    {
        $this->loginAs('editor');
        User::setActive($this->editorId, false);
        $this->nextRequest();
        $this->assertNull(Auth::user());
    }

    public function testBumpVersionForCurrentUserKeepsSession(): void
    {
        $this->loginAs('editor');
        Auth::bumpVersion($this->editorId);
        $this->assertSame(2, $_SESSION['auth_version']);
        $this->nextRequest();
        $this->assertNotNull(Auth::user());
    }

    public function testLogout(): void
    {
        $this->loginAs('owner');
        Auth::logout();
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $this->assertContains('logout:' . $this->ownerId . ':' . $this->ownerId, $this->audit());
        $this->nextRequest();
        $this->assertNull(Auth::user());
    }
}
