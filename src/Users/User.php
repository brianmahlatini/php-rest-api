<?php

declare(strict_types=1);

namespace App\Users;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $name,
        public readonly string $passwordHash,
        public readonly string $role,
        public readonly string $createdAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self((int) $row['id'], (string) $row['email'], (string) $row['name'], (string) $row['password_hash'], (string) $row['role'], (string) $row['created_at']);
    }

    /**
     * Public representation: never includes the password hash.
     *
     * @return array<string, int|string>
     */
    public function toPublic(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'name' => $this->name, 'role' => $this->role, 'created_at' => $this->createdAt];
    }
}
