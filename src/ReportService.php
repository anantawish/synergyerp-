<?php

namespace Stock2;

use PDO;
use RuntimeException;

final class ReportService
{
    private const LIST_MAX_COLUMNS = 12;
    private const DOC_MAX_MAIN_FIELDS = 20;
    private const DETAIL_MAX_COLUMNS = 12;

    private PDO $pdo;
    private SchemaService $schema;
    private LegacyModuleService $modules;
    private ?bool $softDeleteTableAvailable = null;

    /** @var array<string, string> */
    private array $columnLabels = [
        'id' => 'ID',
        'bill_id' => 'Document No',
        'bill_buy_id' => 'Document No',
        'order_id' => 'Order No',
        'billdate' => 'Document Date',
        'bill_date' => 'Document Date',
        'transdate' => 'Transaction Date',
        'created_at' => 'Created At',
        'updated_at' => 'Updated At',
        'cust_id' => 'Customer Code',
        'cust_name' => 'Customer Name',
        'seller_id' => 'Supplier Code',
        'seller_name' => 'Supplier Name',
        'supplier_code' => 'Supplier Code',
        'supplier_name' => 'Supplier Name',
        'creditor_id' => 'Creditor Code',
        'deptor_id' => 'Debtor Code',
        'product_code' => 'Product Code',
        'prd_id' => 'Product Code',
        'product_name' => 'Product Name',
        'unit_name' => 'Unit',
        'items' => 'Qty',
        'recive_item' => 'Qty',
        'delivery_items' => 'Qty',
        'item_price' => 'Unit Price',
        'items_price' => 'Unit Price',
        'unit_price' => 'Unit Price',
        'discount' => 'Discount',
        'amount' => 'Amount',
        'total' => 'Total',
        'sum_total' => 'Total',
        'balance' => 'Balance',
        'final_balance' => 'Final Total',
        'finalbalance' => 'Final Total',
        'total_balance' => 'Final Total',
        'outstanding' => 'Outstanding',
        'note' => 'Note',
        'detail' => 'Detail',
        'status' => 'Status',
        'username' => 'User',
        'users' => 'User',
        'staff_username' => 'User',
    ];

    public function __construct(PDO $pdo, SchemaService $schema, LegacyModuleService $modules)
    {
        $this->pdo = $pdo;
        $this->schema = $schema;
        $this->modules = $modules;
    }

    /** @return array<string, mixed> */
    public function company(): array
    {
        try {
            $row = $this->pdo->query('SELECT * FROM company LIMIT 1')->fetch();
            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    public function listReport(string $moduleKey, array $filters = []): array
    {
        $module = $this->modules->find($moduleKey);
        if (!$module) {
            throw new RuntimeException('module not found');
        }

        if (($module['mode'] ?? '') === 'placeholder') {
            throw new RuntimeException('module has no report implementation');
        }

        $mainTable = (string)$module['main_table'];
        $this->schema->normalizeTable($mainTable);

        $allColumns = $this->schema->getSelectableColumns($mainTable);
        $pk = $this->schema->getPrimaryKey($mainTable);
        $columns = $this->chooseListDisplayColumns($allColumns, $pk);

        $limit = (int)($filters['limit'] ?? 300);
        if ($limit <= 0 || $limit > 2000) {
            $limit = 300;
        }

        $search = trim((string)($filters['q'] ?? ''));
        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        $dateTo = trim((string)($filters['date_to'] ?? ''));

        $where = [];
        $params = [];

        $softMain = $this->softDeleteFilter($mainTable, $pk, ':sd_main');
        if ($softMain['sql'] !== '') {
            $where[] = $softMain['sql'];
            $params = array_merge($params, $softMain['params']);
        }

        if ($search !== '') {
            $searchable = array_slice($this->schema->getSearchableColumns($mainTable), 0, 10);
            if (!empty($searchable)) {
                $parts = [];
                foreach ($searchable as $i => $col) {
                    $param = ':search' . $i;
                    $parts[] = $this->schema->quoteIdentifier($col) . ' LIKE ' . $param;
                    $params[$param] = '%' . $search . '%';
                }
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $dateColumn = $this->guessDateColumn($mainTable);
        if ($dateColumn !== null) {
            if ($dateFrom !== '') {
                $where[] = $this->schema->quoteIdentifier($dateColumn) . ' >= :date_from';
                $params[':date_from'] = $dateFrom;
            }
            if ($dateTo !== '') {
                $where[] = $this->schema->quoteIdentifier($dateColumn) . ' <= :date_to';
                $params[':date_to'] = $dateTo;
            }
        }

        $whereSql = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));

        $quotedCols = array_map(fn(string $c): string => $this->schema->quoteIdentifier($c), $columns);
        $sql = 'SELECT ' . implode(', ', $quotedCols)
            . ' FROM ' . $this->schema->quoteIdentifier($mainTable)
            . $whereSql
            . ' ORDER BY ' . $this->schema->quoteIdentifier($pk) . ' DESC'
            . ' LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $countSql = 'SELECT COUNT(*) FROM ' . $this->schema->quoteIdentifier($mainTable) . $whereSql;
        $countStmt = $this->pdo->prepare($countSql);
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $count = (int)$countStmt->fetchColumn();

        $numericSummary = [];
        $numericCols = array_slice($this->schema->getNumericColumns($mainTable), 0, 5);
        foreach ($numericCols as $col) {
            $sumSql = 'SELECT COALESCE(SUM(' . $this->schema->quoteIdentifier($col) . '), 0) FROM '
                . $this->schema->quoteIdentifier($mainTable) . $whereSql;
            $sumStmt = $this->pdo->prepare($sumSql);
            foreach ($params as $k => $v) {
                $sumStmt->bindValue($k, $v);
            }
            $sumStmt->execute();
            $numericSummary[$col] = (float)$sumStmt->fetchColumn();
        }

        return [
            'module' => $module,
            'main_table' => $mainTable,
            'columns' => $columns,
            'all_columns' => $allColumns,
            'display_columns' => $columns,
            'column_labels' => $this->buildColumnLabels($columns),
            'rows' => $rows,
            'count' => $count,
            'filters' => [
                'q' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit,
            ],
            'summary' => $numericSummary,
            'date_column' => $dateColumn,
        ];
    }

    /** @return array<string, mixed> */
    public function documentReport(string $moduleKey, string $mainId): array
    {
        $module = $this->modules->find($moduleKey);
        if (!$module) {
            throw new RuntimeException('module not found');
        }

        if (($module['mode'] ?? '') === 'placeholder') {
            throw new RuntimeException('module has no report implementation');
        }

        $mainTable = (string)$module['main_table'];
        $this->schema->normalizeTable($mainTable);
        $mainPk = $this->schema->getPrimaryKey($mainTable);

        $mainRow = $this->fetchRowByPk($mainTable, $mainPk, $mainId);

        $detailRows = [];
        $detailColumns = [];
        $detailDisplayColumns = [];

        if (($module['mode'] ?? '') === 'master_detail') {
            $detailTable = (string)$module['detail_table'];
            $targetColumn = (string)$module['detail_target_column'];
            $sourceColumn = (string)$module['detail_source_column'];
            $this->schema->normalizeTable($detailTable);

            $sourceValue = (string)($mainRow[$sourceColumn] ?? '');
            if ($sourceValue === '' && isset($module['main_doc_column'])) {
                $sourceValue = (string)($mainRow[(string)$module['main_doc_column']] ?? '');
            }
            if ($sourceValue === '') {
                $sourceValue = (string)$mainId;
            }

            $detailColumns = $this->schema->getSelectableColumns($detailTable);
            $detailRows = $this->fetchDetailRows($detailTable, $targetColumn, $sourceValue);
            $detailDisplayColumns = $this->chooseDetailDisplayColumns($detailColumns);
        }

        $mainFields = $this->buildMainFields($mainRow, $mainPk, $module);

        return [
            'module' => $module,
            'main_table' => $mainTable,
            'main_pk' => $mainPk,
            'main_id' => $mainId,
            'main_row' => $mainRow,
            'doc_no' => $this->firstValue($mainRow, [
                (string)($module['main_doc_column'] ?? ''),
                (string)($module['detail_source_column'] ?? ''),
                'bill_id',
                'bill_buy_id',
                'order_id',
                $mainPk,
            ]),
            'doc_date' => $this->firstValue($mainRow, ['billdate', 'bill_date', 'transdate', 'delivery_date', 'recivedate']),
            'party_name' => $this->firstValue($mainRow, ['cust_name', 'seller_name', 'supplier_name', 'sale_name', 'business_name', 'name']),
            'party_code' => $this->firstValue($mainRow, ['cust_id', 'seller_id', 'supplier_code', 'sale_code', 'creditor_id', 'deptor_id']),
            'note' => $this->firstValue($mainRow, ['note', 'detail', 'delivery_place', 'address']),
            'main_total' => $this->firstValue($mainRow, ['final_balance', 'finalbalance', 'total_balance', 'total', 'sum_total', 'balance', 'outstanding']),
            'main_fields' => $mainFields,
            'main_field_labels' => $this->buildColumnLabels(array_map(static fn(array $x): string => (string)$x['column'], $mainFields)),
            'detail_rows' => $detailRows,
            'detail_columns' => $detailColumns,
            'detail_display_columns' => $detailDisplayColumns,
            'detail_column_labels' => $this->buildColumnLabels($detailDisplayColumns),
            'detail_totals' => $this->calcDetailTotals($module, $detailRows),
        ];
    }

    /** @return array<string, mixed> */
    private function fetchRowByPk(string $table, string $pk, string $id): array
    {
        $sql = 'SELECT * FROM ' . $this->schema->quoteIdentifier($table)
            . ' WHERE ' . $this->schema->quoteIdentifier($pk) . ' = :id';
        $params = ['id' => $id];
        $soft = $this->softDeleteFilter($table, $pk, ':sd_doc_main');
        if ($soft['sql'] !== '') {
            $sql .= ' AND ' . $soft['sql'];
            $params = array_merge($params, $soft['params']);
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new RuntimeException('document row not found');
        }

        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchDetailRows(string $detailTable, string $targetColumn, string $sourceValue): array
    {
        $detailPk = $this->schema->getPrimaryKey($detailTable);

        $sql = 'SELECT * FROM ' . $this->schema->quoteIdentifier($detailTable)
            . ' WHERE ' . $this->schema->quoteIdentifier($targetColumn) . ' = :source'
            ;
        $params = ['source' => $sourceValue];
        $soft = $this->softDeleteFilter($detailTable, $detailPk, ':sd_doc_detail');
        if ($soft['sql'] !== '') {
            $sql .= ' AND ' . $soft['sql'];
            $params = array_merge($params, $soft['params']);
        }
        $sql .= ' ORDER BY ' . $this->schema->quoteIdentifier($detailPk) . ' ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param string[] $columns
     * @return string[]
     */
    private function chooseDetailDisplayColumns(array $columns): array
    {
        $preferred = [
            'product_code',
            'prd_id',
            'product_name',
            'unit_name',
            'delivery_items',
            'recive_item',
            'items',
            'item_price',
            'items_price',
            'unit_price',
            'discount',
            'amount',
            'total',
            'note',
        ];

        $result = [];
        foreach ($preferred as $col) {
            if (in_array($col, $columns, true)) {
                $result[] = $col;
            }
        }

        if (empty($result)) {
            $result = array_slice($columns, 0, self::DETAIL_MAX_COLUMNS);
        }

        return array_slice(array_values(array_unique($result)), 0, self::DETAIL_MAX_COLUMNS);
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $keys
     * @return mixed
     */
    private function firstValue(array $row, array $keys)
    {
        foreach ($keys as $k) {
            if ($k === '') {
                continue;
            }
            if (array_key_exists($k, $row) && $row[$k] !== null && (string)$row[$k] !== '') {
                return $row[$k];
            }
        }

        return '';
    }

    /** @return array<string, float> */
    private function calcDetailTotals(array $module, array $rows): array
    {
        $qtyCol = (string)($module['detail_qty_column'] ?? '');
        $priceCol = (string)($module['detail_price_column'] ?? '');
        $amountCol = (string)($module['detail_amount_column'] ?? 'amount');

        $sumQty = 0.0;
        $sumAmount = 0.0;

        foreach ($rows as $row) {
            if ($qtyCol !== '' && isset($row[$qtyCol])) {
                $sumQty += (float)$row[$qtyCol];
            }
            if ($amountCol !== '' && isset($row[$amountCol])) {
                $sumAmount += (float)$row[$amountCol];
                continue;
            }
            if ($priceCol !== '' && $qtyCol !== '' && isset($row[$priceCol], $row[$qtyCol])) {
                $sumAmount += (float)$row[$priceCol] * (float)$row[$qtyCol];
            }
        }

        return [
            'qty' => round($sumQty, 2),
            'amount' => round($sumAmount, 2),
        ];
    }

    private function guessDateColumn(string $table): ?string
    {
        $columns = $this->schema->listColumns($table);
        $candidates = ['billdate', 'bill_date', 'transdate', 'delivery_date', 'recivedate', 'created_at'];

        foreach ($candidates as $candidate) {
            foreach ($columns as $col) {
                if ((string)$col['column_name'] === $candidate) {
                    return $candidate;
                }
            }
        }

        foreach ($columns as $col) {
            $type = strtolower((string)$col['data_type']);
            if ($type === 'date' || $type === 'datetime' || $type === 'timestamp') {
                return (string)$col['column_name'];
            }
        }

        return null;
    }

    /**
     * @param string[] $columns
     * @return string[]
     */
    private function chooseListDisplayColumns(array $columns, string $pk): array
    {
        $preferred = [
            $pk,
            'bill_id',
            'bill_buy_id',
            'order_id',
            'billdate',
            'bill_date',
            'transdate',
            'cust_id',
            'cust_name',
            'seller_id',
            'seller_name',
            'supplier_code',
            'supplier_name',
            'product_code',
            'product_name',
            'items',
            'delivery_items',
            'recive_item',
            'item_price',
            'items_price',
            'unit_price',
            'amount',
            'total',
            'sum_total',
            'balance',
            'final_balance',
            'outstanding',
            'status',
            'users',
            'staff_username',
        ];

        $selected = [];
        foreach ($preferred as $col) {
            if ($col !== '' && in_array($col, $columns, true)) {
                $selected[] = $col;
            }
        }

        foreach ($columns as $col) {
            if (!in_array($col, $selected, true)) {
                $selected[] = $col;
            }
            if (count($selected) >= self::LIST_MAX_COLUMNS) {
                break;
            }
        }

        return array_slice(array_values(array_unique($selected)), 0, self::LIST_MAX_COLUMNS);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $module
     * @return array<int, array{column: string, label: string, value: mixed}>
     */
    private function buildMainFields(array $row, string $pk, array $module): array
    {
        $keys = array_keys($row);
        $preferred = [
            (string)($module['main_doc_column'] ?? ''),
            $pk,
            'bill_id',
            'bill_buy_id',
            'order_id',
            'billdate',
            'bill_date',
            'transdate',
            'cust_id',
            'cust_name',
            'seller_id',
            'seller_name',
            'supplier_code',
            'supplier_name',
            'creditor_id',
            'deptor_id',
            'total',
            'sum_total',
            'balance',
            'final_balance',
            'outstanding',
            'status',
            'note',
            'detail',
        ];

        $selected = [];
        foreach ($preferred as $col) {
            if ($col !== '' && in_array($col, $keys, true)) {
                $selected[] = $col;
            }
        }

        foreach ($keys as $col) {
            if (!in_array($col, $selected, true)) {
                $selected[] = $col;
            }
            if (count($selected) >= self::DOC_MAX_MAIN_FIELDS) {
                break;
            }
        }

        $fields = [];
        foreach (array_slice(array_values(array_unique($selected)), 0, self::DOC_MAX_MAIN_FIELDS) as $col) {
            $fields[] = [
                'column' => $col,
                'label' => $this->labelForColumn($col),
                'value' => $row[$col] ?? '',
            ];
        }

        return $fields;
    }

    /**
     * @param string[] $columns
     * @return array<string, string>
     */
    private function buildColumnLabels(array $columns): array
    {
        $labels = [];
        foreach ($columns as $col) {
            $labels[$col] = $this->labelForColumn($col);
        }

        return $labels;
    }

    private function labelForColumn(string $column): string
    {
        if (isset($this->columnLabels[$column])) {
            return $this->columnLabels[$column];
        }

        $base = str_replace('_', ' ', $column);
        $base = trim($base);
        if ($base === '') {
            return $column;
        }

        return ucwords($base);
    }

    private function hasSoftDeleteTable(): bool
    {
        if ($this->softDeleteTableAvailable !== null) {
            return $this->softDeleteTableAvailable;
        }

        try {
            $sql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'app_soft_delete'";
            $this->softDeleteTableAvailable = ((int)$this->pdo->query($sql)->fetchColumn()) > 0;
        } catch (\Throwable $_) {
            $this->softDeleteTableAvailable = false;
        }

        return $this->softDeleteTableAvailable;
    }

    /**
     * @return array{sql: string, params: array<string, string>}
     */
    private function softDeleteFilter(string $table, string $pk, string $paramName): array
    {
        if (strtolower($table) === 'app_soft_delete' || !$this->hasSoftDeleteTable()) {
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
