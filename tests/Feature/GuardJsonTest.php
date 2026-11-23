<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Guard;
use App\Core\HttpException;
use App\Core\RedirectException;
use Tests\AuthTestCase;

final class GuardJsonTest extends AuthTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['CONTENT_LENGTH']);
        parent::tearDown();
    }

    private function call(string $role = 'editor'): array
    {
        $h = Guard::wrap(static fn() => 'handled', $role);
        $out = $h([]);
        $j = json_decode($out, true);
        return is_array($j) ? $j : ['raw' => $out];
    }

    public function testSessionExpiredIs401Json(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
        $r = $this->call();
        $this->assertFalse($r['ok']);
        $this->assertSame('session', $r['code']);
        $this->assertNotSame('', $r['message']);
    }

    public function testAcceptJsonAlsoSwitchesMode(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json, text/plain';
        $this->assertSame('session', $this->call()['code']);
    }

    public function testPlainFormsStillRedirectAndThrow(): void
    {
        $this->post([]);
        $this->expectException(RedirectException::class);
        $this->call();
    }

    public function testBadCsrfIs400Json(): void
    {
        $this->loginAs('editor');
        $this->post(['_csrf' => 'wrong']);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
        $r = $this->call();
        $this->assertSame('csrf', $r['code']);
    }

    public function testCsrfFromHeaderAccepted(): void
    {
        $this->loginAs('editor');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token();
        $this->assertSame(['raw' => 'handled'], $this->call());
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'nope';
        $this->assertSame('csrf', $this->call()['code']);
    }

    public function testHeaderCsrfIgnoredForPlainRequests(): void
    {
        $this->loginAs('editor');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = csrf_token();
        $this->expectException(HttpException::class);
        $this->call();
    }

    public function testForbiddenIs403Json(): void
    {
        $this->loginAs('editor');
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
        $this->assertSame('forbidden', $this->call('owner')['code']);
        $_SERVER['HTTP_X_REQUESTED_WITH'] = '';
        $this->expectException(HttpException::class);
        $this->call('owner');
    }

    public function testOversizedBodyIs413BeforeCsrf(): void
    {
        $this->loginAs('editor');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = []; // PHP отдаёт пустой $_POST при превышении post_max_size
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
        $_SERVER['CONTENT_LENGTH'] = (string)(Guard::iniBytes((string)ini_get('post_max_size')) + 1);
        $r = $this->call();
        $this->assertSame('too_large', $r['code']);
    }

    public function testIniBytes(): void
    {
        $this->assertSame(8388608, Guard::iniBytes('8M'));
        $this->assertSame(1024, Guard::iniBytes('1k'));
        $this->assertSame(2147483648, Guard::iniBytes('2G'));
        $this->assertSame(-1, Guard::iniBytes('-1'));
        $this->assertSame(0, Guard::iniBytes(''));
    }
}
