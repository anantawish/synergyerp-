<?php

namespace Stock2;

use PDO;
use RuntimeException;

final class SchemaService
{
    private PDO $pdo;
    private string $dbName;
    private string $cacheFile;
    private int $cacheTtl;

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /** @param array<string, mixed> $config */
    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->dbName = (string)($config['db']['database'] ?? 'stock2');
        $this->cacheTtl = (int)($config['schema_cache_ttl'] ?? 300);
        $this->cacheFile = __DIR__ . '/../storage/cache/schema-cache.json';
    }

    /** @return string[] */
    public function listTables(): array
    {
        $cache = $this->loadCache();
        if (isset($cache['tables']) && is_array($cache['tables'])) {
            $cachedTables = array_values(
                array_filter(
                    array_map(static fn($table): string => trim((string)$table), $cache['tables']),
                    static fn(string $table): bool => $table !== ''
                )
            );
            if (!empty($cachedTables)) {
                return $cachedTables;
            }
        }

        $tables = [];

        $sql = <<<'SQL'
SELECT table_name
FROM information_schema.tables
WHERE table_schema = :schema
  AND table_type = 'BASE TABLE'
ORDER BY table_name
SQL;
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['schema' => $this->dbName]);
            $rows = $stmt->fetchAll();
            if (is_array($rows) && !empty($rows)) {
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $name = '';
                    if (isset($row['table_name'])) {
                        $name = (string)$row['table_name'];
                    } elseif (isset($row['TABLE_NAME'])) {
                        $name = (string)$row['TABLE_NAME'];
                    } else {
                        $first = reset($row);
                        if ($first !== false) {
                            $name = (string)$first;
                        }
                    }

                    $name = trim($name);
                    if ($name !== '') {
                        $tables[] = $name;
                    }
                }
            }
        } catch (\Throwable $e) {
            $tables = [];
        }

        if (empty($tables)) {
            $stmt = $this->pdo->query('SHOW TABLES');
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as $row) {
                if (is_array($row) && isset($row[0])) {
                    $name = trim((string)$row[0]);
                    if ($name !== '') {
                        $tables[] = $name;
                    }
                }
            }
        }

        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);

        $cache['tables'] = $tables;
        $this->writeCache($cache);
        return $tables;
    }

    /** @return array<int, array<string, mixed>> */
    public function listColumns(string $table): array
    {
        $table = $this->normalizeTable($table);
        $cache = $this->loadCache();

        if (isset($cache['columns'][$table]) && is_array($cache['columns'][$table])) {
            return $cache['columns'][$table];
        }

        $sql = <<<'SQL'
SELECT
  column_name,
  data_type,
  column_type,
  is_nullable,
  column_default,
  extra,
  column_key,
  character_maximum_length,
  numeric_precision,
  numeric_scale
FROM information_schema.columns
WHERE table_schema = :schema
  AND table_name = :table
ORDER BY ordinal_position
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'schema' => $this->dbName,
            'table' => $table,
        ]);

        $rows = $stmt->fetchAll();
        $columns = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $normalized = [];
                foreach ($row as $key => $value) {
                    if (!is_string($key)) {
                        continue;
                    }
                    $normalized[strtolower($key)] = $value;
                }
                if (!empty($normalized)) {
                    $columns[] = $normalized;
                }
            }
        }

        if (empty($columns)) {
            throw new RuntimeException("Table not found in schema: {$table}");
        }

        $cache['columns'][$table] = $columns;
        $this->writeCache($cache);

        return $columns;
    }

    public function getPrimaryKey(string $table): string
    {
        $columns = $this->listColumns($table);
        foreach ($columns as $column) {
            if (($column['column_key'] ?? '') === 'PRI') {
                return (string)$column['column_name'];
            }
        }

        return (string)$columns[0]['column_name'];
    }

    /** @return string[] */
    public function getSelectableColumns(string $table): array
    {
        $columns = $this->listColumns($table);
        $names = [];
        foreach ($columns as $column) {
            $type = strtolower((string)$column['data_type']);
            if (in_array($type, ['blob', 'mediumblob', 'longblob', 'binary', 'varbinary'], true)) {
                continue;
            }
            $names[] = (string)$column['column_name'];
        }

        return $names;
    }

    /** @return string[] */
    public function getSearchableColumns(string $table): array
    {
        $columns = $this->listColumns($table);
        $searchable = [];
        foreach ($columns as $column) {
            $type = strtolower((string)$column['data_type']);
            if (in_array($type, [
                'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext',
                'date', 'datetime', 'timestamp', 'time',
                'int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'double', 'float'
            ], true)) {
                $searchable[] = (string)$column['column_name'];
            }
        }

        return $searchable;
    }

    /** @return string[] */
    public function getNumericColumns(string $table): array
    {
        $columns = $this->listColumns($table);
        $numeric = [];
        foreach ($columns as $column) {
            $type = strtolower((string)$column['data_type']);
            if (in_array($type, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'double', 'float'], true)) {
                $numeric[] = (string)$column['column_name'];
            }
        }

        return $numeric;
    }

    public function normalizeTable(string $table): string
    {
        $table = trim($table);
        if ($table === '') {
            throw new RuntimeException('Table is required');
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('Invalid table format');
        }

        if (!in_array($table, $this->listTables(), true)) {
            // Refresh schema cache once in case migration added a new table.
            $this->cache = [];
            if (is_file($this->cacheFile)) {
                @unlink($this->cacheFile);
            }

            if (!in_array($table, $this->listTables(), true)) {
                throw new RuntimeException("Unknown table: {$table}");
            }
        }

        return $table;
    }

    public function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new RuntimeException("Invalid identifier: {$identifier}");
        }

        return '`' . $identifier . '`';
    }

    /** @return array<string, mixed> */
    private function loadCache(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (!is_file($this->cacheFile)) {
            $this->cache = [];
            return $this->cache;
        }

        $raw = file_get_contents($this->cacheFile);
        if ($raw === false || $raw === '') {
            $this->cache = [];
            return $this->cache;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->cache = [];
            return $this->cache;
        }

        $createdAt = (int)($decoded['created_at'] ?? 0);
        if ($createdAt <= 0 || (time() - $createdAt) > $this->cacheTtl) {
            $this->cache = [];
            return $this->cache;
        }

        $this->cache = $decoded;
        return $this->cache;
    }

    /** @param array<string, mixed> $cache */
    private function writeCache(array $cache): void
    {
        $cache['created_at'] = time();
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($this->cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->cache = $cache;
    }
}
