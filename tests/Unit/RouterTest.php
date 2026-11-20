<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Router;
use Tests\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->get('/', fn() => 'home');
        $r->get('/news/{id:\d+}', fn() => 'news');
        $r->get('/p/{slug}', fn() => 'page');
        $r->post('/p/{slug}', fn() => 'post');
        $r->get('/only-get', fn() => 'g');
        return $r;
    }

    public function testStaticAndParams(): void
    {
        $r = $this->router();
        $this->assertSame([], $r->resolve('GET', '/')['params']);
        $this->assertSame(['id' => '15'], $r->resolve('GET', '/news/15')['params']);
        $this->assertSame(['slug' => 'а'], $r->resolve('GET', '/p/%D0%B0')['params']);
    }

    public function testRegexParamMismatchIs404(): void
    {
        $this->expectHttp(404, fn() => $this->router()->resolve('GET', '/news/abc'));
    }

    public function testUnknownPath404(): void
    {
        $this->expectHttp(404, fn() => $this->router()->resolve('GET', '/nope'));
        $this->expectHttp(404, fn() => $this->router()->resolve('GET', "/p/a\0b"));
    }

    public function testMethodNotAllowedHasAllow(): void
    {
        try {
            $this->router()->resolve('POST', '/only-get');
            $this->fail('expected 405');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->getStatusCode());
            $this->assertSame('GET, HEAD', $e->getHeaders()['Allow']);
        }
        try {
            $this->router()->resolve('DELETE', '/p/x');
            $this->fail('expected 405');
        } catch (HttpException $e) {
            $this->assertSame('GET, HEAD, POST', $e->getHeaders()['Allow']);
        }
    }

    public function testHeadActsAsGet(): void
    {
        $this->assertArrayHasKey('handler', $this->router()->resolve('HEAD', '/'));
    }

    public function testTrailingSlash(): void
    {
        $r = $this->router();
        $this->assertSame(['redirect' => '/only-get'], $r->resolve('GET', '/only-get/'));
        $this->assertSame(['redirect' => '/only-get'], $r->resolve('HEAD', '/only-get/'));
        $this->assertArrayHasKey('handler', $r->resolve('POST', '/p/x/'));
    }

    public function testDuplicateRouteThrows(): void
    {
        $r = new Router();
        $r->get('/a', fn() => 1);
        $this->expectException(\LogicException::class);
        $r->get('/a', fn() => 2);
    }

    public function testDispatchIgnoresQueryAndOutputs(): void
    {
        ob_start();
        $this->router()->dispatch('GET', '/news/7?x=1');
        $this->assertSame('news', ob_get_clean());
        ob_start();
        $this->router()->dispatch('HEAD', '/');
        $this->assertSame('', ob_get_clean());
    }

    private function expectHttp(int $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("expected $code");
        } catch (HttpException $e) {
            $this->assertSame($code, $e->getStatusCode());
        }
    }
}
