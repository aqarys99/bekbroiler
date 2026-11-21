<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth;
use App\Core\Database;
use App\Core\RememberMe;
use Tests\AuthTestCase;

final class RememberMeTest extends AuthTestCase
{
    private function issue(): array
    {
        $this->assertTrue(Auth::attempt('editor', 'Editor-pass-2026', true, '10.0.0.1')->ok());
        $raw = $_COOKIE['sadik_remember'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}:[0-9a-f]{64}$/', $raw);
        return explode(':', $raw);
    }

    /** Вернуться к «закрытому браузеру»: сессии нет, cookie остался. */
    private function restart(string $cookie): void
    {
        $this->resetRequest();
        $_COOKIE['sadik_remember'] = $cookie;
    }

    public function testIssueStoresOnlyHash(): void
    {
        [$sel, $val] = $this->issue();
        $row = Database::fetch('SELECT * FROM remember_tokens');
        $this->assertSame($sel, $row['selector']);
        $this->assertSame(hash('sha256', $val), $row['token_hash']);
        $this->assertStringNotContainsString($val, implode('|', $row));
        $days = (strtotime($row['expires_at'] . ' UTC') - time()) / 86400;
        $this->assertEqualsWithDelta(30, $days, 0.01);
    }

    public function testNoCookieWithoutRemember(): void
    {
        Auth::attempt('editor', 'Editor-pass-2026', false, '10.0.0.1');
        $this->assertArrayNotHasKey('sadik_remember', $_COOKIE);
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM remember_tokens'));
    }

    public function testLoginByCookieRotates(): void
    {
        [$sel, $val] = $this->issue();
        $hashBefore = Database::fetchValue('SELECT token_hash FROM remember_tokens');
        $expBefore = Database::fetchValue('SELECT expires_at FROM remember_tokens');
        $this->restart("$sel:$val");
        $this->assertSame($this->editorId, Auth::user()['id'] ?? null);
        $this->assertNotSame("$sel:$val", $_COOKIE['sadik_remember']);
        $this->assertStringStartsWith($sel . ':', $_COOKIE['sadik_remember']);
        $this->assertNotSame($hashBefore, Database::fetchValue('SELECT token_hash FROM remember_tokens'));
        $this->assertSame($expBefore, Database::fetchValue('SELECT expires_at FROM remember_tokens'));
        $this->assertSame(1, (int)Database::fetchValue("SELECT COUNT(*) FROM audit_log WHERE action = 'login_remember'"));
        // старый validator больше не работает и ломает цепочку
        $this->restart("$sel:$val");
        $this->assertNull(Auth::user());
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM remember_tokens'));
    }

    public function testForgedValidatorDeletesAllUserTokens(): void
    {
        [$sel] = $this->issue();
        $this->resetRequest();
        Auth::attempt('editor', 'Editor-pass-2026', true, '10.0.0.1');
        $this->assertSame(2, (int)Database::fetchValue('SELECT COUNT(*) FROM remember_tokens'));
        $this->restart($sel . ':' . str_repeat('a', 64));
        $this->assertNull(Auth::user());
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM remember_tokens'));
        $this->assertArrayNotHasKey('sadik_remember', $_COOKIE);
    }

    public function testExpired(): void
    {
        [$sel, $val] = $this->issue();
        Database::query('UPDATE remember_tokens SET expires_at = ?', [gmdate('Y-m-d H:i:s', time() - 5)]);
        $this->restart("$sel:$val");
        $this->assertNull(Auth::user());
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM remember_tokens'));
    }

    public function testBlockedUser(): void
    {
        [$sel, $val] = $this->issue();
        Database::query('UPDATE users SET is_active = 0 WHERE id = ?', [$this->editorId]);
        $this->restart("$sel:$val");
        $this->assertNull(Auth::user());
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM remember_tokens'));
    }

    public function testGarbageCookie(): void
    {
        foreach (['junk', str_repeat('z', 89), '12:34', ''] as $garbage) {
            $this->restart($garbage);
            $this->assertNull(Auth::user());
            $this->assertArrayNotHasKey('sadik_remember', $_COOKIE);
        }
    }

    public function testLimitTenTokens(): void
    {
        for ($i = 0; $i < 12; $i++) {
            RememberMe::issue($this->editorId);
        }
        $this->assertSame(10, (int)Database::fetchValue('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ' . $this->editorId));
    }

    public function testTimeoutWithValidCookieLogsInAgain(): void
    {
        [$sel, $val] = $this->issue();
        $_SESSION['_last_activity'] = time() - 7201;
        $this->nextRequest();
        $this->assertNotNull(Auth::user());
    }

    public function testLogoutRemovesOnlyThisDeviceToken(): void
    {
        $this->issue();
        $this->resetRequest();
        $this->issue();
        $this->assertSame(2, (int)Database::fetchValue('SELECT COUNT(*) FROM remember_tokens'));
        Auth::logout();
        $this->assertSame(1, (int)Database::fetchValue('SELECT COUNT(*) FROM remember_tokens'));
    }

    public function testBumpVersionDeletesTokens(): void
    {
        $this->issue();
        Auth::bumpVersion($this->editorId);
        $this->assertSame(0, (int)Database::fetchValue('SELECT COUNT(*) FROM remember_tokens'));
    }
}
