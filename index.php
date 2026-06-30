<?php

require __DIR__ . '/bootstrap.php';

/** @param mixed $value */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<int, array<string, mixed>> $groups
 * @return int
 */
function countModules(array $groups): int
{
    $count = 0;
    foreach ($groups as $group) {
        $items = $group['items'] ?? [];
        if (is_array($items)) {
            $count += count($items);
        }
    }
    return $count;
}


function labelFromKey(string $key): string
{
    $label = trim(str_replace('_', ' ', $key));
    return $label === '' ? '' : ucwords($label);
}

function hasLatinLetters(string $text): bool
{
    return preg_match('/[A-Za-z]/', $text) === 1;
}

function hasThaiLetters(string $text): bool
{
    return preg_match('/\p{Thai}/u', $text) === 1;
}

function moduleLabelOverride(string $key): string
{
    static $map = [
        'report_log' => 'รายงานบันทึกระบบ / Log Report',
        'setup_server' => 'ตั้งค่าเซิร์ฟเวอร์ / Setup Server',
        'erp_project' => 'โครงการ ERP / ERP Projects',
        'erp_flow_run' => 'รอบการทำงานโฟลว์ ERP / ERP Flow Runs',
        'erp_flow_step' => 'ขั้นตอนโฟลว์ ERP / ERP Flow Steps',
        'erp_flow_console' => 'คอนโซลโฟลว์ ERP / ERP Flow Console',
        'erp_department' => 'แผนก / Departments',
        'erp_user_department' => 'ผู้ใช้ตามแผนก / User Departments',
        'erp_department_access' => 'เทมเพลตสิทธิ์แผนก / Department Permission Templates',
        'erp_screen_capture' => 'บันทึกการจับภาพหน้าจอ / Screen Capture Log',
        'warehouse_master' => 'คลังสินค้า / Warehouse Master',
        'warehouse_location' => 'ตำแหน่งคลัง / Warehouse Location',
        'warehouse_shelf' => 'ชั้นวางสินค้า / Warehouse Shelf',
        'mfg_item_master' => 'ข้อมูลสินค้าเพื่อการผลิต / Item Master (MFG)',
        'mfg_bom_header' => 'หัว BOM (แยกเวอร์ชัน) / BOM Header (Versioned)',
        'mfg_bom_line' => 'รายการ BOM / BOM Lines',
        'mfg_routing_header' => 'หัวเส้นทางการผลิต (แยกเวอร์ชัน) / Routing Header (Versioned)',
        'mfg_routing_step' => 'ขั้นตอนการผลิต / Routing Steps',
        'mfg_work_center' => 'ศูนย์งาน / Work Centers',
        'mfg_work_center_alt' => 'ศูนย์งานทางเลือก / Alternative Work Centers',
        'mfg_center_calendar' => 'ปฏิทินศูนย์งาน / Work Center Calendar',
        'mfg_setup_matrix' => 'เมทริกซ์การตั้งเครื่อง (เปลี่ยนงาน) / Setup Matrix (Changeover)',
        'mfg_center_shift' => 'กะการทำงานศูนย์งาน / Work Center Shifts',
        'mfg_production_order' => 'ใบสั่งผลิต / Production Orders',
        'mfg_aps_schedule' => 'กระดานตาราง APS / APS Schedule Board',
        'mfg_supply_policy' => 'นโยบายอุปทาน (ROP/JIT) / Supply Policy (ROP/JIT)',
        'mfg_forecast' => 'พยากรณ์ความต้องการ / Demand Forecast',
        'mfg_material_reservation' => 'จองวัตถุดิบ / Material Reservation',
        'mfg_purchase_req' => 'ใบขอซื้อ / Purchase Requisition',
        'mfg_inventory_snapshot' => 'ภาพรวมสินค้าคงคลัง / Inventory Snapshot',
        'mfg_iot_device' => 'อุปกรณ์ IoT / IoT Devices',
        'mfg_iot_log' => 'บันทึกเซนเซอร์ IoT / IoT Sensor Logs',
        'mfg_job_sheet' => 'ใบงานดิจิทัล / Digital Job Sheets',
        'mfg_lot' => 'ล็อตการผลิต / Production Lots',
        'mfg_lot_consumption' => 'การใช้ล็อต / Lot Consumption',
        'mfg_lot_dispatch' => 'จ่ายออกล็อต / Lot Dispatch',
        'mfg_qms_plan' => 'แผนการตรวจสอบ / Inspection Plans',
        'mfg_qms_result' => 'ผลการตรวจสอบ / Inspection Results',
        'mfg_asset_machine' => 'เครื่องจักรและสินทรัพย์ / Machines & Assets',
        'mfg_runtime_log' => 'บันทึกเวลาทำงาน / Runtime Logs',
        'mfg_maintenance_plan' => 'แผนซ่อมบำรุง / Maintenance Plans',
        'mfg_maintenance_wo' => 'ใบงานซ่อมบำรุง / Maintenance Work Orders',
        'mfg_dashboard' => 'แดชบอร์ดเรียลไทม์ / Realtime Dashboard',
        'mfg_report_bom_explosion' => 'รายละเอียดโครงสร้างผลิตภัณฑ์ BOM / Product BOM Structure Details',
        'mfg_report_routing_plan' => 'เส้นทางผลิตและศูนย์งานทางเลือก / Routing & Alternative WC',
        'mfg_report_resource_utilization' => 'การใช้ทรัพยากร / Resource Utilization',
        'mfg_report_trace_backward' => 'ติดตามย้อนหลัง / Traceability Backward',
        'mfg_report_trace_forward' => 'ติดตามไปข้างหน้า / Traceability Forward',
        'mfg_report_spc' => 'รายงานควบคุม SPC / SPC Control Report',
        'mfg_report_maintenance_due' => 'ครบกำหนดซ่อมบำรุงเชิงป้องกัน / Preventive Maintenance Due',
        'mfg_report_maintenance_risk' => 'ความเสี่ยงซ่อมบำรุงเชิงคาดการณ์ / Predictive Maintenance Risk',
        'mfg_report_inventory_reorder' => 'จุดสั่งซื้ออัตโนมัติ / Auto Reorder Point',
        'mfg_report_jit_plan' => 'แผน JIT / JIT Plan',
    ];
    return $map[$key] ?? '';
}

function groupLabelOverride(string $key): string
{
    static $map = [
        'erp_project_flow' => 'โครงการและโฟลว์ ERP / ERP Project & Flow',
        'erp_admin_dept' => 'สิทธิ์การเข้าถึงตามแผนก ERP / ERP Department Access',
        'erp_audit_capture' => 'ตรวจสอบและหลักฐาน ERP / ERP Audit & Evidence',
        'mfg_engineering' => 'วิศวกรรมการผลิต / MFG Engineering',
        'mfg_planning' => 'วางแผนการผลิต (APS) / MFG Planning (APS)',
        'mfg_shopfloor' => 'หน้างานผลิตและ IoT / MFG Shop Floor & IoT',
        'mfg_quality' => 'คุณภาพการผลิต (QMS) / MFG Quality (QMS)',
        'mfg_maintenance' => 'ซ่อมบำรุงการผลิต / MFG Maintenance',
        'mfg_reports' => 'รายงานการผลิต / MFG Reports',
    ];
    return $map[$key] ?? '';
}

/** @param array<string, mixed> $item */
function moduleLabel(array $item): string
{
    $title = trim((string)($item['title'] ?? ''));
    $key = (string)($item['key'] ?? '');
    $fallback = labelFromKey($key);

    if ($title === '') {
        $override = moduleLabelOverride($key);
        if ($override !== '') {
            return $override;
        }
        return $fallback;
    }

    if (!hasThaiLetters($title)) {
        $override = moduleLabelOverride($key);
        if ($override !== '') {
            return $override;
        }
    }

    if ($fallback === '' || hasLatinLetters($title)) {
        return $title;
    }

    return $title . ' / ' . $fallback;
}

/** @param array<string, mixed> $group */
function groupLabel(array $group): string
{
    $title = trim((string)($group['group_title'] ?? ''));
    $key = (string)($group['group_key'] ?? '');
    $fallback = labelFromKey($key);

    if ($title === '') {
        $override = groupLabelOverride($key);
        if ($override !== '') {
            return $override;
        }
        return $fallback;
    }

    if (!hasThaiLetters($title)) {
        $override = groupLabelOverride($key);
        if ($override !== '') {
            return $override;
        }
    }

    if ($fallback === '' || hasLatinLetters($title)) {
        return $title;
    }

    return $title . ' / ' . $fallback;
}

/** @return array{0:string,1:string} */
function splitBilingualLabel(string $label, string $fallbackEnglish = ''): array
{
    $parts = explode('/', $label, 2);
    if (count($parts) === 2) {
        $th = trim($parts[0]);
        $en = trim($parts[1]);
        return [$th, $en !== '' ? $en : $fallbackEnglish];
    }

    $hasThai = hasThaiLetters($label);
    $hasLatin = hasLatinLetters($label);
    $text = trim($label);

    if ($hasThai && !$hasLatin) {
        return [$text, $fallbackEnglish];
    }
    if (!$hasThai && $hasLatin) {
        return [$fallbackEnglish, $text];
    }

    return [$text, $fallbackEnglish];
}

$loginError = '';
if (($_POST['action'] ?? '') === 'login') {
    $username = (string)($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($authService->login($username, $password)) {
        header('Location: index.php');
        exit;
    }

    $loginError = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง / Invalid username or password';
}

if (($_GET['action'] ?? '') === 'logout') {
    $authService->logout();
    header('Location: index.php');
    exit;
}

if (!$authService->isLoggedIn()) {
    ?>
    <!doctype html>
    <html lang="th">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($config['app_name'] ?? 'SynergyERP') ?> Login</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        <style>
            body { background: #0f2230; min-height: 100vh; display: grid; place-items: center; }
            .login-card { width: min(420px, 92vw); border: 0; border-radius: 14px; }
        </style>
    </head>
    <body>
        <div class="card login-card shadow-lg">
            <div class="card-body p-4">
                <h3 class="mb-1"><?= h($config['app_name'] ?? 'SynergyERP') ?></h3>
                <p class="text-muted mb-3">Legacy 1:1 Mode</p>
                <?php if ($loginError !== ''): ?>
                    <div class="alert alert-danger py-2"><?= h($loginError) ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input class="form-control" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input class="form-control" name="password" type="password" required>
                    </div>
                    <button class="btn btn-primary w-100">Sign In</button>
                </form>
                <div class="small text-muted mt-3">Default sample user from old DB: <code>222 / 222</code></div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$user = $authService->user();
$menuGroups = $legacyModuleService->groupsForAuth($authService);

$page = (string)($_GET['page'] ?? 'dashboard');
$moduleKey = (string)($_GET['module'] ?? '');

$module = null;
$moduleError = '';
$modulePermissions = null;

$mainColumns = [];
$mainPrimaryKey = '';
$detailColumns = [];
$detailPrimaryKey = '';

if ($page === 'module') {
    $module = $legacyModuleService->find($moduleKey);

    if (!$module) {
        $moduleError = 'ไม่พบโมดูลที่เลือก / Module not found';
    } else {
        $formId = (int)($module['form_id'] ?? 0);
        $moduleAuthKey = (string)($module['key'] ?? '');
        if (!$authService->hasModulePermission($moduleAuthKey, $formId)) {
            $moduleError = 'ไม่มีสิทธิ์เข้าใช้งานโมดูลนี้ / Access denied for this module';
        } else {
            $modulePermissions = $authService->moduleRights($moduleAuthKey, $formId);
            try {
                $legacyModuleService->validateModule($module, $schemaService);

                if (($module['mode'] ?? '') !== 'placeholder') {
                    $mainTable = (string)$module['main_table'];
                    $mainColumns = $schemaService->listColumns($mainTable);
                    $mainPrimaryKey = $schemaService->getPrimaryKey($mainTable);

                    if (($module['mode'] ?? '') === 'master_detail') {
                        $detailTable = (string)$module['detail_table'];
                        $detailColumns = $schemaService->listColumns($detailTable);
                        $detailPrimaryKey = $schemaService->getPrimaryKey($detailTable);
                    }
                }
            } catch (Throwable $e) {
                $moduleError = $e->getMessage();
            }
        }
    }
}

$appName = $config['app_name'] ?? 'SynergyERP';
$moduleCount = countModules($menuGroups);
$moduleTitle = $module !== null ? moduleLabel($module) : 'Module';

?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($appName) ?></title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h1><?= h($appName) ?></h1>
            <small>Master Modules</small>
        </div>

        <nav class="menu-wrap">
            <a class="menu-item <?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard">
                <span class="menu-line-th">แดชบอร์ด</span>
                <span class="menu-line-en">Dashboard</span>
            </a>
            <?php if ($authService->hasPermission(22)): ?>
                <a class="menu-item" href="erp_flow.php">
                    <span class="menu-line-th">คอนโซลโฟลว์ ERP</span>
                    <span class="menu-line-en">ERP Flow Console</span>
                </a>
                <a class="menu-item" href="capture_screen.php">
                    <span class="menu-line-th">บันทึกภาพหน้าจอ</span>
                    <span class="menu-line-en">Screen Capture Log</span>
                </a>
                <?php if ($authService->hasModulePermission('transfer_stock', 5)): ?>
                    <a class="menu-item" href="barcode_io.php">
                        <span class="menu-line-th">ยิงบาร์โค้ดเข้าออก</span>
                        <span class="menu-line-en">Barcode In-Out</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($authService->hasPermission(26)): ?>
                <a class="menu-item" href="admin_access.php">
                    <span class="menu-line-th">สิทธิ์ผู้ดูแลระบบ</span>
                    <span class="menu-line-en">Admin Access</span>
                </a>
                <a class="menu-item" href="department_access.php">
                    <span class="menu-line-th">สิทธิ์ตามแผนก</span>
                    <span class="menu-line-en">Department Access</span>
                </a>
            <?php endif; ?>
            <a class="menu-item" href="docs/user_manual_departments.html" target="_blank">
                <span class="menu-line-th">คู่มือแยกตามแผนก</span>
                <span class="menu-line-en">Manual by Department</span>
            </a>
            <a class="menu-item" href="docs/user_manual_full.html" target="_blank">
                <span class="menu-line-th">คู่มือการใช้งาน</span>
                <span class="menu-line-en">User Manual</span>
            </a>

            <div class="menu-section-title">ค้นหาเมนู / Search Menu</div>
            <input id="tableFilter" class="form-control form-control-sm mb-2" placeholder="ค้นหาเมนูหรือโมดูล / Search menu or module..." />

            <?php foreach ($menuGroups as $group): ?>
                <div class="menu-group">
                    <?php [$groupTh, $groupEn] = splitBilingualLabel(groupLabel($group), labelFromKey((string)($group['group_key'] ?? ''))); ?>
                    <div class="menu-group-title">
                        <span class="menu-line-th"><?= h($groupTh) ?></span>
                        <span class="menu-line-en"><?= h($groupEn) ?></span>
                    </div>
                    <?php foreach (($group['items'] ?? []) as $item): ?>
                        <?php $isActive = $page === 'module' && $moduleKey === ($item['key'] ?? ''); ?>
                        <?php $itemLabel = moduleLabel($item); ?>
                        <?php [$itemTh, $itemEn] = splitBilingualLabel($itemLabel, labelFromKey((string)($item['key'] ?? ''))); ?>
                        <a class="menu-item table-item <?= $isActive ? 'active' : '' ?>"
                           data-table-name="<?= h($itemLabel) ?>"
                           href="?page=module&amp;module=<?= urlencode((string)($item['key'] ?? '')) ?>">
                            <span class="menu-line-th"><?= h($itemTh) ?></span>
                            <span class="menu-line-en"><?= h($itemEn) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <small>User: <?= h($user['username'] ?? '') ?></small>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <div>
                <h2 class="m-0">
                    <?php if ($page === 'dashboard'): ?>
                        Dashboard
                    <?php else: ?>
                        <?= h($moduleTitle) ?>
                    <?php endif; ?>
                </h2>
                <small class="text-muted">หน้าจอเดิมจากระบบ Legacy (MDIParent1) แบบ 1:1 / Legacy UI mapped from MDIParent1</small>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-secondary btn-sm" id="toggleSidebar">Menu</button>
                <a class="btn btn-outline-danger btn-sm" href="?action=logout">Logout</a>
            </div>
        </header>

        <?php if ($page === 'dashboard'): ?>
            <section class="dashboard-grid">
                <div class="card metric-card">
                    <div class="card-body">
                        <div class="metric-label">Modules</div>
                        <div class="metric-value"><?= number_format($moduleCount) ?></div>
                    </div>
                </div>
                <div class="card metric-card">
                    <div class="card-body">
                        <div class="metric-label">Groups</div>
                        <div class="metric-value"><?= number_format(count($menuGroups)) ?></div>
                    </div>
                </div>
                <div class="card metric-card">
                    <div class="card-body">
                        <div class="metric-label">Program</div>
                        <div class="metric-value">SynergyERP</div>
                    </div>
                </div>
                <div class="card metric-card">
                    <div class="card-body">
                        <div class="metric-label">Server</div>
                        <div class="metric-value">localhost:888</div>
                    </div>
                </div>
            </section>

            <section class="card mt-3">
                <div class="card-header"><strong>Master Modules</strong></div>
                <div class="card-body">
                    <div class="row g-2">
                        <?php if ($authService->hasModulePermission('transfer_stock', 5)): ?>
                            <div class="col-6 col-md-4 col-xl-3">
                                <a class="btn btn-light w-100 text-start" href="barcode_io.php">
                                    ยิงบาร์โค้ดเข้าออก / Barcode In-Out
                                </a>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($menuGroups as $group): ?>
                            <?php foreach (($group['items'] ?? []) as $item): ?>
                                <div class="col-6 col-md-4 col-xl-3">
                                    <a class="btn btn-light w-100 text-start" href="?page=module&amp;module=<?= urlencode((string)$item['key']) ?>">
                                        <?= h(moduleLabel($item)) ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php else: ?>
            <?php if ($moduleError !== ''): ?>
                <div class="alert alert-danger"><?= h($moduleError) ?></div>
            <?php elseif (($module['mode'] ?? '') === 'placeholder'): ?>
                <div class="alert alert-warning">
                    <strong><?= h($moduleTitle) ?></strong><br>
                    <?= h($module['message'] ?? 'ยังไม่มีข้อความของโมดูลนี้ / No module message') ?>
                </div>
            <?php else: ?>
                <section class="card mb-3">
                    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                        <span class="badge text-bg-info">Form ID: <?= (int)($module['form_id'] ?? 0) ?></span>
                        <span class="badge text-bg-secondary">Main: <?= h($module['main_table'] ?? '') ?></span>
                        <?php if (($module['mode'] ?? '') === 'master_detail'): ?>
                            <span class="badge text-bg-secondary">Detail: <?= h($module['detail_table'] ?? '') ?></span>
                        <?php endif; ?>
                        <button class="btn btn-primary btn-sm" id="btnAddMain">Add Main</button>
                        <button class="btn btn-outline-secondary btn-sm" id="btnRefreshMain">Refresh</button>
                        <button class="btn btn-outline-dark btn-sm" id="btnSummary">Summary</button>
                        <button class="btn btn-outline-primary btn-sm" id="btnReportList">List Report</button>
                        <button class="btn btn-outline-primary btn-sm" id="btnReportSelected">Selected Doc</button>
                        <?php if (($module['mode'] ?? '') === 'master_detail'): ?>
                            <button class="btn btn-outline-success btn-sm" id="btnQuickProcess">Run Mock Process</button>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="row g-3 mb-3" id="summaryCards"></section>

                <section class="card mb-3">
                    <div class="card-header d-flex justify-content-between">
                        <strong>รายการหลัก / Main Records</strong>
                        <small class="text-muted">Export PDF ได้จาก toolbar / Export PDF from toolbar</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="mainGrid" class="table table-striped table-bordered table-sm nowrap w-100"></table>
                        </div>
                    </div>
                </section>

                <?php if (($module['mode'] ?? '') === 'master_detail'): ?>
                    <section class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <strong>รายการย่อย / Detail Records</strong>
                            <div class="d-flex gap-2">
                                <span class="badge text-bg-warning" id="detailContext">ยังไม่ได้เลือกรายการหลัก / Select a main record first</span>
                                <button class="btn btn-sm btn-primary" id="btnAddDetail">เพิ่มรายการย่อย / Add Detail</button>
                                <button class="btn btn-sm btn-outline-secondary" id="btnRefreshDetail">Refresh</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="detailGrid" class="table table-striped table-bordered table-sm nowrap w-100"></table>
                            </div>
                        </div>
                    </section>
                <?php endif; ?>

                <div class="modal fade" id="mainModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="mainModalLabel">รายการหลัก / Main Record</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body"><form id="mainForm" class="row g-2"></form></div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" class="btn btn-primary" id="btnSaveMain">Save</button>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (($module['mode'] ?? '') === 'master_detail'): ?>
                    <div class="modal fade" id="detailModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="detailModalLabel">รายการย่อย / Detail Record</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body"><form id="detailForm" class="row g-2"></form></div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-primary" id="btnSaveDetail">Save</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <footer class="footer mt-3">
            <small>SynergyERP legacy 1:1 | localhost:888/stock2 | user_access right control</small>
        </footer>
    </main>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
window.stock2Config = {
    page: <?= json_encode($page, JSON_UNESCAPED_UNICODE) ?>,
    module: <?= json_encode($module, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    modulePermissions: <?= json_encode($modulePermissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    moduleError: <?= json_encode($moduleError, JSON_UNESCAPED_UNICODE) ?>,
    mainColumns: <?= json_encode($mainColumns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    mainPrimaryKey: <?= json_encode($mainPrimaryKey) ?>,
    detailColumns: <?= json_encode($detailColumns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    detailPrimaryKey: <?= json_encode($detailPrimaryKey) ?>,
    apiBase: 'api/table.php',
    processApiBase: 'api/process.php',
    reportBase: 'report.php'
};
</script>
<script src="assets/app.js"></script>
</body>
</html>


