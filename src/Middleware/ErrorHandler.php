<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\HttpException;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;

/** Outermost middleware: every failure becomes RFC 7807 JSON; internals never leak to clients. */
final class ErrorHandler implements Middleware
{
    /** @var \Closure(string): void */
    private readonly \Closure $log;

    /** @param (callable(string): void)|null $log */
    public function __construct(private readonly bool $debug = false, ?callable $log = null)
    {
        $this->log = $log !== null ? \Closure::fromCallable($log) : static function (string $line): void {
            error_log($line);
        };
    }

    public function process(Request $request, callable $next): Response
    {
        $requestId = $request->header('x-request-id') ?? bin2hex(random_bytes(8));
        try {
            $response = $next($request->withAttribute('request_id', $requestId));
        } catch (HttpException $e) {
            $response = Response::problem($e->status, $e->title, $e->getMessage(), $e->extra);
            foreach ($e->headers as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
        } catch (\Throwable $e) {
            ($this->log)(json_encode(['level' => 'error', 'request_id' => $requestId, 'error' => $e::class, 'message' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()], JSON_THROW_ON_ERROR));
            $response = Response::problem(500, 'Internal Server Error', $this->debug ? $e->getMessage() : 'An unexpected error occurred.', ['request_id' => $requestId]);
        }

        return $response->withHeader('x-request-id', $requestId);
    }
}
