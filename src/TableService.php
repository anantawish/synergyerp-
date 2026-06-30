<?php

namespace Stock2;

use PDO;
use RuntimeException;
use Throwable;

final class TableService
{
    private PDO $pdo;
    private SchemaService $schema;
    private ?bool $softDeleteReady = null;
    /** @var array<string, bool> */
    private array $tableExistsCache = [];

    public function __construct(PDO $pdo, SchemaService $schema)
    {
        $this->pdo = $pdo;
        $this->schema = $schema;
    }

    /** @param array<string, mixed> $request
      * @return array<string, mixed>
      */
    public function fetchForDataTable(string $table, array $request): array
    {
        $table = $this->schema->normalizeTable($table);
        $allColumns = $this->schema->getSelectableColumns($table);
        $pk = $this->schema->getPrimaryKey($table);

        if (empty($allColumns)) {
            throw new RuntimeException('No selectable columns for table');
        }

        $columns = $this->resolveSelectColumns($allColumns, $request);

        $draw = (int)($request['draw'] ?? 1);
        $start = max(0, (int)($request['start'] ?? 0));
        $length = (int)($request['length'] ?? 25);
        if ($length <= 0 || $length > 200) {
            $length = 25;
        }

        $searchValue = '';
        if (isset($request['search']) && is_array($request['search'])) {
            $searchValue = trim((string)($request['search']['value'] ?? ''));
        } elseif (isset($request['search[value]'])) {
            $searchValue = trim((string)$request['search[value]']);
        }

        $quotedTable = $this->schema->quoteIdentifier($table);
        $quotedColumns = array_map(fn(string $col): string => $this->schema->quoteIdentifier($col), $columns);

        $whereParts = [];
        $whereParams = [];

        $soft = $this->softDeleteFilter($table, $pk, ':sd_table_filter');
        if ($soft['sql'] !== '') {
            $whereParts[] = $soft['sql'];
            $whereParams = array_merge($whereParams, $soft['params']);
        }

        $fixedFilters = $this->resolveFixedFilters($table, $request);
        $filterIndex = 0;
        foreach ($fixedFilters as $column => $value) {
            $param = ':filter' . $filterIndex;
            $whereParts[] = $this->schema->quoteIdentifier($column) . ' = ' . $param;
            $whereParams[$param] = $value;
            $filterIndex++;
        }

        if ($searchValue !== '') {
            $searchable = array_slice($this->schema->getSearchableColumns($table), 0, 12);
            if (!empty($searchable)) {
                $parts = [];
                foreach ($searchable as $idx => $col) {
                    $param = ':search' . $idx;
                    $parts[] = $this->schema->quoteIdentifier($col) . ' LIKE ' . $param;
                    $whereParams[$param] = '%' . $searchValue . '%';
                }
                $whereParts[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $whereSql = '';
        if (!empty($whereParts)) {
            $whereSql = ' WHERE ' . implode(' AND ', $whereParts);
        }

        $orderSql = ' ORDER BY ' . $this->schema->quoteIdentifier($pk) . ' DESC';
        $orderColumnIndex = (int)($request['order'][0]['column'] ?? 0);
        $orderDirection = strtolower((string)($request['order'][0]['dir'] ?? 'desc'));
        if ($orderDirection !== 'asc' && $orderDirection !== 'desc') {
            $orderDirection = 'desc';
        }

        if (isset($columns[$orderColumnIndex])) {
            $orderSql = ' ORDER BY ' . $this->schema->quoteIdentifier($columns[$orderColumnIndex]) . ' ' . strtoupper($orderDirection);
        }

        $totalSql = 'SELECT COUNT(*) FROM ' . $quotedTable;
        $totalParams = [];
        $softTotal = $this->softDeleteFilter($table, $pk, ':sd_table_total');
        if ($softTotal['sql'] !== '') {
            $totalSql .= ' WHERE ' . $softTotal['sql'];
            $totalParams = $softTotal['params'];
        }
        $totalStmt = $this->pdo->prepare($totalSql);
        foreach ($totalParams as $k => $v) {
            $totalStmt->bindValue($k, $v);
        }
        $totalStmt->execute();
        $total = (int)$totalStmt->fetchColumn();

        $filtered = $total;
        if ($whereSql !== '') {
            $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $quotedTable . $whereSql);
            foreach ($whereParams as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $filtered = (int)$countStmt->fetchColumn();
        }

        $sql = 'SELECT ' . implode(', ', $quotedColumns) . ' FROM ' . $quotedTable . $whereSql . $orderSql . ' LIMIT :start, :length';
        $stmt = $this->pdo->prepare($sql);
        foreach ($whereParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':length', $length, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();

        return [
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $filtered,
            'data' => $rows,
            'columns' => $columns,
            'primaryKey' => $pk,
        ];
    }

    /** @return array<string, mixed> */
    public function fetchRow(string $table, string $id): array
    {
        $table = $this->schema->normalizeTable($table);
        $pk = $this->schema->getPrimaryKey($table);

        $sql = 'SELECT * FROM ' . $this->schema->quoteIdentifier($table)
            . ' WHERE ' . $this->schema->quoteIdentifier($pk) . ' = :id';
        $params = ['id' => $id];

        $soft = $this->softDeleteFilter($table, $pk, ':sd_table_row');
        if ($soft['sql'] !== '') {
            $sql .= ' AND ' . $soft['sql'];
            $params = array_merge($params, $soft['params']);
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('Record not found');
        }

        return $row;
    }

    /** @param array<string, mixed> $payload
      * @return array<string, mixed>
      */
    public function saveRow(string $table, array $payload): array
    {
        $table = $this->schema->normalizeTable($table);
        $columns = $this->schema->listColumns($table);
        $pk = $this->schema->getPrimaryKey($table);

        $columnMap = [];
        foreach ($columns as $column) {
            $columnMap[(string)$column['column_name']] = $column;
        }

        $pkValue = isset($payload[$pk]) ? trim((string)$payload[$pk]) : '';
        $isUpdate = $pkValue !== '';

        if ($isUpdate) {
            $setParts = [];
            $params = ['pk' => $pkValue];
            $normalizedUpdate = [];

            foreach ($columnMap as $name => $meta) {
                if ($name === $pk) {
                    continue;
                }
                if (!array_key_exists($name, $payload)) {
                    continue;
                }

                $setParts[] = $this->schema->quoteIdentifier($name) . ' = :' . $name;
                $normalized = $this->normalizeValue($payload[$name], $meta, $name);
                $params[$name] = $normalized;
                $normalizedUpdate[$name] = $normalized;
            }

            if (empty($setParts)) {
                return [
                    'mode' => 'update',
                    'id' => $pkValue,
                    'affected' => 0,
                ];
            }

            $this->validateInventoryLocationPayload($table, $columnMap, $normalizedUpdate, true, $pk, $pkValue);

            $sql = 'UPDATE ' . $this->schema->quoteIdentifier($table)
                . ' SET ' . implode(', ', $setParts)
                . ' WHERE ' . $this->schema->quoteIdentifier($pk) . ' = :pk';

            $soft = $this->softDeleteFilter($table, $pk, ':sd_table_update');
            if ($soft['sql'] !== '') {
                $sql .= ' AND ' . $soft['sql'];
                $params = array_merge($params, $soft['params']);
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return [
                'mode' => 'update',
                'id' => $pkValue,
                'affected' => $stmt->rowCount(),
            ];
        }

        $insertColumns = [];
        $insertParams = [];

        foreach ($columnMap as $name => $meta) {
            $extra = strtolower((string)($meta['extra'] ?? ''));
            if ($name === $pk && str_contains($extra, 'auto_increment')) {
                continue;
            }

            if (!array_key_exists($name, $payload)) {
                continue;
            }

            $insertColumns[] = $name;
            $insertParams[$name] = $this->normalizeValue($payload[$name], $meta, $name);
        }

        $this->validateInventoryLocationPayload($table, $columnMap, $insertParams, false, $pk, '');

        foreach ($columnMap as $name => $meta) {
            $extra = strtolower((string)($meta['extra'] ?? ''));
            $nullable = strtoupper((string)($meta['is_nullable'] ?? 'YES')) === 'YES';
            $default = $meta['column_default'] ?? null;
            $dataType = strtolower((string)($meta['data_type'] ?? ''));

            if ($name === $pk && str_contains($extra, 'auto_increment')) {
                continue;
            }
            if (array_key_exists($name, $insertParams)) {
                continue;
            }
            if ($nullable) {
                continue;
            }
            if ($default !== null) {
                continue;
            }
            if (str_contains($extra, 'on update')) {
                continue;
            }
            if ($dataType === 'timestamp' && $default === null) {
                continue;
            }

            throw new RuntimeException('field required: ' . $name);
        }

        if (empty($insertColumns)) {
            throw new RuntimeException('No data to insert');
        }

        $quotedColumns = array_map(fn(string $name): string => $this->schema->quoteIdentifier($name), $insertColumns);
        $paramNames = array_map(fn(string $name): string => ':' . $name, $insertColumns);

        $sql = 'INSERT INTO ' . $this->schema->quoteIdentifier($table)
            . ' (' . implode(', ', $quotedColumns) . ')'
            . ' VALUES (' . implode(', ', $paramNames) . ')';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($insertParams);

        $newId = $this->pdo->lastInsertId();
        if ($newId === '0' || $newId === '') {
            $newId = isset($payload[$pk]) ? (string)$payload[$pk] : '';
        }

        return [
            'mode' => 'insert',
            'id' => $newId,
            'affected' => $stmt->rowCount(),
        ];
    }

    public function deleteRow(string $table, string $id, ?string $deletedBy = null, ?string $reason = null): int
    {
        $table = $this->schema->normalizeTable($table);
        $pk = $this->schema->getPrimaryKey($table);

        if (!$this->isSoftDeleteEnabled($table)) {
            $sql = 'DELETE FROM ' . $this->schema->quoteIdentifier($table)
                . ' WHERE ' . $this->schema->quoteIdentifier($pk) . ' = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $id]);
            return $stmt->rowCount();
        }

        $rowSql = 'SELECT * FROM ' . $this->schema->quoteIdentifier($table)
            . ' WHERE ' . $this->schema->quoteIdentifier($pk) . ' = :id LIMIT 1';
        $rowStmt = $this->pdo->prepare($rowSql);
        $rowStmt->execute(['id' => $id]);
        $row = $rowStmt->fetch();

        if (!is_array($row)) {
            return 0;
        }

        $rowData = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($rowData === false) {
            $rowData = null;
        }

        $sql = <<<'SQL'
INSERT INTO app_soft_delete (table_name, pk_column, pk_value, row_data, deleted_by, delete_reason, deleted_at)
VALUES (:table_name, :pk_column, :pk_value, :row_data, :deleted_by, :delete_reason, NOW())
ON DUPLICATE KEY UPDATE
    pk_column = VALUES(pk_column),
    row_data = VALUES(row_data),
    deleted_by = VALUES(deleted_by),
    delete_reason = VALUES(delete_reason),
    deleted_at = VALUES(deleted_at)
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'table_name' => $table,
            'pk_column' => $pk,
            'pk_value' => (string)$id,
            'row_data' => $rowData,
            'deleted_by' => $deletedBy !== null && trim($deletedBy) !== '' ? trim($deletedBy) : null,
            'delete_reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
        ]);

        return 1;
    }

    public function restoreRow(string $table, string $id): int
    {
        $table = $this->schema->normalizeTable($table);
        if (!$this->isSoftDeleteEnabled($table)) {
            return 0;
        }

        $sql = 'DELETE FROM app_soft_delete WHERE table_name = :table_name AND pk_value = :pk_value';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'table_name' => $table,
            'pk_value' => (string)$id,
        ]);

        return $stmt->rowCount();
    }

    /** @return array<int, array<string, mixed>> */
    public function deletedRows(string $table, int $limit = 200): array
    {
        $table = $this->schema->normalizeTable($table);
        if (!$this->isSoftDeleteEnabled($table)) {
            return [];
        }

        $limit = max(1, min(2000, $limit));
        $sql = 'SELECT table_name, pk_column, pk_value, deleted_at, deleted_by, delete_reason, row_data
                FROM app_soft_delete
                WHERE table_name = :table_name
                ORDER BY deleted_at DESC
                LIMIT ' . $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['table_name' => $table]);
        $rows = $stmt->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed> */
    public function summary(string $table): array
    {
        $table = $this->schema->normalizeTable($table);
        $quotedTable = $this->schema->quoteIdentifier($table);
        $pk = $this->schema->getPrimaryKey($table);

        $totalSql = 'SELECT COUNT(*) FROM ' . $quotedTable;
        $totalParams = [];
        $soft = $this->softDeleteFilter($table, $pk, ':sd_table_summary_total');
        if ($soft['sql'] !== '') {
            $totalSql .= ' WHERE ' . $soft['sql'];
            $totalParams = $soft['params'];
        }
        $totalStmt = $this->pdo->prepare($totalSql);
        foreach ($totalParams as $k => $v) {
            $totalStmt->bindValue($k, $v);
        }
        $totalStmt->execute();
        $total = (int)$totalStmt->fetchColumn();

        $numericColumns = array_slice(
            array_values(array_filter(
                $this->schema->getNumericColumns($table),
                fn(string $name): bool => $name !== $pk
            )),
            0,
            5
        );

        $sums = [];
        foreach ($numericColumns as $column) {
            $sql = 'SELECT SUM(' . $this->schema->quoteIdentifier($column) . ') FROM ' . $quotedTable;
            $params = [];
            $softSum = $this->softDeleteFilter($table, $pk, ':sd_table_summary_sum');
            if ($softSum['sql'] !== '') {
                $sql .= ' WHERE ' . $softSum['sql'];
                $params = $softSum['params'];
            }
            $stmt = $this->pdo->prepare($sql);
            foreach ($params as $k => $v) {
                $stmt->bindValue($k, $v);
            }
            $stmt->execute();
            $sums[$column] = (float)($stmt->fetchColumn() ?? 0);
        }

        $latest = null;
        $sqlLatest = 'SELECT * FROM ' . $quotedTable;
        $paramsLatest = [];
        $softLatest = $this->softDeleteFilter($table, $pk, ':sd_table_summary_latest');
        if ($softLatest['sql'] !== '') {
            $sqlLatest .= ' WHERE ' . $softLatest['sql'];
            $paramsLatest = $softLatest['params'];
        }
        $sqlLatest .= ' ORDER BY ' . $this->schema->quoteIdentifier($pk) . ' DESC LIMIT 1';
        $stmtLatest = $this->pdo->prepare($sqlLatest);
        foreach ($paramsLatest as $k => $v) {
            $stmtLatest->bindValue($k, $v);
        }
        $stmtLatest->execute();
        $row = $stmtLatest->fetch();
        if (is_array($row)) {
            $latest = $row;
        }

        return [
            'table' => $table,
            'primaryKey' => $pk,
            'totalRows' => $total,
            'sum' => $sums,
            'latestRow' => $latest,
        ];
    }

    /** @param string[] $allColumns
      * @param array<string, mixed> $request
      * @return string[]
      */
    private function resolveSelectColumns(array $allColumns, array $request): array
    {
        if (!isset($request['select_columns']) || !is_array($request['select_columns'])) {
            return $allColumns;
        }

        $selected = [];
        foreach ($request['select_columns'] as $column) {
            $column = (string)$column;
            if (in_array($column, $allColumns, true)) {
                $selected[] = $column;
            }
        }

        if (empty($selected)) {
            return $allColumns;
        }

        return $selected;
    }

    /** @param array<string, mixed> $request
      * @return array<string, string>
      */
    private function resolveFixedFilters(string $table, array $request): array
    {
        $columns = $this->schema->getSelectableColumns($table);
        $filters = [];

        if (isset($request['filter_column']) && isset($request['filter_value'])) {
            $column = (string)$request['filter_column'];
            if (in_array($column, $columns, true)) {
                $filters[$column] = (string)$request['filter_value'];
            }
        }

        if (isset($request['fixed_filters']) && is_array($request['fixed_filters'])) {
            foreach ($request['fixed_filters'] as $column => $value) {
                $column = (string)$column;
                if (!in_array($column, $columns, true)) {
                    continue;
                }
                $filters[$column] = (string)$value;
            }
        }

        return $filters;
    }

    /**
     * @param array<string, array<string, mixed>> $columnMap
     * @param array<string, mixed> $payload
     */
    private function validateInventoryLocationPayload(
        string $table,
        array $columnMap,
        array $payload,
        bool $isUpdate,
        string $pk,
        string $pkValue
    ): void {
        if (in_array(strtolower($table), ['erp_warehouse', 'erp_warehouse_location', 'erp_warehouse_shelf'], true)) {
            return;
        }

        $slotSets = $this->inventorySlotSets($columnMap);
        if (empty($slotSets)) {
            return;
        }

        $effectivePayload = $payload;
        if ($isUpdate && $pkValue !== '') {
            $current = $this->loadValidationRow($table, $pk, $pkValue);
            if (is_array($current)) {
                $effectivePayload = array_merge($current, $payload);
            }
        }

        $validateMaster = $this->tableExists('erp_warehouse')
            && $this->tableExists('erp_warehouse_location')
            && $this->tableExists('erp_warehouse_shelf');

        foreach ($slotSets as $set) {
            $warehouseColumn = $set['warehouse'];
            $locationColumn = $set['location'];
            $shelfColumn = $set['shelf'];

            $warehouseCode = $this->normalizeSlotValue($effectivePayload[$warehouseColumn] ?? null);
            $locationCode = $this->normalizeSlotValue($effectivePayload[$locationColumn] ?? null);
            $shelfCode = $this->normalizeSlotValue($effectivePayload[$shelfColumn] ?? null);

            if ($warehouseCode === '' || $locationCode === '' || $shelfCode === '') {
                throw new RuntimeException(
                    'warehouse/location/shelf is required: '
                    . $warehouseColumn . ', ' . $locationColumn . ', ' . $shelfColumn
                );
            }

            if (!$validateMaster) {
                continue;
            }

            $warehouseStmt = $this->pdo->prepare(
                'SELECT 1 FROM erp_warehouse WHERE warehouse_code = :warehouse_code LIMIT 1'
            );
            $warehouseStmt->execute(['warehouse_code' => $warehouseCode]);
            if ((int)($warehouseStmt->fetchColumn() ?? 0) !== 1) {
                throw new RuntimeException('invalid warehouse_code: ' . $warehouseCode);
            }

            $locationStmt = $this->pdo->prepare(
                'SELECT 1
                 FROM erp_warehouse_location
                 WHERE warehouse_code = :warehouse_code
                   AND location_code = :location_code
                 LIMIT 1'
            );
            $locationStmt->execute([
                'warehouse_code' => $warehouseCode,
                'location_code' => $locationCode,
            ]);
            if ((int)($locationStmt->fetchColumn() ?? 0) !== 1) {
                throw new RuntimeException(
                    'invalid location_code for warehouse '
                    . $warehouseCode . ': ' . $locationCode
                );
            }

            $shelfStmt = $this->pdo->prepare(
                'SELECT 1
                 FROM erp_warehouse_shelf
                 WHERE warehouse_code = :warehouse_code
                   AND location_code = :location_code
                   AND shelf_code = :shelf_code
                 LIMIT 1'
            );
            $shelfStmt->execute([
                'warehouse_code' => $warehouseCode,
                'location_code' => $locationCode,
                'shelf_code' => $shelfCode,
            ]);
            if ((int)($shelfStmt->fetchColumn() ?? 0) !== 1) {
                throw new RuntimeException(
                    'invalid shelf_code for warehouse/location '
                    . $warehouseCode . '/' . $locationCode . ': ' . $shelfCode
                );
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $columnMap
     * @return array<int, array{warehouse:string, location:string, shelf:string}>
     */
    private function inventorySlotSets(array $columnMap): array
    {
        $sets = [];

        if (isset($columnMap['warehouse_code'], $columnMap['location_code'], $columnMap['shelf_code'])) {
            $sets[] = [
                'warehouse' => 'warehouse_code',
                'location' => 'location_code',
                'shelf' => 'shelf_code',
            ];
        }

        if (isset($columnMap['from_warehouse'], $columnMap['from_location_code'], $columnMap['from_shelf_code'])) {
            $sets[] = [
                'warehouse' => 'from_warehouse',
                'location' => 'from_location_code',
                'shelf' => 'from_shelf_code',
            ];
        }

        if (isset($columnMap['to_warehouse'], $columnMap['to_location_code'], $columnMap['to_shelf_code'])) {
            $sets[] = [
                'warehouse' => 'to_warehouse',
                'location' => 'to_location_code',
                'shelf' => 'to_shelf_code',
            ];
        }

        return $sets;
    }

    /** @param mixed $value */
    private function normalizeSlotValue($value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string)$value);
    }

    /** @return array<string, mixed>|null */
    private function loadValidationRow(string $table, string $pk, string $pkValue): ?array
    {
        $sql = 'SELECT * FROM ' . $this->schema->quoteIdentifier($table)
            . ' WHERE ' . $this->schema->quoteIdentifier($pk) . ' = :pk LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['pk' => $pkValue]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function tableExists(string $table): bool
    {
        $cacheKey = strtolower(trim($table));
        if ($cacheKey === '') {
            return false;
        }

        if (array_key_exists($cacheKey, $this->tableExistsCache)) {
            return $this->tableExistsCache[$cacheKey];
        }

        $sql = 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['table_name' => $table]);
        $exists = ((int)$stmt->fetchColumn()) > 0;
        $this->tableExistsCache[$cacheKey] = $exists;

        return $exists;
    }

    /** @param mixed $value
      * @param array<string, mixed> $columnMeta
      * @return mixed
      */
    private function normalizeValue($value, array $columnMeta, string $columnName)
    {
        $dataType = strtolower((string)($columnMeta['data_type'] ?? ''));
        $columnTypeRaw = (string)($columnMeta['column_type'] ?? '');
        $columnType = strtolower($columnTypeRaw);
        $isNullable = strtoupper((string)($columnMeta['is_nullable'] ?? 'YES')) === 'YES';
        $maxLength = (int)($columnMeta['character_maximum_length'] ?? 0);

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '' || $value === null) {
            if ($isNullable) {
                return null;
            }

            if (in_array($dataType, ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext'], true)) {
                return '';
            }

            throw new RuntimeException('field required: ' . $columnName);
        }

        if (in_array($dataType, ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)) {
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            if (is_string($value) && in_array(strtolower($value), ['true', 'false', 'yes', 'no', 'on', 'off'], true)) {
                return in_array(strtolower($value), ['true', 'yes', 'on'], true) ? 1 : 0;
            }
            if (is_numeric($value) && preg_match('/^-?\d+$/', (string)$value) === 1) {
                return (int)$value;
            }
            throw new RuntimeException('invalid integer: ' . $columnName);
        }

        if (in_array($dataType, ['decimal', 'double', 'float'], true)) {
            if (!is_numeric($value)) {
                throw new RuntimeException('invalid number: ' . $columnName);
            }
            return (float)$value;
        }

        if ($dataType === 'date') {
            return $this->normalizeDateValue((string)$value, $columnName);
        }

        if (in_array($dataType, ['datetime', 'timestamp'], true)) {
            return $this->normalizeDateTimeValue((string)$value, $columnName);
        }

        if ($dataType === 'time') {
            return $this->normalizeTimeValue((string)$value, $columnName);
        }

        if ($dataType === 'json') {
            if (is_array($value)) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded === false) {
                    throw new RuntimeException('invalid json: ' . $columnName);
                }
                return $encoded;
            }

            if (is_string($value)) {
                json_decode($value, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('invalid json: ' . $columnName);
                }
            }
        }

        $text = (string)$value;
        if ($maxLength > 0) {
            $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
            if ($length > $maxLength) {
                throw new RuntimeException('value too long: ' . $columnName . ' max ' . $maxLength);
            }
        }

        if (str_starts_with($columnType, 'enum(')) {
            $options = $this->enumOptions($columnTypeRaw);
            if (!in_array($text, $options, true)) {
                throw new RuntimeException('invalid enum value: ' . $columnName);
            }
        }

        if (str_starts_with($columnType, 'set(')) {
            $options = $this->enumOptions($columnTypeRaw);
            $parts = array_filter(array_map('trim', explode(',', $text)), static fn(string $x): bool => $x !== '');
            foreach ($parts as $part) {
                if (!in_array($part, $options, true)) {
                    throw new RuntimeException('invalid set value: ' . $columnName);
                }
            }
        }

        return $text;
    }

    private function normalizeDateValue(string $value, string $columnName): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException('invalid date: ' . $columnName);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        $ts = strtotime($value);
        if ($ts === false) {
            throw new RuntimeException('invalid date: ' . $columnName);
        }

        return date('Y-m-d', $ts);
    }

    private function normalizeDateTimeValue(string $value, string $columnName): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException('invalid datetime: ' . $columnName);
        }

        $normalized = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $normalized) === 1) {
            $normalized .= ':00';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $normalized) === 1) {
            return $normalized;
        }

        $ts = strtotime($normalized);
        if ($ts === false) {
            throw new RuntimeException('invalid datetime: ' . $columnName);
        }

        return date('Y-m-d H:i:s', $ts);
    }

    private function normalizeTimeValue(string $value, string $columnName): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException('invalid time: ' . $columnName);
        }

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1) {
            return $value;
        }

        throw new RuntimeException('invalid time: ' . $columnName);
    }

    /** @return string[] */
    private function enumOptions(string $columnType): array
    {
        $ok = preg_match_all("/'((?:\\\\'|[^'])*)'/", $columnType, $matches);
        if ($ok === false || empty($matches[1])) {
            return [];
        }

        $options = [];
        foreach ($matches[1] as $item) {
            $options[] = stripslashes((string)$item);
        }

        return $options;
    }

    private function isSoftDeleteEnabled(string $table): bool
    {
        if (strtolower($table) === 'app_soft_delete') {
            return false;
        }

        return $this->ensureSoftDeleteTable();
    }

    private function ensureSoftDeleteTable(): bool
    {
        if ($this->softDeleteReady !== null) {
            return $this->softDeleteReady;
        }

        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS app_soft_delete (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    table_name VARCHAR(120) NOT NULL,
                    pk_column VARCHAR(120) NOT NULL,
                    pk_value VARCHAR(255) NOT NULL,
                    row_data LONGTEXT NULL,
                    deleted_by VARCHAR(120) NULL,
                    delete_reason VARCHAR(255) NULL,
                    deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_table_pk (table_name, pk_value),
                    KEY idx_table_deleted_at (table_name, deleted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci'
            );
            $this->softDeleteReady = true;
        } catch (Throwable $_) {
            $this->softDeleteReady = false;
        }

        return $this->softDeleteReady;
    }

    /**
     * @return array{sql: string, params: array<string, string>}
     */
    private function softDeleteFilter(string $table, string $pk, string $paramName): array
    {
        if (!$this->isSoftDeleteEnabled($table)) {
            return ['sql' => '', 'params' => []];
        }

        $quotedPk = $this->schema->quoteIdentifier($pk);
        return [
            'sql' => 'NOT EXISTS (
                SELECT 1
                FROM app_soft_delete sd
                WHERE sd.table_name = ' . $paramName . '
                  AND sd.pk_value = CAST(' . $quotedPk . ' AS CHAR)
            )',
            'params' => [$paramName => $table],
        ];
    }
}
