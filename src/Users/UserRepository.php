<?php

declare(strict_types=1);

namespace App\Users;

final class UserRepository
{
    public function __construct(private readonly \PDO $pdo) {}

    public function find(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? User::fromRow($row) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        return is_array($row) ? User::fromRow($row) : null;
    }

    public function create(string $email, string $name, string $hash, string $role): User
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare('INSERT INTO users (email, name, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$email, $name, $hash, $role, $now]);

        return new User((int) $this->pdo->lastInsertId(), $email, $name, $hash, $role, $now);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public function setRole(int $id, string $role): void
    {
        $this->pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
    }

    public function countAdmins(): int
    {
        return $this->scalar("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    }

    /** @return array{items: list<User>, total: int} */
    public function paginate(int $page, int $perPage): array
    {
        $total = $this->scalar('SELECT COUNT(*) FROM users');
        $stmt = $this->pdo->prepare('SELECT * FROM users ORDER BY id LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(2, ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => array_values(array_map(User::fromRow(...), $stmt->fetchAll())), 'total' => $total];
    }

    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
