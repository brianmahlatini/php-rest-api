<?php

declare(strict_types=1);

namespace App\Tasks;

final class TaskRepository
{
    private const COLUMNS = ['title', 'description', 'status', 'priority', 'due_date']; // updatable whitelist

    public function __construct(private readonly \PDO $pdo) {}

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? self::cast($row) : null;
    }

    /**
     * @param array{owner_id?: int, status?: string, q?: string} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $page, int $perPage): array
    {
        $where = [];
        $args = [];
        if (isset($filters['owner_id'])) {
            $where[] = 'owner_id = ?';
            $args[] = $filters['owner_id'];
        }
        if (isset($filters['status'])) {
            $where[] = 'status = ?';
            $args[] = $filters['status'];
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $where[] = 'title LIKE ?';
            $args[] = '%' . addcslashes($filters['q'], '%_\\') . '%';
        }
        $sqlWhere = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM tasks' . $sqlWhere);
        $count->execute($args);

        $stmt = $this->pdo->prepare('SELECT * FROM tasks' . $sqlWhere . ' ORDER BY priority ASC, id DESC LIMIT ? OFFSET ?');
        foreach ($args as $i => $arg) {
            $stmt->bindValue($i + 1, $arg, is_int($arg) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $stmt->bindValue(count($args) + 1, $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(count($args) + 2, ($page - 1) * $perPage, \PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => array_values(array_map(self::cast(...), $stmt->fetchAll())), 'total' => (int) $count->fetchColumn()];
    }

    /** @param array<string, mixed> $data */
    public function create(int $ownerId, array $data): int
    {
        $now = gmdate('c');
        $stmt = $this->pdo->prepare('INSERT INTO tasks (owner_id, title, description, status, priority, due_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$ownerId, $data['title'], $data['description'] ?? '', $data['status'] ?? 'todo', $data['priority'] ?? 3, $data['due_date'] ?? null, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        $fields = array_intersect_key($data, array_flip(self::COLUMNS));
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn(string $c): string => "{$c} = ?", array_keys($fields)));
        $stmt = $this->pdo->prepare("UPDATE tasks SET {$set}, updated_at = ? WHERE id = ?");
        $stmt->execute([...array_values($fields), gmdate('c'), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function cast(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'owner_id' => (int) $row['owner_id'],
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'status' => (string) $row['status'],
            'priority' => (int) $row['priority'],
            'due_date' => $row['due_date'] === null ? null : (string) $row['due_date'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
