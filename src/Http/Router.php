<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Minimal router: METHOD + path template ("/api/tasks/{id}") -> handler.
 * Templates compile to anchored regexes once at registration. Unknown
 * paths give 404; known paths with the wrong method give 405 + Allow.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, names: list<string>, handler: callable(Request): Response, middleware: list<Middleware>}> */
    private array $routes = [];

    /**
     * @param callable(Request): Response $handler
     * @param list<Middleware>            $middleware route-specific middleware (auth, roles)
     */
    public function add(string $method, string $template, callable $handler, array $middleware = []): void
    {
        preg_match_all('/\{([a-z_]+)\}/', $template, $m);
        $regex = '#^' . preg_replace('/\{[a-z_]+\}/', '([^/]+)', $template) . '$#';
        $this->routes[] = ['method' => strtoupper($method), 'regex' => $regex, 'names' => $m[1], 'handler' => $handler, 'middleware' => $middleware];
    }

    /** @return array{0: callable(Request): Response, 1: Request, 2: list<Middleware>} */
    public function match(Request $request): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $values) !== 1) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = array_combine($route['names'], array_map('rawurldecode', array_slice($values, 1)));

            return [$route['handler'], $request->withParams($params), $route['middleware']];
        }
        if ($allowed !== []) {
            throw new HttpException(405, 'Method Not Allowed', '', [], ['allow' => implode(', ', array_unique($allowed))]);
        }
        throw HttpException::notFound("No route for {$request->method} {$request->path}.");
    }
}
