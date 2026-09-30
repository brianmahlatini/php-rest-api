<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\HttpException;

/**
 * Declarative input validation. Collects every error (not just the first)
 * and returns only the declared fields, so unexpected input such as a
 * client-supplied "role" or "owner_id" can never reach the database.
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $clean = [];

    /** @param array<string, mixed> $input */
    private function __construct(private readonly array $input, private readonly bool $partial) {}

    /** @param array<string, mixed> $input */
    public static function make(array $input): self
    {
        return new self($input, false);
    }

    /**
     * For PATCH: fields are optional, but present ones must be valid.
     *
     * @param array<string, mixed> $input
     */
    public static function partial(array $input): self
    {
        return new self($input, true);
    }

    public function string(string $field, int $min = 0, int $max = 255, bool $required = true): self
    {
        return $this->rule($field, $required, function (mixed $v) use ($min, $max): ?string {
            if (!is_string($v)) {
                return 'must be a string';
            }
            $len = mb_strlen(trim($v));

            return $len < $min ? "must be at least {$min} characters" : ($len > $max ? "must be at most {$max} characters" : null);
        }, static fn(mixed $v): string => trim((string) $v));
    }

    public function email(string $field, bool $required = true): self
    {
        return $this->rule($field, $required, static fn(mixed $v): ?string => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false && strlen($v) <= 254 ? null : 'must be a valid email address');
    }

    /** @param list<string> $allowed */
    public function in(string $field, array $allowed, bool $required = true): self
    {
        return $this->rule($field, $required, static fn(mixed $v): ?string => in_array($v, $allowed, true) ? null : 'must be one of: ' . implode(', ', $allowed));
    }

    public function int(string $field, int $min, int $max, bool $required = true): self
    {
        return $this->rule($field, $required, static fn(mixed $v): ?string => is_int($v) && $v >= $min && $v <= $max ? null : "must be an integer between {$min} and {$max}");
    }

    public function date(string $field, bool $required = true): self
    {
        return $this->rule($field, $required, static function (mixed $v): ?string {
            if ($v === null) {
                return null; // explicit null clears the date
            }
            $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;

            return $d !== false && $d->format('Y-m-d') === $v ? null : 'must be a date in YYYY-MM-DD format';
        });
    }

    /** @return array<string, mixed> */
    public function validate(): array
    {
        if ($this->errors !== []) {
            throw HttpException::validation($this->errors);
        }

        return $this->clean;
    }

    /**
     * @param callable(mixed): ?string $check
     * @param (callable(mixed): mixed)|null $normalize
     */
    private function rule(string $field, bool $required, callable $check, ?callable $normalize = null): self
    {
        if (!array_key_exists($field, $this->input)) {
            if ($required && !$this->partial) {
                $this->errors[$field][] = 'is required';
            }

            return $this;
        }
        $value = $this->input[$field];
        $error = $check($value);
        if ($error !== null) {
            $this->errors[$field][] = $error;
        } else {
            $this->clean[$field] = $normalize !== null ? $normalize($value) : $value;
        }

        return $this;
    }
}
