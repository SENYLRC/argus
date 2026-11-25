<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A light hand-rolled router — no framework, per the handoff README
 * ("a light router" is explicitly the right weight for this app).
 */
final class Router
{
    /** @var array<string,array<int,array{pattern:string,handler:callable}>> */
    private array $routes = ['GET' => [], 'POST' => []];

    /** Register a GET route; {name} segments become named params passed to the handler as an array. */
    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][] = ['pattern' => $this->compile($path), 'handler' => $handler];
    }

    /** Register a POST route (same pattern syntax as get()). */
    public function post(string $path, callable $handler): void
    {
        $this->routes['POST'][] = ['pattern' => $this->compile($path), 'handler' => $handler];
    }

    /** Run the first route matching the method and path (trailing slash ignored); 404 if none does. */
    public function dispatch(string $method, string $uri): void
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = $path === '' ? '/' : rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                ($route['handler'])($params);
                return;
            }
        }

        http_response_code(404);
        echo 'Not found';
    }

    private function compile(string $path): string
    {
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }
}
