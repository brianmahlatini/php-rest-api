<?php

declare(strict_types=1);

namespace App\Database;

/**
 * Forward-only SQL migrations, one file per version, applied in order inside
 * a transaction each and recorded in schema_migrations. Driver-specific
 * directories keep DDL honest instead of lowest-common-denominator SQL.
 */
final class Migrator
{
    public function __construct(private readonly \PDO $pdo, private readonly string $baseDir) {}

    /** @return list<string> versions applied in this run */
    public function migrate(): array
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(64) PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)');
        $stmt = $this->pdo->prepare('SELECT version FROM schema_migrations');
        $stmt->execute();
        $done = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $files = glob($this->baseDir . '/' . $driver . '/*.sql') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $done, true)) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            $this->pdo->beginTransaction();
            try {
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                    $this->pdo->exec($statement);
                }
                $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
                $stmt->execute([$version, gmdate('c')]);
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new \RuntimeException("Migration {$version} failed: {$e->getMessage()}", 0, $e);
            }
            $applied[] = $version;
        }

        return $applied;
    }
}
