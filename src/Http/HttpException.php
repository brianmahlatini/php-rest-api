<?php

declare(strict_types=1);

namespace App\Http;

final class HttpException extends \RuntimeException
{
    /**
     * @param array<string, mixed>  $extra
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        string $detail = '',
        public readonly array $extra = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail);
    }

    public static function badRequest(string $detail): self
    {
        return new self(400, 'Bad Request', $detail);
    }

    /** @param array<string, list<string>> $errors */
    public static function validation(array $errors): self
    {
        return new self(422, 'Validation failed', 'One or more fields are invalid.', ['errors' => $errors]);
    }

    public static function unauthorized(string $detail = 'Authentication required.'): self
    {
        return new self(401, 'Unauthorized', $detail, [], ['www-authenticate' => 'Bearer']);
    }

    public static function forbidden(string $detail = 'You do not have permission for this action.'): self
    {
        return new self(403, 'Forbidden', $detail);
    }

    public static function notFound(string $detail = 'Resource not found.'): self
    {
        return new self(404, 'Not Found', $detail);
    }

    public static function conflict(string $detail): self
    {
        return new self(409, 'Conflict', $detail);
    }
}
