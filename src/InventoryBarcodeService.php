<?php

namespace Stock2;

use PDO;
use RuntimeException;

final class InventoryBarcodeService
{
    private PDO $pdo;
    /** @var array<string, bool> */
    private array $tableExists = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array<int, array<string, mixed>> */
    public function listWarehouses(): array
    {
        if ($this->tableExists('erp_warehouse')) {
            $stmt = $this->pdo->query(
                'SELECT warehouse_code, warehouse_name
                 FROM erp_warehouse
                 WHERE is_active = 1
                 ORDER BY warehouse_code'
            );
            $rows = $stmt->fetchAll();
            if (is_array($rows) && !empty($rows)) {
                return $rows;
            }
        }

        $stmt = $this->pdo->query(
            'SELECT DISTINCT warehouse_code, warehouse_code AS warehouse_name
             FROM stockcard
             WHERE warehouse_code IS NOT NULL AND TRIM(warehouse_code) <> \'\'
             ORDER BY warehouse_code'
        );
        $rows = $stmt->fetchAll();
        if (is_array($rows) && !empty($rows)) {
            return $rows;
        }

        return [
            ['warehouse_code' => 'MAIN_WH', 'warehouse_name' => 'Main Warehouse'],
            ['warehouse_code' => 'PRODUCTION_WH', 'warehouse_name' => 'Production Warehouse'],
            ['warehouse_code' => 'FG_WH', 'warehouse_name' => 'Finished Goods Warehouse'],
            ['warehouse_code' => 'PACK_WH', 'warehouse_name' => 'Packing Warehouse'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function listLocations(string $warehouseCode): array
    {
        $warehouseCode = $this->normalizeCode($warehouseCode);
        if ($warehouseCode === '') {
            return [];
        }

        if ($this->tableExists('erp_warehouse_location')) {
            $stmt = $this->pdo->prepare(
                'SELECT location_code, location_name
                 FROM erp_warehouse_location
                 WHERE warehouse_code = :warehouse_code
                   AND is_active = 1
                 ORDER BY location_code'
            );
            $stmt->execute(['warehouse_code' => $warehouseCode]);
            $rows = $stmt->fetchAll();
            if (is_array($rows) && !empty($rows)) {
                return $rows;
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT location_code, location_code AS location_name
             FROM stockcard
             WHERE warehouse_code = :warehouse_code
               AND location_code IS NOT NULL
               AND TRIM(location_code) <> \'\'
             ORDER BY location_code'
        );
        $stmt->execute(['warehouse_code' => $warehouseCode]);
        $rows = $stmt->fetchAll();
        if (is_array($rows) && !empty($rows)) {
            return $rows;
        }

        return [
            ['location_code' => 'L01', 'location_name' => 'Default Location'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function listShelves(string $warehouseCode, string $locationCode): array
    {
        $warehouseCode = $this->normalizeCode($warehouseCode);
        $locationCode = $this->normalizeCode($locationCode);
        if ($warehouseCode === '' || $locationCode === '') {
            return [];
        }

        if ($this->tableExists('erp_warehouse_shelf')) {
            $stmt = $this->pdo->prepare(
                'SELECT shelf_code, shelf_name
                 FROM erp_warehouse_shelf
                 WHERE warehouse_code = :warehouse_code
                   AND location_code = :location_code
                   AND is_active = 1
                 ORDER BY sort_no, shelf_code'
            );
            $stmt->execute([
                'warehouse_code' => $warehouseCode,
                'location_code' => $locationCode,
            ]);
            $rows = $stmt->fetchAll();
            if (is_array($rows) && !empty($rows)) {
                return $rows;
            }
        }

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT shelf_code, shelf_code AS shelf_name
             FROM stockcard
             WHERE warehouse_code = :warehouse_code
               AND location_code = :location_code
               AND shelf_code IS NOT NULL
               AND TRIM(shelf_code) <> \'\'
             ORDER BY shelf_code'
        );
        $stmt->execute([
            'warehouse_code' => $warehouseCode,
            'location_code' => $locationCode,
        ]);
        $rows = $stmt->fetchAll();
        if (is_array($rows) && !empty($rows)) {
            return $rows;
        }

        return [
            ['shelf_code' => 'S01', 'shelf_name' => 'Default Shelf'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function productById(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, product_code, product_name, product_shotname, reference_code, product_auto_code
             FROM product
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $productId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function resolveProduct(string $barcode): ?array
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $params = [
            'barcode' => $barcode,
            'id_text' => $barcode,
        ];

        $sql = <<<'SQL'
SELECT id, product_code, product_name, product_shotname, reference_code, product_auto_code
FROM product
WHERE product_code = :barcode
   OR product_auto_code = :barcode
   OR reference_code = :barcode
   OR CAST(id AS CHAR) = :id_text
ORDER BY
   CASE
      WHEN product_code = :barcode THEN 1
      WHEN product_auto_code = :barcode THEN 2
      WHEN reference_code = :barcode THEN 3
      WHEN CAST(id AS CHAR) = :id_text THEN 4
      ELSE 9
   END,
   id DESC
LIMIT 1
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function searchProducts(string $keyword, int $limit = 30): array
    {
        $keyword = trim($keyword);
        $limit = max(1, min(100, $limit));

        if ($keyword === '') {
            $stmt = $this->pdo->query(
                'SELECT id, product_code, product_name, product_shotname, reference_code, product_auto_code
                 FROM product
                 ORDER BY id DESC
                 LIMIT ' . $limit
            );
            $rows = $stmt->fetchAll();
            return is_array($rows) ? $rows : [];
        }

        $like = '%' . $keyword . '%';
        $stmt = $this->pdo->prepare(
            'SELECT id, product_code, product_name, product_shotname, reference_code, product_auto_code
             FROM product
             WHERE product_code LIKE :q
                OR product_name LIKE :q
                OR product_shotname LIKE :q
                OR reference_code LIKE :q
                OR product_auto_code LIKE :q
             ORDER BY
                CASE WHEN product_code = :eq THEN 0 ELSE 1 END,
                id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([
            'q' => $like,
            'eq' => $keyword,
        ]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, float> */
    public function stockSummary(int $productId, string $warehouseCode = '', string $locationCode = '', string $shelfCode = ''): array
    {
        if ($productId <= 0) {
            return [
                'overall_qty' => 0.0,
                'slot_qty' => 0.0,
            ];
        }

        $overallQty = $this->balanceOverall($productId);
        $slotQty = 0.0;
        $warehouseCode = $this->normalizeCode($warehouseCode);
        $locationCode = $this->normalizeCode($locationCode);
        $shelfCode = $this->normalizeCode($shelfCode);

        if ($warehouseCode !== '' && $locationCode !== '' && $shelfCode !== '') {
            $slotQty = $this->balanceBySlot($productId, $warehouseCode, $locationCode, $shelfCode);
        }

        return [
            'overall_qty' => round($overallQty, 4),
            'slot_qty' => round($slotQty, 4),
        ];
    }

    /** @param array<string, mixed> $payload
      * @return array<string, mixed>
      */
    public function move(array $payload, string $username): array
    {
        $directionRaw = strtoupper(trim((string)($payload['direction'] ?? '')));
        if (!in_array($directionRaw, ['IN', 'OUT'], true)) {
            throw new RuntimeException('direction must be IN or OUT');
        }

        $qty = (float)($payload['qty'] ?? 0);
        if ($qty <= 0) {
            throw new RuntimeException('qty must be greater than zero');
        }

        $warehouseCode = $this->normalizeCode((string)($payload['warehouse_code'] ?? ''));
        $locationCode = $this->normalizeCode((string)($payload['location_code'] ?? ''));
        $shelfCode = $this->normalizeCode((string)($payload['shelf_code'] ?? ''));
        if ($warehouseCode === '' || $locationCode === '' || $shelfCode === '') {
            throw new RuntimeException('warehouse/location/shelf is required');
        }

        $this->assertSlot($warehouseCode, $locationCode, $shelfCode);

        $productId = (int)($payload['product_id'] ?? 0);
        $product = $productId > 0
            ? $this->productById($productId)
            : $this->resolveProduct((string)($payload['barcode'] ?? ''));
        if (!$product) {
            throw new RuntimeException('product not found');
        }
        $productId = (int)$product['id'];

        $prevOverall = $this->balanceOverall($productId);
        $prevSlot = $this->balanceBySlot($productId, $warehouseCode, $locationCode, $shelfCode);
        $delta = $directionRaw === 'IN' ? $qty : (-1 * $qty);

        if ($directionRaw === 'OUT') {
            if ($prevSlot + 0.000001 < $qty) {
                throw new RuntimeException('insufficient slot qty: available ' . number_format($prevSlot, 4));
            }
            if ($prevOverall + 0.000001 < $qty) {
                throw new RuntimeException('insufficient overall qty: available ' . number_format($prevOverall, 4));
            }
        }

        $newOverall = $prevOverall + $delta;
        $newSlot = $prevSlot + $delta;
        if ($newOverall < -0.000001 || $newSlot < -0.000001) {
            throw new RuntimeException('negative stock is not allowed');
        }

        $billId = 'BCIO-' . date('YmdHis') . '-' . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
        $transType = $directionRaw === 'IN' ? 'BARCODE_IN' : 'BARCODE_OUT';
        $locationStore = $this->extractNumber($locationCode);
        $unitName = trim((string)($payload['unit_name'] ?? 'PCS'));
        if ($unitName === '') {
            $unitName = 'PCS';
        }

        $sql = <<<'SQL'
INSERT INTO stockcard
(`product_id`, `bill_id`, `product_unit_level`, `LOCATION_STORE`, `TRANS_DATE`, `TRANS_TYPE`, `TRANS_ITEM`,
 `TRANS_BALANCE`, `TRANS_ALL_BALANCE`, `TRANS_ALL_LOC_BALANCE`, `PREV_BALANCE`, `PREV_ALL_BALANCE`, `PREV_ALL_LOC_BALANCE`,
 `puser`, `product_unit_name`, `warehouse_code`, `location_code`, `shelf_code`)
VALUES
(:product_id, :bill_id, 1, :location_store, :trans_date, :trans_type, :trans_item,
 :trans_balance, :trans_all_balance, :trans_all_loc_balance, :prev_balance, :prev_all_balance, :prev_all_loc_balance,
 :puser, :product_unit_name, :warehouse_code, :location_code, :shelf_code)
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'product_id' => $productId,
            'bill_id' => $billId,
            'location_store' => $locationStore > 0 ? $locationStore : null,
            'trans_date' => date('Y-m-d H:i:s'),
            'trans_type' => $transType,
            'trans_item' => round($delta, 4),
            'trans_balance' => round($newSlot, 4),
            'trans_all_balance' => round($newOverall, 4),
            'trans_all_loc_balance' => round($newSlot, 4),
            'prev_balance' => round($prevSlot, 4),
            'prev_all_balance' => round($prevOverall, 4),
            'prev_all_loc_balance' => round($prevSlot, 4),
            'puser' => trim($username) !== '' ? trim($username) : 'system',
            'product_unit_name' => $unitName,
            'warehouse_code' => $warehouseCode,
            'location_code' => $locationCode,
            'shelf_code' => $shelfCode,
        ]);

        $id = (int)$this->pdo->lastInsertId();

        return [
            'movement' => [
                'id' => $id,
                'bill_id' => $billId,
                'direction' => $directionRaw,
                'trans_type' => $transType,
                'qty' => round($qty, 4),
                'delta' => round($delta, 4),
                'warehouse_code' => $warehouseCode,
                'location_code' => $locationCode,
                'shelf_code' => $shelfCode,
                'trans_date' => date('Y-m-d H:i:s'),
            ],
            'product' => $product,
            'stock' => [
                'overall_qty' => round($newOverall, 4),
                'slot_qty' => round($newSlot, 4),
                'prev_overall_qty' => round($prevOverall, 4),
                'prev_slot_qty' => round($prevSlot, 4),
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 40): array
    {
        $limit = max(1, min(300, $limit));
        $sql = <<<'SQL'
SELECT
    sc.id,
    sc.bill_id,
    sc.TRANS_DATE AS trans_date,
    sc.TRANS_TYPE AS trans_type,
    sc.TRANS_ITEM AS trans_item,
    sc.TRANS_BALANCE AS trans_balance,
    sc.TRANS_ALL_BALANCE AS trans_all_balance,
    sc.warehouse_code,
    sc.location_code,
    sc.shelf_code,
    sc.puser,
    p.product_code,
    p.product_name
FROM stockcard sc
LEFT JOIN product p ON p.id = sc.product_id
WHERE sc.TRANS_TYPE IN ('BARCODE_IN', 'BARCODE_OUT')
ORDER BY sc.id DESC
LIMIT
SQL;
        $stmt = $this->pdo->prepare($sql . ' ' . $limit);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    private function assertSlot(string $warehouseCode, string $locationCode, string $shelfCode): void
    {
        if ($this->tableExists('erp_warehouse')) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM erp_warehouse WHERE warehouse_code = :warehouse_code AND is_active = 1 LIMIT 1'
            );
            $stmt->execute(['warehouse_code' => $warehouseCode]);
            if ((int)($stmt->fetchColumn() ?? 0) !== 1) {
                throw new RuntimeException('invalid warehouse_code: ' . $warehouseCode);
            }
        }

        if ($this->tableExists('erp_warehouse_location')) {
            $stmt = $this->pdo->prepare(
                'SELECT 1
                 FROM erp_warehouse_location
                 WHERE warehouse_code = :warehouse_code
                   AND location_code = :location_code
                   AND is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([
                'warehouse_code' => $warehouseCode,
                'location_code' => $locationCode,
            ]);
            if ((int)($stmt->fetchColumn() ?? 0) !== 1) {
                throw new RuntimeException('invalid location_code for warehouse ' . $warehouseCode . ': ' . $locationCode);
            }
        }

        if ($this->tableExists('erp_warehouse_shelf')) {
            $stmt = $this->pdo->prepare(
                'SELECT 1
                 FROM erp_warehouse_shelf
                 WHERE warehouse_code = :warehouse_code
                   AND location_code = :location_code
                   AND shelf_code = :shelf_code
                   AND is_active = 1
                 LIMIT 1'
            );
            $stmt->execute([
                'warehouse_code' => $warehouseCode,
                'location_code' => $locationCode,
                'shelf_code' => $shelfCode,
            ]);
            if ((int)($stmt->fetchColumn() ?? 0) !== 1) {
                throw new RuntimeException(
                    'invalid shelf_code for warehouse/location '
                    . $warehouseCode . '/' . $locationCode . ': ' . $shelfCode
                );
            }
        }
    }

    private function balanceOverall(int $productId): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(COALESCE(TRANS_ITEM, 0)), 0)
             FROM stockcard
             WHERE product_id = :product_id'
        );
        $stmt->execute(['product_id' => $productId]);
        return (float)($stmt->fetchColumn() ?? 0);
    }

    private function balanceBySlot(int $productId, string $warehouseCode, string $locationCode, string $shelfCode): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(COALESCE(TRANS_ITEM, 0)), 0)
             FROM stockcard
             WHERE product_id = :product_id
               AND warehouse_code = :warehouse_code
               AND location_code = :location_code
               AND shelf_code = :shelf_code'
        );
        $stmt->execute([
            'product_id' => $productId,
            'warehouse_code' => $warehouseCode,
            'location_code' => $locationCode,
            'shelf_code' => $shelfCode,
        ]);
        return (float)($stmt->fetchColumn() ?? 0);
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    private function extractNumber(string $value): ?int
    {
        if (preg_match('/(\d+)/', $value, $matches) !== 1) {
            return null;
        }

        $parsed = (int)$matches[1];
        return $parsed > 0 ? $parsed : null;
    }

    private function tableExists(string $table): bool
    {
        $key = strtolower(trim($table));
        if ($key === '') {
            return false;
        }

        if (array_key_exists($key, $this->tableExists)) {
            return $this->tableExists[$key];
        }

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name'
        );
        $stmt->execute(['table_name' => $table]);
        $exists = ((int)$stmt->fetchColumn()) > 0;
        $this->tableExists[$key] = $exists;

        return $exists;
    }
}

