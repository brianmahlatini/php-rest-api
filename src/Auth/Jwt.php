<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * HS256 JSON Web Tokens (RFC 7519) with the checks that matter:
 * the algorithm is fixed server-side (no "alg: none" / algorithm confusion),
 * signatures are compared in constant time, and exp/nbf are enforced with
 * a small clock-skew leeway.
 */
final class Jwt
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds = 3600,
        private readonly string $issuer = 'php-rest-api',
        private readonly int $leewaySeconds = 30,
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT secret must be at least 32 bytes.');
        }
    }

    /** @param array<string, scalar> $claims */
    public function issue(array $claims, ?int $now = null): string
    {
        $now ??= time();
        $payload = $claims + ['iss' => $this->issuer, 'iat' => $now, 'nbf' => $now, 'exp' => $now + $this->ttlSeconds];
        $segments = [self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)), self::b64(json_encode($payload, JSON_THROW_ON_ERROR))];
        $segments[] = self::b64(hash_hmac('sha256', implode('.', $segments), $this->secret, true));

        return implode('.', $segments);
    }

    /**
     * @return array<string, mixed> verified claims
     *
     * @throws InvalidToken
     */
    public function verify(string $token, ?int $now = null): array
    {
        $now ??= time();
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidToken('malformed token');
        }
        [$h, $p, $s] = $parts;
        $header = self::decodeJson($h);
        if (($header['alg'] ?? null) !== 'HS256') {
            throw new InvalidToken('unsupported algorithm');
        }
        $expected = self::b64(hash_hmac('sha256', "{$h}.{$p}", $this->secret, true));
        if (!hash_equals($expected, $s)) {
            throw new InvalidToken('bad signature');
        }
        $claims = self::decodeJson($p);
        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new InvalidToken('wrong issuer');
        }
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] + $this->leewaySeconds < $now) {
            throw new InvalidToken('token expired');
        }
        if (is_int($claims['nbf'] ?? null) && $claims['nbf'] - $this->leewaySeconds > $now) {
            throw new InvalidToken('token not yet valid');
        }

        return $claims;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> */
    private static function decodeJson(string $segment): array
    {
        $raw = base64_decode(strtr($segment, '-_', '+/'), true);
        if ($raw === false) {
            throw new InvalidToken('bad encoding');
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidToken('bad json');
        }
        if (!is_array($data)) {
            throw new InvalidToken('bad json');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
