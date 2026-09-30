<?php

declare(strict_types=1);

namespace App\Http;

/** Immutable HTTP request. Route params and the authenticated user are attached by the kernel/middleware. */
final class Request
{
    /**
     * @param array<string, string>   $query
     * @param array<string, string>   $headers lower-cased names
     * @param array<string, string>   $params  route parameters
     * @param array<string, mixed>    $attributes
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly array $params = [],
        public readonly array $attributes = [],
        public readonly string $clientIp = '127.0.0.1',
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        /** @var array<string, string> $query */
        $query = array_filter($_GET, 'is_string');

        return new self(
            method: strtoupper(is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET'),
            path: is_string($path) ? $path : '/',
            query: $query,
            headers: $headers,
            body: (string) file_get_contents('php://input'),
            clientIp: is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0',
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw HttpException::badRequest('Request body is not valid JSON.');
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw HttpException::badRequest('Request body must be a JSON object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @param array<string, string> $params */
    public function withParams(array $params): self
    {
        return new self($this->method, $this->path, $this->query, $this->headers, $this->body, $params, $this->attributes, $this->clientIp);
    }

    public function withAttribute(string $key, mixed $value): self
    {
        return new self($this->method, $this->path, $this->query, $this->headers, $this->body, $this->params, [$key => $value] + $this->attributes, $this->clientIp);
    }

    public function attribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}
