<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\InvalidToken;
use App\Auth\Jwt;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    public function testRoundTrip(): void
    {
        $jwt = new Jwt(self::SECRET);
        $claims = $jwt->verify($jwt->issue(['sub' => '42', 'role' => 'admin']));
        self::assertSame('42', $claims['sub']);
        self::assertSame('admin', $claims['role']);
    }

    public function testRejectsTamperedPayload(): void
    {
        $jwt = new Jwt(self::SECRET);
        [$h, , $s] = explode('.', $jwt->issue(['sub' => '1', 'role' => 'member']));
        $forged = rtrim(strtr(base64_encode((string) json_encode(['sub' => '1', 'role' => 'admin', 'iss' => 'php-rest-api', 'exp' => time() + 999])), '+/', '-_'), '=');
        $this->expectException(InvalidToken::class);
        $jwt->verify("{$h}.{$forged}.{$s}");
    }

    public function testRejectsAlgNone(): void
    {
        $jwt = new Jwt(self::SECRET);
        $enc = static fn(array $a): string => rtrim(strtr(base64_encode((string) json_encode($a)), '+/', '-_'), '=');
        $token = $enc(['alg' => 'none', 'typ' => 'JWT']) . '.' . $enc(['sub' => '1', 'iss' => 'php-rest-api', 'exp' => time() + 60]) . '.';
        $this->expectException(InvalidToken::class);
        $jwt->verify($token);
    }

    public function testRejectsTokenSignedWithAnotherSecret(): void
    {
        $other = new Jwt(str_repeat('x', 32));
        $this->expectException(InvalidToken::class);
        (new Jwt(self::SECRET))->verify($other->issue(['sub' => '1']));
    }

    public function testExpiryHonoursLeeway(): void
    {
        $jwt = new Jwt(self::SECRET, ttlSeconds: 60, leewaySeconds: 30);
        $token = $jwt->issue(['sub' => '1'], now: 1_000_000);
        self::assertSame('1', $jwt->verify($token, now: 1_000_080)['sub']); // 20s past exp, inside leeway
        $this->expectException(InvalidToken::class);
        $jwt->verify($token, now: 1_000_100);
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(InvalidToken::class);
        (new Jwt(self::SECRET))->verify('not.a.jwt');
    }

    public function testRejectsShortSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Jwt('short');
    }
}
