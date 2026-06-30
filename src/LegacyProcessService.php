<?php

namespace Stock2;

use PDO;
use RuntimeException;

final class LegacyProcessService
{
    private PDO $pdo;
    private SchemaService $schema;
    private LegacyModuleService $modules;
    private DocumentNumberService $documentNumber;
    private ?bool $softDeleteReady = null;

    public function __construct(
        PDO $pdo,
        SchemaService $schema,
        LegacyModuleService $modules,
        DocumentNumberService $documentNumber
    ) {
        $this->pdo = $pdo;
        $this->schema = $schema;
        $this->modules = $modules;
        $this->documentNumber = $documentNumber;
    }

    /**
     * @param array<string, mixed> $mainData
     * @param array<int, array<string, mixed>> $details
     * @return array<string, mixed>
     */
    public function create(string $moduleKey, array $mainData, array $details, string $username): array
    {
        $module = $this->modules->find($moduleKey);
        if (!$module) {
            throw new RuntimeException('Unknown module key');
        }

        if (($module['mode'] ?? '') !== 'master_detail') {
            throw new RuntimeException('Module is not master_detail');
        }

        $this->modules->validateModule($module, $this->schema);

        $mainTable = (string)$module['main_table'];
        $detailTable = (string)$module['detail_table'];
        $sourceColumn = (string)$module['detail_source_column'];
        $targetColumn = (string)$module['detail_target_column'];
        $mainDocColumn = (string)($module['main_doc_column'] ?? $sourceColumn);

        $mainCols = $this->schema->listColumns($mainTable);
        $detailCols = $this->schema->listColumns($detailTable);

        $this->pdo->beginTransaction();

        try {
            $docInfo = null;
            $docValue = trim((string)($mainData[$mainDocColumn] ?? ''));
            if ($docValue === '' && isset($module['bill_name'])) {
                $docInfo = $this->documentNumber->next((string)$module['bill_name']);
                $docValue = (string)$docInfo['code'];
                $mainData[$mainDocColumn] = $docValue;
            }

            if ($docValue !== '' && (!isset($mainData[$sourceColumn]) || trim((string)$mainData[$sourceColumn]) === '')) {
                $mainData[$sourceColumn] = $docValue;
            }

            $mainData = $this->applySmartDefaults($mainData, $mainCols, $username);

            $mainInsert = $this->insertRow($mainTable, $mainCols, $mainData);
            $mainId = (string)$mainInsert['id'];

            $sourceValue = trim((string)($mainData[$sourceColumn] ?? ''));
            if ($sourceValue === '') {
                $sourceValue = $mainId;
                $this->updateSingleColumnByPk($mainTable, $sourceColumn, $sourceValue, $mainId);
            }

            if (empty($details)) {
                $details[] = $this->createMockDetailRow($module, $detailCols, $sourceValue, $targetColumn, $username);
            }

            $insertedDetailIds = [];
            foreach ($details as $detail) {
                $detail[$targetColumn] = $sourceValue;
                $detail = $this->applySmartDefaults($detail, $detailCols, $username);
                $detail = $this->computeDetailAmount($module, $detail);

                $detailInsert = $this->insertRow($detailTable, $detailCols, $detail);
                $insertedDetailIds[] = (string)$detailInsert['id'];
            }

            $totals = $this->calculateDetailTotals($module, $detailTable, $targetColumn, $sourceValue);
            $this->applyMainTotals($mainTable, $mainId, $totals);

            $this->pdo->commit();

            return [
                'module_key' => $moduleKey,
                'main_table' => $mainTable,
                'detail_table' => $detailTable,
                'main_id' => $mainId,
                'source_value' => $sourceValue,
                'document' => $docInfo,
                'detail_ids' => $insertedDetailIds,
                'totals' => $totals,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function createMock(string $moduleKey, string $username): array
    {
        $module = $this->modules->find($moduleKey);
        if (!$module) {
            throw new RuntimeException('Unknown module key');
        }

        $mainTable = (string)($module['main_table'] ?? '');
        $mainCols = $this->schema->listColumns($mainTable);

        $main = [];
        foreach ($mainCols as $column) {
            $name = (string)$column['column_name'];
            if (in_array($name, ['cust_name', 'seller_name', 'sale_name', 'supplier_name'], true)) {
                $main[$name] = 'Mock Customer';
            }
            if (in_array($name, ['cust_id', 'seller_id', 'sale_code', 'supplier_code'], true)) {
                $main[$name] = 'MOCK001';
            }
            if (in_array($name, ['note', 'detail'], true)) {
                $main[$name] = 'Auto mock process';
            }
        }

        return $this->create($moduleKey, $main, [], $username);
    }

    public function delete(string $moduleKey, string $mainId, ?string $sourceValue, ?string $deletedBy = null): array
    {
        $module = $this->modules->find($moduleKey);
        if (!$module) {
            throw new RuntimeException('Unknown module key');
        }

        if (($module['mode'] ?? '') !== 'master_detail') {
            throw new RuntimeException('Module is not master_detail');
        }

        $this->modules->validateModule($module, $this->schema);

        $mainTable = (string)$module['main_table'];
        $detailTable = (string)$module['detail_table'];
        $sourceColumn = (string)$module['detail_source_column'];
        $targetColumn = (string)$module['detail_target_column'];
        $mainPk = $this->schema->getPrimaryKey($mainTable);

        $this->ensureSoftDeleteTable();
        $this->pdo->beginTransaction();

        try {
            $realSource = $sourceValue !== null ? trim($sourceValue) : '';
            if ($realSource === '') {
                $sql = 'SELECT ' . $this->schema->quoteIdentifier($sourceColumn)
                    . ' FROM ' . $this->schema->quoteIdentifier($mainTable)
                    . ' WHERE ' . $this->schema->quoteIdentifier($mainPk) . ' = :id';
                $soft = $this->softDeleteFilter($mainTable, $mainPk, ':sd_main');
                $params = ['id' => $mainId];
                if ($soft['sql'] !== '') {
                    $sql .= ' AND ' . $soft['sql'];
                    $params = array_merge($params, $soft['params']);
                }
                $sql .= ' LIMIT 1';
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $realSource = (string)($stmt->fetchColumn() ?? '');
            }

            $deletedDetails = 0;
            $detailPk = $this->schema->getPrimaryKey($detailTable);
            if ($realSource !== '') {
                $sql = 'SELECT ' . $this->schema->quoteIdentifier($detailPk)
                    . ' FROM ' . $this->schema->quoteIdentifier($detailTable)
                    . ' WHERE ' . $this->schema->quoteIdentifier($targetColumn) . ' = :source';
                $soft = $this->softDeleteFilter($detailTable, $detailPk, ':sd_detail');
                $params = ['source' => $realSource];
                if ($soft['sql'] !== '') {
                    $sql .= ' AND ' . $soft['sql'];
                    $params = array_merge($params, $soft['params']);
                }
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                $detailIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($detailIds as $detailId) {
                    if ($this->markSoftDeleted($detailTable, $detailPk, (string)$detailId, $deletedBy)) {
                        $deletedDetails++;
                    }
                }
            }

            $deletedMain = $this->markSoftDeleted($mainTable, $mainPk, $mainId, $deletedBy) ? 1 : 0;

            $this->pdo->commit();

            return [
                'deleted_main' => $deletedMain,
                'deleted_detail' => $deletedDetails,
                'source_value' => $realSource,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $columns
     * @return array<string, mixed>
     */
    private function applySmartDefaults(array $data, array $columns, string $username): array
    {
        $nowDate = date('Y-m-d');
        $nowDateTime = date('Y-m-d H:i:s');

        foreach ($columns as $column) {
            $name = (string)$column['column_name'];
            $type = strtolower((string)$column['data_type']);

            if (!array_key_exists($name, $data)) {
                if (in_array($name, ['transdate', 'last_contact', 'buy_lot_date'], true)) {
                    $data[$name] = $nowDateTime;
                    continue;
                }

                if (in_array($name, ['billdate', 'bill_date', 'delivery_date', 'recivedate', 'check_date'], true)) {
                    $data[$name] = $nowDate;
                    continue;
                }

                if (in_array($name, ['staff_username', 'user_name', 'username', 'users', 'staff'], true)) {
                    $data[$name] = $username;
                    continue;
                }

                if (in_array($name, ['staff_name', 'user_realname', 'sale_name'], true)) {
                    $data[$name] = $username;
                    continue;
                }

                if (in_array($name, ['discount', 'cancel', 'billing', 'paid', 'note'], true)) {
                    if ($name === 'note') {
                        $data[$name] = 'Auto generated';
                    } else {
                        $data[$name] = '0';
                    }
                    continue;
                }
            }

            $value = $data[$name] ?? null;
            if ($value === '' || $value === null) {
                if (in_array($type, ['date'], true)) {
                    $data[$name] = $nowDate;
                } elseif (in_array($type, ['datetime', 'timestamp'], true)) {
                    $data[$name] = $nowDateTime;
                } elseif (in_array($type, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'double', 'float'], true)) {
                    $data[$name] = 0;
                }
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $module
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function computeDetailAmount(array $module, array $detail): array
    {
        $amountColumn = (string)($module['detail_amount_column'] ?? '');
        $qtyColumn = (string)($module['detail_qty_column'] ?? '');
        $priceColumn = (string)($module['detail_price_column'] ?? '');

        if ($amountColumn === '') {
            return $detail;
        }

        $amountValue = isset($detail[$amountColumn]) ? (float)$detail[$amountColumn] : 0.0;
        if ($amountValue > 0) {
            return $detail;
        }

        $qty = $qtyColumn !== '' && isset($detail[$qtyColumn]) ? (float)$detail[$qtyColumn] : 0.0;
        $price = $priceColumn !== '' && isset($detail[$priceColumn]) ? (float)$detail[$priceColumn] : 0.0;

        if ($qty > 0 && $price > 0) {
            $detail[$amountColumn] = round($qty * $price, 2);
        }

        return $detail;
    }

    /**
     * @param array<string, mixed> $module
     * @param array<int, array<string, mixed>> $detailCols
     * @return array<string, mixed>
     */
    private function createMockDetailRow(array $module, array $detailCols, string $sourceValue, string $targetColumn, string $username): array
    {
        $row = [
            $targetColumn => $sourceValue,
        ];

        foreach ($detailCols as $column) {
            $name = (string)$column['column_name'];
            $type = strtolower((string)$column['data_type']);

            if (isset($row[$name])) {
                continue;
            }

            if (in_array($name, ['product_code', 'prd_id', 'master_prd_id', 'prd_master_id'], true)) {
                $row[$name] = 'MOCK-P01';
            } elseif (in_array($name, ['product_name'], true)) {
                $row[$name] = 'Mock Product';
            } elseif (in_array($name, ['unit_name'], true)) {
                $row[$name] = 'PCS';
            } elseif (in_array($name, ['users', 'username'], true)) {
                $row[$name] = $username;
            } elseif (in_array($name, ['discount'], true)) {
                $row[$name] = '0';
            } elseif (in_array($name, ['location'], true)) {
                $row[$name] = '1';
            } elseif (in_array($type, ['datetime', 'timestamp'], true)) {
                $row[$name] = date('Y-m-d H:i:s');
            } elseif (in_array($type, ['date'], true)) {
                $row[$name] = date('Y-m-d');
            }
        }

        $qtyColumn = (string)($module['detail_qty_column'] ?? '');
        $priceColumn = (string)($module['detail_price_column'] ?? '');
        $amountColumn = (string)($module['detail_amount_column'] ?? '');

        if ($qtyColumn !== '') {
            $row[$qtyColumn] = $row[$qtyColumn] ?? 1;
        }
        if ($priceColumn !== '') {
            $row[$priceColumn] = $row[$priceColumn] ?? 100;
        }
        if ($amountColumn !== '') {
            $row[$amountColumn] = $row[$amountColumn] ?? 100;
        }

        return $row;
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function insertRow(string $table, array $columns, array $data): array
    {
        $insertData = [];

        foreach ($columns as $column) {
            $name = (string)$column['column_name'];
            $extra = strtolower((string)($column['extra'] ?? ''));
            $nullable = strtoupper((string)($column['is_nullable'] ?? 'YES')) === 'YES';
            $default = $column['column_default'];
            $type = strtolower((string)$column['data_type']);

            if (str_contains($extra, 'auto_increment')) {
                continue;
            }

            if (array_key_exists($name, $data)) {
                $value = $data[$name];
                if ($value === '' && $nullable) {
                    $value = null;
                }
                $insertData[$name] = $value;
                continue;
            }

            if ($default !== null && strtoupper((string)$default) !== 'NULL') {
                $insertData[$name] = $default;
                continue;
            }

            if (!$nullable) {
                if (in_array($type, ['int', 'tinyint', 'smallint', 'mediumint', 'bigint', 'decimal', 'double', 'float'], true)) {
                    $insertData[$name] = 0;
                } elseif (in_array($type, ['date'], true)) {
                    $insertData[$name] = date('Y-m-d');
                } elseif (in_array($type, ['datetime', 'timestamp'], true)) {
                    $insertData[$name] = date('Y-m-d H:i:s');
                } else {
                    $insertData[$name] = '';
                }
            }
        }

        if (empty($insertData)) {
            throw new RuntimeException('No insertable data for table: ' . $table);
        }

        $columnsQuoted = [];
        $params = [];
        foreach ($insertData as $name => $value) {
            $columnsQuoted[] = $this->schema->quoteIdentifier($name);
            $params[] = ':' . $name;
        }

        $sql = 'INSERT INTO ' . $this->schema->quoteIdentifier($table)
            . ' (' . implode(', ', $columnsQuoted) . ')'
            . ' VALUES (' . implode(', ', $params) . ')';

        $stmt = $this->pdo->prepare($sql);
        foreach ($insertData as $name => $value) {
            $stmt->bindValue(':' . $name, $value);
        }
        $stmt->execute();

        return [
            'id' => (string)$this->pdo->lastInsertId(),
            'data' => $insertData,
        ];
    }

    private function updateSingleColumnByPk(string $table, string $column, string $value, string $id): void
    {
        $pk = $this->schema->getPrimaryKey($table);

        $sql = 'UPDATE ' . $this->schema->quoteIdentifier($table)
            . ' SET ' . $this->schema->quoteIdentifier($column) . ' = :value'
            . ' WHERE ' . $this->schema->quoteIdentifier($pk) . ' = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'value' => $value,
            'id' => $id,
        ]);
    }

    /** @return array<string, float> */
    private function calculateDetailTotals(array $module, string $detailTable, string $targetColumn, string $sourceValue): array
    {
        $amountColumn = (string)($module['detail_amount_column'] ?? 'amount');

        $detailColumns = $this->schema->getSelectableColumns($detailTable);
        if (!in_array($amountColumn, $detailColumns, true)) {
            return [
                'amount' => 0.0,
                'vat' => 0.0,
                'final' => 0.0,
            ];
        }

        $sql = 'SELECT COALESCE(SUM(' . $this->schema->quoteIdentifier($amountColumn) . '), 0)'
            . ' FROM ' . $this->schema->quoteIdentifier($detailTable)
            . ' WHERE ' . $this->schema->quoteIdentifier($targetColumn) . ' = :source';
        $params = ['source' => $sourceValue];
        $detailPk = $this->schema->getPrimaryKey($detailTable);
        $soft = $this->softDeleteFilter($detailTable, $detailPk, ':sd_detail_total');
        if ($soft['sql'] !== '') {
            $sql .= ' AND ' . $soft['sql'];
            $params = array_merge($params, $soft['params']);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $total = (float)($stmt->fetchColumn() ?? 0);

        $vat = 0.0;
        $final = $total + $vat;

        return [
            'amount' => round($total, 2),
            'vat' => round($vat, 2),
            'final' => round($final, 2),
        ];
    }

    /** @param array<string, float> $totals */
    private function applyMainTotals(string $mainTable, string $mainId, array $totals): void
    {
        $columns = $this->schema->getSelectableColumns($mainTable);
        $pk = $this->schema->getPrimaryKey($mainTable);

        $sets = [];
        $params = ['id' => $mainId];

        $map = [
            'total' => 'amount',
            'sum_total' => 'amount',
            'balance' => 'amount',
            'final_balance' => 'final',
            'finalbalance' => 'final',
            'vat' => 'vat',
            'vattotal' => 'vat',
            'total_balance' => 'amount',
            'outstanding' => 'amount',
        ];

        foreach ($map as $column => $sourceKey) {
            if (!in_array($column, $columns, true)) {
                continue;
            }
            $sets[] = $this->schema->quoteIdentifier($column) . ' = :' . $column;
            $params[$column] = $totals[$sourceKey] ?? 0;
        }

        if (empty($sets)) {
            return;
        }

        $sql = 'UPDATE ' . $this->schema->quoteIdentifier($mainTable)
            . ' SET ' . implode(', ', $sets)
            . ' WHERE ' . $this->schema->quoteIdentifier($pk) . ' = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function ensureSoftDeleteTable(): bool
    {
        if ($this->softDeleteReady !== null) {
            return $this->softDeleteReady;
        }

        try {
            $checkSql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'app_soft_delete'";
            $exists = ((int)$this->pdo->query($checkSql)->fetchColumn()) > 0;
            if ($exists) {
                $this->softDeleteReady = true;
                return true;
            }

            if ($this->pdo->inTransaction()) {
                $this->softDeleteReady = false;
                return false;
            }

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
        } catch (\Throwable $_) {
            $this->softDeleteReady = false;
        }

        return $this->softDeleteReady;
    }

    private function markSoftDeleted(string $table, string $pk, string $id, ?string $deletedBy = null): bool
    {
        if (!$this->ensureSoftDeleteTable()) {
            return false;
        }

        $sql = 'SELECT * FROM ' . $this->schema->quoteIdentifier($table)
            . ' WHERE ' . $this->schema->quoteIdentifier($pk) . ' = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return false;
        }

        $rowData = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($rowData === false) {
            $rowData = null;
        }

        $sqlIns = <<<'SQL'
INSERT INTO app_soft_delete (table_name, pk_column, pk_value, row_data, deleted_by, delete_reason, deleted_at)
VALUES (:table_name, :pk_column, :pk_value, :row_data, :deleted_by, NULL, NOW())
ON DUPLICATE KEY UPDATE
    pk_column = VALUES(pk_column),
    row_data = VALUES(row_data),
    deleted_by = VALUES(deleted_by),
    deleted_at = VALUES(deleted_at)
SQL;
        $ins = $this->pdo->prepare($sqlIns);
        $ins->execute([
            'table_name' => $table,
            'pk_column' => $pk,
            'pk_value' => (string)$id,
            'row_data' => $rowData,
            'deleted_by' => $deletedBy !== null && trim($deletedBy) !== '' ? trim($deletedBy) : null,
        ]);

        return true;
    }

    /**
     * @return array{sql: string, params: array<string, string>}
     */
    private function softDeleteFilter(string $table, string $pk, string $paramName): array
    {
        if (strtolower($table) === 'app_soft_delete' || !$this->ensureSoftDeleteTable()) {
            return ['sql' => '', 'params' => []];
        }

        return [
            'sql' => 'NOT EXISTS (
                SELECT 1
                FROM app_soft_delete sd
                WHERE sd.table_name = ' . $paramName . '
                  AND sd.pk_value = CAST(' . $this->schema->quoteIdentifier($pk) . ' AS CHAR)
            )',
            'params' => [$paramName => $table],
        ];
    }
}
