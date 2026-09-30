<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\HttpException;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\Response;

/**
 * Token bucket per client IP and bucket name, persisted in the database so
 * limits hold across PHP-FPM workers and app instances. Used tightly on the
 * auth endpoints (credential stuffing) and loosely on the rest.
 */
final class RateLimit implements Middleware
{
    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @param (callable(): float)|null $clock */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $name,
        private readonly int $capacity,
        private readonly float $refillPerSecond,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
    }

    public function process(Request $request, callable $next): Response
    {
        $key = $this->name . ':' . $request->clientIp;
        $now = ($this->clock)();

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT tokens, updated_at FROM rate_limits WHERE bucket = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            $tokens = is_array($row)
                ? min($this->capacity, (float) $row['tokens'] + ($now - (float) $row['updated_at']) * $this->refillPerSecond)
                : (float) $this->capacity;
            $allowed = $tokens >= 1.0;
            $tokens = $allowed ? $tokens - 1.0 : $tokens;

            $write = is_array($row)
                ? $this->pdo->prepare('UPDATE rate_limits SET tokens = ?, updated_at = ? WHERE bucket = ?')
                : $this->pdo->prepare('INSERT INTO rate_limits (tokens, updated_at, bucket) VALUES (?, ?, ?)');
            $write->execute([$tokens, $now, $key]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        if (!$allowed) {
            $retry = (int) ceil((1.0 - $tokens) / $this->refillPerSecond);
            throw new HttpException(429, 'Too Many Requests', 'Rate limit exceeded, retry later.', [], ['retry-after' => (string) max(1, $retry)]);
        }

        return $next($request)->withHeader('x-ratelimit-remaining', (string) (int) floor($tokens));
    }
}
