<?php

declare(strict_types=1);

namespace App\Http;

/** Runs global middleware, then route middleware, then the handler (onion model). */
final class Kernel
{
    /** @param list<Middleware> $global outermost first */
    public function __construct(private readonly Router $router, private readonly array $global = []) {}

    public function handle(Request $request): Response
    {
        $dispatch = function (Request $req): Response {
            [$handler, $routed, $routeMiddleware] = $this->router->match($req);

            return $this->pipeline($routeMiddleware, $handler)($routed);
        };

        return $this->pipeline($this->global, $dispatch)($request);
    }

    /**
     * @param list<Middleware>            $stack
     * @param callable(Request): Response $core
     * @return callable(Request): Response
     */
    private function pipeline(array $stack, callable $core): callable
    {
        return array_reduce(
            array_reverse($stack),
            static fn(callable $next, Middleware $m): callable => static fn(Request $r): Response => $m->process($r, $next),
            $core,
        );
    }
}
