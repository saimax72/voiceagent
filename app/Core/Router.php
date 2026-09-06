<?php
declare(strict_types=1);

namespace App\Core;

final class Route
{
    public array $middleware = [];
    public ?string $name = null;

    public function __construct(public readonly string $method, public readonly string $pattern, public readonly mixed $handler)
    {
    }

    public function middleware(string ...$names): self
    {
        foreach ($names as $n) {
            $this->middleware[] = $n;
        }
        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }
}

final class Router
{
    /** @var Route[] */
    private array $routes = [];
    private array $groupStack = [];
    /** @var array<string, \Closure> */
    private array $middleware = [];

    public function middleware(string $name, \Closure $handler): void
    {
        $this->middleware[$name] = $handler;
    }

    public function get(string $path, mixed $handler): Route
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, mixed $handler): Route
    {
        return $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->add('DELETE', $path, $handler);
    }

    public function any(string $path, mixed $handler): Route
    {
        return $this->add('*', $path, $handler);
    }

    public function group(array $attributes, \Closure $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(string $method, string $path, mixed $handler): Route
    {
        $prefix = '';
        $middleware = [];
        foreach ($this->groupStack as $group) {
            $prefix .= rtrim($group['prefix'] ?? '', '/');
            foreach ((array) ($group['middleware'] ?? []) as $m) {
                $middleware[] = $m;
            }
        }
        $full = '/' . trim($prefix . '/' . trim($path, '/'), '/');
        $route = new Route($method, $full, $handler);
        $route->middleware = $middleware;
        $this->routes[] = $route;
        return $route;
    }

    private function compile(string $pattern): string
    {
        // Placeholders: {name} or {name:regex}; the regex may contain one level of braces, e.g. {id:[a-f0-9]{32}}
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}/', static function (array $m): string {
            $sub = isset($m[2]) && $m[2] !== '' ? $m[2] : '[^/]+';
            return '(?P<' . $m[1] . '>' . $sub . ')';
        }, $pattern);
        return '#^' . $regex . '$#u';
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->path();
        $method = $request->method();
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($this->compile($route->pattern), $path, $matches)) {
                continue;
            }
            if ($route->method !== '*' && $route->method !== $method) {
                if (!($method === 'HEAD' && $route->method === 'GET')) {
                    $allowed[] = $route->method;
                    continue;
                }
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $this->runRoute($route, $request, $params);
        }

        if ($method === 'OPTIONS') {
            return Response::noContent(204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, X-CSRF-Token, X-Widget-Token',
                'Access-Control-Max-Age' => '86400',
            ]);
        }
        if ($allowed) {
            throw new HttpException(405, 'Method not allowed');
        }
        throw new HttpException(404, 'The page you are looking for could not be found.');
    }

    private function runRoute(Route $route, Request $request, array $params): Response
    {
        $core = function (Request $request) use ($route, $params): Response {
            return $this->normalize($this->invoke($route->handler, $request, $params));
        };

        $pipeline = $core;
        foreach (array_reverse($route->middleware) as $spec) {
            [$name, $args] = array_pad(explode(':', $spec, 2), 2, null);
            $handler = $this->middleware[$name] ?? null;
            if ($handler === null) {
                throw new \RuntimeException("Unknown middleware: {$name}");
            }
            $argList = $args !== null ? explode(',', $args) : [];
            $next = $pipeline;
            $pipeline = static function (Request $request) use ($handler, $next, $argList): Response {
                return $handler($request, $next, ...$argList);
            };
        }

        return $pipeline($request);
    }

    private function invoke(mixed $handler, Request $request, array $params): mixed
    {
        if ($handler instanceof \Closure) {
            return $handler($request, ...array_values($params));
        }
        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = is_string($class) ? new $class() : $class;
            return $instance->{$method}($request, ...array_values($params));
        }
        throw new \RuntimeException('Invalid route handler');
    }

    private function normalize(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result)) {
            return Response::json($result);
        }
        if (is_string($result)) {
            return Response::html($result);
        }
        if ($result === null) {
            return Response::noContent();
        }
        return Response::text((string) $result);
    }
}
