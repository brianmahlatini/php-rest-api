<?php

declare(strict_types=1);

namespace App\Database;

final class ConnectionFactory
{
    public static function create(string $dsn, ?string $user = null, ?string $password = null): \PDO
    {
        $pdo = new \PDO($dsn, $user, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false, // real server-side prepared statements
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        return $pdo;
    }
}
