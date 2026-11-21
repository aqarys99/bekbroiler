<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Core\LoginThrottle;
use Tests\TestCase;

final class LoginThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->freshDatabase();
    }

    private function row(string $ip, string $login, int $ago): void
    {
        Database::insert('login_attempts', ['ip' => $ip, 'login' => $login, 'created_at' => gmdate('Y-m-d H:i:s', time() - $ago)]);
    }

    public function testFourDoNotLockFifthLocks(): void
    {
        for ($i = 0; $i < 4; $i++) {
            LoginThrottle::fail('1.1.1.1', 'Owner');
        }
        $this->assertSame(0, LoginThrottle::lockedFor('1.1.1.1', 'owner'));
        LoginThrottle::fail('1.1.1.1', ' OWNER ');
        $left = LoginThrottle::lockedFor('1.1.1.1', 'owner');
        $this->assertGreaterThan(895, $left);
        $this->assertLessThanOrEqual(900, $left);
        $this->assertSame(0, LoginThrottle::lockedFor('1.1.1.1', 'other'));
        $this->assertSame(0, LoginThrottle::lockedFor('2.2.2.2', 'owner'));
    }

    public function testRemainingCountedFromFifth(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->row('1.1.1.1', 'owner', 600);
        }
        $left = LoginThrottle::lockedFor('1.1.1.1', 'owner');
        $this->assertGreaterThan(295, $left);
        $this->assertLessThanOrEqual(300, $left);
    }

    public function testUnlockedAfterFifteenMinutes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->row('1.1.1.1', 'owner', 901);
        }
        $this->assertSame(0, LoginThrottle::lockedFor('1.1.1.1', 'owner'));
    }

    public function testIpBlockOn20DifferentLogins(): void
    {
        for ($i = 0; $i < 19; $i++) {
            LoginThrottle::fail('3.3.3.3', 'user' . $i);
        }
        $this->assertSame(0, LoginThrottle::lockedFor('3.3.3.3', 'fresh'));
        LoginThrottle::fail('3.3.3.3', 'user19');
        $this->assertGreaterThan(0, LoginThrottle::lockedFor('3.3.3.3', 'fresh'));
        $this->assertSame(0, LoginThrottle::lockedFor('4.4.4.4', 'fresh'));
    }

    public function testClearOnlyPair(): void
    {
        for ($i = 0; $i < 5; $i++) {
            LoginThrottle::fail('1.1.1.1', 'a');
            LoginThrottle::fail('1.1.1.1', 'b');
        }
        LoginThrottle::clear('1.1.1.1', 'A');
        $this->assertSame(0, LoginThrottle::lockedFor('1.1.1.1', 'a'));
        $this->assertGreaterThan(0, LoginThrottle::lockedFor('1.1.1.1', 'b'));
    }

    public function testOldRowsPurged(): void
    {
        $this->row('9.9.9.9', 'x', 90000);
        LoginThrottle::fail('1.1.1.1', 'a');
        $this->assertSame(0, (int)Database::fetchValue("SELECT COUNT(*) FROM login_attempts WHERE ip = '9.9.9.9'"));
    }

    public function testLongLoginTruncated(): void
    {
        LoginThrottle::fail('1.1.1.1', str_repeat('Я', 300));
        $this->assertSame(100, mb_strlen((string)Database::fetchValue('SELECT login FROM login_attempts')));
    }
}
