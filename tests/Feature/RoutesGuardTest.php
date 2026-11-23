<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Guard;
use App\Core\Router;
use Tests\TestCase;

final class RoutesGuardTest extends TestCase
{
    public function testEveryAdminRouteIsGuardedExceptLogin(): void
    {
        $r = new Router();
        (require ROOT . '/app/routes.php')($r);
        $admin = 0;
        foreach ($r->routes() as $route) {
            $p = $route['pattern'];
            if ($p !== '/admin' && !str_starts_with($p, '/admin/')) {
                continue;
            }
            $admin++;
            $role = Guard::roleOf($route['handler']);
            if ($p === '/admin/login' || $p === '/admin/lang') {
                $this->assertNull($role, "{$route['method']} $p не должен быть под Guard");
                continue;
            }
            $this->assertNotNull($role, "{$route['method']} $p не обёрнут Guard");
            if (str_starts_with($p, '/admin/users')) {
                $this->assertSame('owner', $role, "{$route['method']} $p требует owner");
            }
        }
        $this->assertGreaterThanOrEqual(15, $admin);
    }

    public function testLogoutGetIs405(): void
    {
        $r = new Router();
        (require ROOT . '/app/routes.php')($r);
        try {
            $r->resolve('GET', '/admin/logout');
            $this->fail('405');
        } catch (\App\Core\HttpException $e) {
            $this->assertSame(405, $e->getStatusCode());
            $this->assertSame('POST', $e->getHeaders()['Allow']);
        }
    }
}
