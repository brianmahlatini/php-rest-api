<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;

final class SecurityHeaders implements Middleware
{
    public function process(Request $request, callable $next): Response
    {
        return $next($request)
            ->withHeader('x-content-type-options', 'nosniff')
            ->withHeader('x-frame-options', 'DENY')
            ->withHeader('referrer-policy', 'no-referrer')
            ->withHeader('content-security-policy', "default-src 'none'; frame-ancestors 'none'")
            ->withHeader('cache-control', 'no-store');
    }
}
