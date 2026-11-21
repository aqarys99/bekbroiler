<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string,array<int,array{pattern:string,regex:string,handler:mixed}>> */
    private array $routes = [];

    public function get(string $pattern, mixed $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    /** @return array<int,array{method:string,pattern:string,handler:mixed}> все маршруты (для тестов охраны) */
    public function routes(): array
    {
        $out = [];
        foreach ($this->routes as $method => $list) {
            foreach ($list as $r) {
                $out[] = ['method' => $method, 'pattern' => $r['pattern'], 'handler' => $r['handler']];
            }
        }
        return $out;
    }

    private function add(string $method, string $pattern, mixed $handler): void
    {
        foreach ($this->routes[$method] ?? [] as $r) {
            if ($r['pattern'] === $pattern) {
                throw new \LogicException("Маршрут уже зарегистрирован: $method $pattern");
            }
        }
        $this->routes[$method][] = ['pattern' => $pattern, 'regex' => self::compile($pattern), 'handler' => $handler];
    }

    private static function compile(string $pattern): string
    {
        $regex = '';
        $pos = 0;
        preg_match_all('/\{(\w+)(?::([^}]+))?\}/', $pattern, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($m as $x) {
            $regex .= preg_quote(substr($pattern, $pos, $x[0][1] - $pos), '~');
            $regex .= '(?P<' . $x[1][0] . '>' . (isset($x[2]) ? $x[2][0] : '[^/]+') . ')';
            $pos = $x[0][1] + strlen($x[0][0]);
        }
        $regex .= preg_quote(substr($pattern, $pos), '~');
        return '~^' . $regex . '$~';
    }

    /**
     * @return array{handler:mixed,params:array<string,string>}|array{redirect:string}
     * @throws HttpException 404 / 405
     */
    public function resolve(string $method, string $path): array
    {
        $method = strtoupper($method);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        if (str_contains($path, "\0")) {
            throw new HttpException(404);
        }
        $isRead = $method === 'GET' || $method === 'HEAD';
        $slash = $path !== '/' && str_ends_with($path, '/');
        $lookup = $slash ? ($this->trimSlash($path)) : $path;

        $found = $this->match($isRead ? 'GET' : $method, $lookup);
        if ($found !== null) {
            if ($slash && $isRead) {
                return ['redirect' => $lookup];
            }
            return $found;
        }

        $allowed = [];
        foreach ($this->routes as $m => $list) {
            foreach ($list as $r) {
                if (preg_match($r['regex'], $lookup) === 1) {
                    $allowed[] = $m;
                    break;
                }
            }
        }
        if ($allowed) {
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            $order = ['GET' => 0, 'HEAD' => 1, 'POST' => 2];
            usort($allowed, static fn($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
            throw new HttpException(405, '', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404);
    }

    private function trimSlash(string $path): string
    {
        $p = rtrim($path, '/');
        return $p === '' ? '/' : $p;
    }

    private function match(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as $r) {
            if (preg_match($r['regex'], $path, $mm) === 1) {
                $params = [];
                foreach ($mm as $k => $v) {
                    if (is_string($k)) {
                        $params[$k] = rawurldecode($v);
                    }
                }
                return ['handler' => $r['handler'], 'params' => $params];
            }
        }
        return null;
    }

    /** Разобрать REQUEST_URI и выполнить маршрут. */
    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        $path = (string)parse_url($uri, PHP_URL_PATH);
        $query = (string)parse_url($uri, PHP_URL_QUERY);
        $base = rtrim((string)config('base_path', ''), '/');
        if ($base !== '') {
            if ($path === $base || strncmp($path, $base . '/', strlen($base) + 1) === 0) {
                $path = substr($path, strlen($base)) ?: '/';
            } else {
                throw new HttpException(404);
            }
        }

        $res = $this->resolve($method, $path);
        if (isset($res['redirect'])) {
            redirect(url($res['redirect']) . ($query !== '' ? '?' . $query : ''), 301);
        }

        $isHead = $method === 'HEAD';
        if ($isHead) {
            ob_start();
        }
        $handler = $res['handler'];
        if ($handler instanceof \Closure) {
            $out = $handler($res['params']);
        } else {
            [$class, $action] = $handler;
            $out = (new $class())->$action($res['params']);
        }
        if (is_string($out)) {
            echo $out;
        }
        if ($isHead) {
            ob_end_clean();
        }
    }
}
