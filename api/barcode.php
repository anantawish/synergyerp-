<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../bootstrap.php';

/** @return array<string, mixed> */
function payload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

/** @param array<string, mixed> $data */
function out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!$authService->isLoggedIn()) {
        out(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    if (!$authService->hasModulePermission('transfer_stock', 5)) {
        out(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $action = strtolower((string)($_GET['action'] ?? ''));
    if ($action === '') {
        throw new RuntimeException('action is required');
    }

    $input = payload();

    switch ($action) {
        case 'options':
            $warehouseCode = trim((string)($_GET['warehouse_code'] ?? $input['warehouse_code'] ?? ''));
            $locationCode = trim((string)($_GET['location_code'] ?? $input['location_code'] ?? ''));

            $warehouses = $inventoryBarcodeService->listWarehouses();
            if ($warehouseCode === '' && !empty($warehouses)) {
                $warehouseCode = (string)$warehouses[0]['warehouse_code'];
            }

            $locations = $inventoryBarcodeService->listLocations($warehouseCode);
            if ($locationCode === '' && !empty($locations)) {
                $locationCode = (string)$locations[0]['location_code'];
            }

            $shelves = $inventoryBarcodeService->listShelves($warehouseCode, $locationCode);
            $shelfCode = trim((string)($_GET['shelf_code'] ?? $input['shelf_code'] ?? ''));
            if ($shelfCode === '' && !empty($shelves)) {
                $shelfCode = (string)$shelves[0]['shelf_code'];
            }

            out([
                'ok' => true,
                'result' => [
                    'warehouses' => $warehouses,
                    'locations' => $locations,
                    'shelves' => $shelves,
                    'selected' => [
                        'warehouse_code' => $warehouseCode,
                        'location_code' => $locationCode,
                        'shelf_code' => $shelfCode,
                    ],
                ],
            ]);
            break;

        case 'search_product':
            $keyword = (string)($_GET['q'] ?? $_GET['keyword'] ?? $input['q'] ?? $input['keyword'] ?? '');
            $limit = (int)($_GET['limit'] ?? $input['limit'] ?? 30);
            $rows = $inventoryBarcodeService->searchProducts($keyword, $limit);
            out([
                'ok' => true,
                'rows' => $rows,
            ]);
            break;

        case 'resolve_product':
            $productId = (int)($_GET['product_id'] ?? $input['product_id'] ?? 0);
            $code = (string)($_GET['code'] ?? $_GET['barcode'] ?? $input['code'] ?? $input['barcode'] ?? '');
            $warehouseCode = (string)($_GET['warehouse_code'] ?? $input['warehouse_code'] ?? '');
            $locationCode = (string)($_GET['location_code'] ?? $input['location_code'] ?? '');
            $shelfCode = (string)($_GET['shelf_code'] ?? $input['shelf_code'] ?? '');

            $product = $productId > 0
                ? $inventoryBarcodeService->productById($productId)
                : $inventoryBarcodeService->resolveProduct($code);
            if (!$product) {
                throw new RuntimeException('product not found');
            }

            $stock = $inventoryBarcodeService->stockSummary(
                (int)$product['id'],
                $warehouseCode,
                $locationCode,
                $shelfCode
            );

            out([
                'ok' => true,
                'result' => [
                    'product' => $product,
                    'stock' => $stock,
                ],
            ]);
            break;

        case 'stock':
            $productId = (int)($_GET['product_id'] ?? $input['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new RuntimeException('product_id is required');
            }
            $stock = $inventoryBarcodeService->stockSummary(
                $productId,
                (string)($_GET['warehouse_code'] ?? $input['warehouse_code'] ?? ''),
                (string)($_GET['location_code'] ?? $input['location_code'] ?? ''),
                (string)($_GET['shelf_code'] ?? $input['shelf_code'] ?? '')
            );
            out([
                'ok' => true,
                'result' => $stock,
            ]);
            break;

        case 'move':
            $moveRights = $authService->moduleRights('transfer_stock', 5);
            if (!$moveRights['add']) {
                out(['ok' => false, 'error' => 'forbidden_add'], 403);
            }

            $username = (string)($authService->user()['username'] ?? 'system');
            $result = $inventoryBarcodeService->move($input + $_POST, $username);
            out([
                'ok' => true,
                'result' => $result,
            ]);
            break;

        case 'recent':
            $limit = (int)($_GET['limit'] ?? $input['limit'] ?? 40);
            $rows = $inventoryBarcodeService->recent($limit);
            out([
                'ok' => true,
                'rows' => $rows,
            ]);
            break;

        default:
            throw new RuntimeException('unknown action');
    }
} catch (Throwable $e) {
    out([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);
}

