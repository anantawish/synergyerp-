<?php

$config = require __DIR__ . '/../config/app.php';

$host = $config['db']['host'] ?? '127.0.0.1';
$port = (int)($config['db']['port'] ?? 3306);
$dbName = $config['db']['database'] ?? 'stock2';
$user = $config['db']['username'] ?? 'root';
$pass = $config['db']['password'] ?? '';
$charset = $config['db']['charset'] ?? 'utf8mb4';

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbName, $charset);
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

echo "Running optimizer for db={$dbName}\n";

$sqlCandidates = <<<'SQL'
SELECT c.table_name, c.column_name, c.data_type
FROM information_schema.columns c
JOIN information_schema.tables t
  ON t.table_schema = c.table_schema
 AND t.table_name = c.table_name
WHERE c.table_schema = :db
  AND t.table_type = 'BASE TABLE'
  AND c.data_type NOT IN ('text','tinytext','mediumtext','longtext','blob','tinyblob','mediumblob','longblob','json')
  AND (
    c.column_name LIKE '%\\_id' ESCAPE '\\'
    OR c.column_name LIKE '%date%'
    OR c.column_name IN ('username','created_at','updated_at','bill_id','prd_id','cust_id','supy_id')
  )
ORDER BY c.table_name, c.column_name
SQL;

$stmt = $pdo->prepare($sqlCandidates);
$stmt->execute(['db' => $dbName]);
$candidates = $stmt->fetchAll();

$created = 0;
$skipped = 0;
$failed = 0;

foreach ($candidates as $col) {
    $table = $col['table_name'];
    $column = $col['column_name'];

    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = :db AND table_name = :table AND column_name = :column'
    );
    $check->execute([
        'db' => $dbName,
        'table' => $table,
        'column' => $column,
    ]);

    if ((int)$check->fetchColumn() > 0) {
        $skipped++;
        continue;
    }

    $indexName = 'idx_' . $table . '_' . $column;
    if (strlen($indexName) > 62) {
        $indexName = substr($indexName, 0, 54) . '_' . substr(md5($indexName), 0, 7);
    }

    $sql = sprintf('ALTER TABLE `%s` ADD INDEX `%s` (`%s`)', $table, $indexName, $column);

    try {
        $pdo->exec($sql);
        $created++;
        echo "[ADD] {$table}.{$column} -> {$indexName}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "[FAIL] {$table}.{$column} :: {$e->getMessage()}\n";
    }
}

echo "\nDone\n";
echo "Created: {$created}\n";
echo "Skipped: {$skipped}\n";
echo "Failed : {$failed}\n";

