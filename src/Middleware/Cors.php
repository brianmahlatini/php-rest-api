<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;

/** Explicit origin allow-list (never "*" with credentials); answers preflights directly. */
final class Cors implements Middleware
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins) {}

    public function process(Request $request, callable $next): Response
    {
        $origin = $request->header('origin');
        $allowed = $origin !== null && in_array($origin, $this->allowedOrigins, true);

        if ($request->method === 'OPTIONS' && $request->header('access-control-request-method') !== null) {
            $response = new Response(204);
            if ($allowed) {
                $response = $response
                    ->withHeader('access-control-allow-methods', 'GET, POST, PATCH, DELETE, OPTIONS')
                    ->withHeader('access-control-allow-headers', 'Authorization, Content-Type, X-Request-Id')
                    ->withHeader('access-control-max-age', '600');
            }
        } else {
            $response = $next($request);
        }
        if ($allowed) {
            $response = $response->withHeader('access-control-allow-origin', $origin)->withHeader('vary', 'Origin');
        }

        return $response;
    }
}
