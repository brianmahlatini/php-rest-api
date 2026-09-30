<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\HttpException;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;

final class RequireRole implements Middleware
{
    public function __construct(private readonly string $role) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->attribute('role') !== $this->role) {
            throw HttpException::forbidden();
        }

        return $next($request);
    }
}
