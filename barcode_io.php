<?php

require __DIR__ . '/bootstrap.php';

/** @param mixed $value */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if (!$authService->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

if (!$authService->hasModulePermission('transfer_stock', 5)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$user = $authService->user();
$rights = $authService->moduleRights('transfer_stock', 5);

?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Barcode In-Out | SynergyERP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/global-menu.css">
    <link rel="stylesheet" href="assets/barcode-io.css">
</head>
<body class="bio-page">
<div class="container-fluid py-3 bio-wrap">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h3 class="m-0">ยิงบาร์โค้ดเข้าออกคลัง / Barcode In-Out</h3>
            <small class="text-muted">โหมดเครื่องยิงบาร์โค้ดต่อ Android เท่านั้น (ไม่ใช้กล้อง)</small>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="index.php?page=dashboard">Dashboard</a>
            <a class="btn btn-outline-primary" href="index.php?page=module&module=transfer_stock">Transfer Module</a>
            <span class="badge text-bg-dark d-inline-flex align-items-center">User: <?= h($user['username'] ?? '') ?></span>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-5">
            <div class="card bio-card h-100">
                <div class="card-header">
                    <strong>ยิงบาร์โค้ดด้วยเครื่องสแกน / Hardware Scanner</strong>
                </div>
                <div class="card-body">
                    <div class="alert alert-info py-2 mb-3">
                        ใช้เครื่องยิงบาร์โค้ดที่ต่อกับสมาร์ทโฟน/แท็บเล็ต แล้วสแกนเข้าช่องด้านล่างได้ทันที
                    </div>
                    <div id="scanStatus" class="small text-muted bio-status mb-2"></div>

                    <label class="form-label fw-semibold">กรอก/ยิงบาร์โค้ดสินค้า</label>
                    <div class="input-group input-group-lg mb-2">
                        <input type="text" class="form-control" id="barcodeInput" placeholder="เช่น prd00027">
                        <button type="button" class="btn btn-primary" id="btnResolveBarcode">ค้นหาสินค้า</button>
                    </div>
                    <small class="text-muted">รองรับเครื่องสแกนที่ส่งท้ายด้วย Enter หรือ Tab และยังพิมพ์ค้นหาเองได้</small>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card bio-card mb-3">
                <div class="card-header">
                    <strong>เลือกสินค้า / Product Selection</strong>
                </div>
                <div class="card-body">
                    <label class="form-label fw-semibold">ค้นหาสินค้า</label>
                    <input type="text" class="form-control form-control-lg mb-2" id="productSearchInput" placeholder="พิมพ์ชื่อหรือรหัสสินค้า...">
                    <div id="productSearchResults" class="search-results mb-3"></div>

                    <div class="bio-product-info">
                        <div class="bio-product-title mb-1" id="productName">-</div>
                        <div class="small text-muted mb-2">Code: <span id="productCode">-</span> | Ref: <span id="productRef">-</span></div>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge text-bg-primary bio-stock-badge">ยอดรวม: <span id="stockOverall">0.00</span></span>
                            <span class="badge text-bg-info bio-stock-badge">ยอดตำแหน่ง: <span id="stockSlot">0.00</span></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card bio-card">
                <div class="card-header">
                    <strong>บันทึกเข้าออก / Post Movement</strong>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Direction</label>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-success touch-btn direction-btn direction-in" id="btnDirectionIn">IN / เข้า</button>
                            <button type="button" class="btn btn-outline-danger touch-btn direction-btn direction-out" id="btnDirectionOut">OUT / ออก</button>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">คลัง</label>
                            <select class="form-select form-select-lg" id="warehouseSelect"></select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Location</label>
                            <select class="form-select form-select-lg" id="locationSelect"></select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">Shelf</label>
                            <select class="form-select form-select-lg" id="shelfSelect"></select>
                        </div>
                    </div>

                    <div class="row g-2">
                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold">จำนวน</label>
                            <input type="number" step="0.0001" min="0.0001" class="form-control form-control-lg" id="qtyInput" value="1">
                        </div>
                        <div class="col-12 col-md-8 d-flex align-items-end">
                            <div class="d-grid gap-2 d-md-flex w-100">
                                <button type="button" class="btn btn-primary touch-btn flex-fill" id="btnPostMove">บันทึกตาม Direction</button>
                                <button type="button" class="btn btn-success touch-btn flex-fill" id="btnQuickIn">Quick IN</button>
                                <button type="button" class="btn btn-danger touch-btn flex-fill" id="btnQuickOut">Quick OUT</button>
                            </div>
                        </div>
                    </div>
                    <?php if (!$rights['add']): ?>
                        <div class="alert alert-warning mt-3 mb-0 py-2">บัญชีนี้ไม่มีสิทธิ์บันทึกเข้าออก (add permission = false)</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card bio-card mt-3">
        <div class="card-header">
            <strong>รายการล่าสุด / Recent Barcode Movements</strong>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-bordered mb-0 recent-table">
                    <thead class="table-light">
                    <tr>
                        <th>Date Time</th>
                        <th>Dir</th>
                        <th>Product Code</th>
                        <th>Product Name</th>
                        <th class="text-end">Qty</th>
                        <th>Slot</th>
                        <th>Doc No</th>
                    </tr>
                    </thead>
                    <tbody id="recentBody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
window.barcodeIoConfig = {
    apiBase: 'api/barcode.php',
    canAdd: <?= json_encode((bool)$rights['add']) ?>
};
</script>
<script src="assets/global-menu.js"></script>
<script src="assets/barcode-io.js"></script>
</body>
</html>
