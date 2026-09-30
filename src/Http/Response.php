<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {}

    /** @param array<mixed>|\JsonSerializable $data */
    public static function json(array|\JsonSerializable $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), ['content-type' => 'application/json']);
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    /**
     * RFC 7807 problem details: one machine-readable error shape for every failure.
     *
     * @param array<string, mixed> $extra
     */
    public static function problem(int $status, string $title, string $detail = '', array $extra = []): self
    {
        $body = ['type' => 'about:blank', 'title' => $title, 'status' => $status] + ($detail !== '' ? ['detail' => $detail] : []) + $extra;

        return new self($status, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), ['content-type' => 'application/problem+json']);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [strtolower($name) => $value] + $this->headers);
    }

    /** @return array<string, mixed> */
    public function decoded(): array
    {
        /** @var array<string, mixed> */
        return $this->body === '' ? [] : json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
