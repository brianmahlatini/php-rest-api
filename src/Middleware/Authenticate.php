<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Auth\InvalidToken;
use App\Auth\Jwt;
use App\Http\HttpException;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;

final class Authenticate implements Middleware
{
    public function __construct(private readonly Jwt $jwt) {}

    public function process(Request $request, callable $next): Response
    {
        $header = $request->header('authorization') ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw HttpException::unauthorized();
        }
        try {
            $claims = $this->jwt->verify($m[1]);
        } catch (InvalidToken) {
            throw HttpException::unauthorized('Invalid or expired token.'); // reason stays server-side
        }

        return $next($request->withAttribute('user_id', (int) $claims['sub'])->withAttribute('role', (string) ($claims['role'] ?? 'member')));
    }
}
