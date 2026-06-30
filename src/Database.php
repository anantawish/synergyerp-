<?php

namespace Stock2;

use PDO;

final class Database
{
    private PDO $pdo;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $host = (string)($config['host'] ?? '127.0.0.1');
        $port = (int)($config['port'] ?? 3306);
        $database = (string)($config['database'] ?? 'stock2');
        $charset = (string)($config['charset'] ?? 'utf8mb4');
        $username = (string)($config['username'] ?? 'root');
        $password = (string)($config['password'] ?? '');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);

        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
