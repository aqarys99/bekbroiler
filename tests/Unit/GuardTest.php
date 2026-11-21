<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Guard;
use Tests\TestCase;

final class GuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set(['base_path' => '']);
    }

    public function testValidNext(): void
    {
        $this->assertSame('/admin/users', Guard::safeNext('/admin/users'));
        $this->assertSame('/admin/users?page=2', Guard::safeNext('/admin/users?page=2'));
        $this->assertSame('/admin/users/5/edit', Guard::safeNext('/admin/users/5/edit'));
        $this->assertSame('/admin', Guard::safeNext('/admin'));
    }

    /** @dataProvider badNext */
    public function testBadNext(mixed $next): void
    {
        $this->assertSame('/admin', Guard::safeNext($next));
    }

    public static function badNext(): array
    {
        return [
            'null' => [null], 'empty' => [''], 'array' => [['x']],
            'abs' => ['https://evil.example'], 'proto-relative' => ['//evil'],
            'backslash' => ['/admin/..\\x'], 'dotdot' => ['/admin/../x'],
            'crlf' => ["/admin/x\r\nSet-Cookie: a=b"], 'lf-in-query' => ["/admin?x=\n"],
            'foreign' => ['/administrator'], 'other' => ['/news'],
            'colon' => ['/admin/a:b'], 'double-slash' => ['/admin//evil'],
            'login' => ['/admin/login'],
        ];
    }

    public function testBasePathRespected(): void
    {
        Config::set(['base_path' => '/sadik']);
        $this->assertSame('/sadik/admin/users', Guard::safeNext('/sadik/admin/users'));
        $this->assertSame('/sadik/admin', Guard::safeNext('/admin/users'));
    }
}
