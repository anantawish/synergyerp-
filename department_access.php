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

if (!$authService->hasPermission(26)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$pdo = $database->pdo();
$notice = '';
$error = '';

try {
    $pdo->exec(file_get_contents(__DIR__ . '/scripts/migrate_erp_core.sql') ?: '');
    $pdo->exec(file_get_contents(__DIR__ . '/scripts/migrate_user_module_access.sql') ?: '');
} catch (Throwable $e) {
    $error = $e->getMessage();
}

/** @var array<string, array<string, mixed>> $templates */
$templates = [
    'ADMIN' => [
        'label' => 'Admin',
        'allow_all' => true,
        'can_delete' => true,
        'can_report' => true,
    ],
    'PURCHASE' => [
        'label' => 'Purchasing',
        'prefixes' => ['creditor_', 'buy_'],
        'keys' => ['mfg_purchase_req', 'mfg_supply_policy', 'mfg_inventory_snapshot', 'report_buy', 'report_product_balance', 'erp_screen_capture'],
        'can_delete' => false,
        'can_report' => true,
    ],
    'HR' => [
        'label' => 'HR',
        'prefixes' => ['hr_'],
        'keys' => ['erp_screen_capture'],
        'can_delete' => false,
        'can_report' => true,
    ],
    'SALE' => [
        'label' => 'Sales',
        'prefixes' => ['deptor_', 'sale_', 'quotation', 'booking', 'client_receive'],
        'keys' => ['report_sale', 'report_deptor_payment', 'erp_screen_capture'],
        'can_delete' => false,
        'can_report' => true,
    ],
    'ACCOUNT' => [
        'label' => 'Accounting',
        'prefixes' => ['gl_'],
        'keys' => ['report_buy', 'report_sale', 'report_log', 'report_product_activity', 'report_deptor_payment', 'erp_screen_capture'],
        'can_delete' => false,
        'can_report' => true,
    ],
    'POS' => [
        'label' => 'POS',
        'prefixes' => ['sale_cash', 'sale_return'],
        'keys' => ['product_detail', 'stock_card', 'erp_screen_capture'],
        'can_delete' => false,
        'can_report' => false,
    ],
    'WAREHOUSE' => [
        'label' => 'Warehouse',
        'prefixes' => ['product_', 'adjust_', 'stock_', 'transfer_'],
        'keys' => ['mfg_inventory_snapshot', 'mfg_lot', 'mfg_lot_consumption', 'mfg_lot_dispatch', 'report_product_balance', 'report_product_activity', 'erp_screen_capture'],
        'can_delete' => false,
        'can_report' => true,
    ],
    'PRODUCTION' => [
        'label' => 'Production',
        'prefixes' => ['mfg_'],
        'keys' => ['erp_project', 'erp_flow_run', 'erp_flow_step', 'erp_flow_console', 'erp_screen_capture'],
        'can_delete' => false,
        'can_report' => true,
    ],
    'AUDIT' => [
        'label' => 'Audit / QA',
        'prefixes' => ['mfg_report_', 'report_'],
        'keys' => ['erp_flow_run', 'erp_flow_step', 'erp_flow_console', 'erp_screen_capture', 'gl_balance_sheet_report', 'gl_trial_balance_report', 'gl_ledger_report', 'gl_tax_report'],
        'can_delete' => false,
        'can_report' => true,
    ],
];

/** @var array<int, array<string, mixed>> $modules */
$modules = $legacyModuleService->allModules();
$moduleMap = [];
$formIds = [];
foreach ($modules as $module) {
    $key = (string)($module['key'] ?? '');
    if ($key === '') {
        continue;
    }
    $moduleMap[$key] = $module;
    $formId = (int)($module['form_id'] ?? 0);
    if ($formId > 0) {
        $formIds[$formId] = $formId;
    }
}
ksort($formIds);

$users = $pdo->query('SELECT id, username, name FROM unpw ORDER BY username')->fetchAll();
$selectedUserId = (int)($_POST['uid'] ?? $_GET['uid'] ?? ($users[0]['id'] ?? 0));
$selectedDepartment = strtoupper(trim((string)($_POST['department_code'] ?? $_GET['department_code'] ?? '')));

if (($_POST['action'] ?? '') === 'apply_template') {
    try {
        if ($selectedUserId <= 0) {
            throw new RuntimeException('user is required');
        }
        if ($selectedDepartment === '' || !isset($templates[$selectedDepartment])) {
            throw new RuntimeException('department template not found');
        }

        $template = $templates[$selectedDepartment];
        $moduleRights = [];

        foreach ($moduleMap as $moduleKey => $module) {
            $allowed = false;
            if (!empty($template['allow_all'])) {
                $allowed = true;
            } else {
                foreach (($template['prefixes'] ?? []) as $prefix) {
                    if ($prefix !== '' && str_starts_with($moduleKey, (string)$prefix)) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed && in_array($moduleKey, (array)($template['keys'] ?? []), true)) {
                    $allowed = true;
                }
            }

            $readOnly = !empty($module['read_only']);
            $canView = $allowed;
            $canAdd = $allowed && !$readOnly;
            $canEdit = $allowed && !$readOnly;
            $canDelete = $allowed && !$readOnly && !empty($template['can_delete']);
            $canReport = $allowed && !empty($template['can_report']);

            $moduleRights[$moduleKey] = [
                'can_view' => $canView ? 1 : 0,
                'can_add' => $canAdd ? 1 : 0,
                'can_edit' => $canEdit ? 1 : 0,
                'can_delete' => $canDelete ? 1 : 0,
                'can_report' => $canReport ? 1 : 0,
            ];
        }

        $formRights = [];
        foreach ($formIds as $formId) {
            $formRights[$formId] = [
                'permision' => false,
                'addright' => false,
                'editright' => false,
                'deleteright' => false,
            ];
        }
        foreach ($moduleMap as $moduleKey => $module) {
            $formId = (int)($module['form_id'] ?? 0);
            if ($formId <= 0 || !isset($formRights[$formId])) {
                continue;
            }
            $r = $moduleRights[$moduleKey] ?? null;
            if (!$r) {
                continue;
            }
            $formRights[$formId]['permision'] = $formRights[$formId]['permision'] || ($r['can_view'] === 1);
            $formRights[$formId]['addright'] = $formRights[$formId]['addright'] || ($r['can_add'] === 1);
            $formRights[$formId]['editright'] = $formRights[$formId]['editright'] || ($r['can_edit'] === 1);
            $formRights[$formId]['deleteright'] = $formRights[$formId]['deleteright'] || ($r['can_delete'] === 1);
        }

        $pdo->beginTransaction();

        $stmtDept = $pdo->prepare(
            'INSERT INTO erp_user_department (user_tid, department_code, assigned_by)
             VALUES (:uid, :department_code, :assigned_by)
             ON DUPLICATE KEY UPDATE
                department_code = VALUES(department_code),
                assigned_by = VALUES(assigned_by)'
        );
        $stmtDept->execute([
            'uid' => $selectedUserId,
            'department_code' => $selectedDepartment,
            'assigned_by' => (string)($authService->user()['username'] ?? ''),
        ]);

        $pdo->prepare('DELETE FROM user_access WHERE user_tid = :uid')->execute(['uid' => $selectedUserId]);
        $stmtAccess = $pdo->prepare(
            'INSERT INTO user_access (user_tid, form_id, permision, addright, editright, deleteright, editprice, editdiscount)
             VALUES (:uid, :form_id, :permision, :addright, :editright, :deleteright, :editprice, :editdiscount)'
        );
        foreach ($formRights as $formId => $r) {
            $stmtAccess->execute([
                'uid' => $selectedUserId,
                'form_id' => $formId,
                'permision' => $r['permision'] ? 'true' : 'false',
                'addright' => $r['addright'] ? 'true' : 'false',
                'editright' => $r['editright'] ? 'true' : 'false',
                'deleteright' => $r['deleteright'] ? 'true' : 'false',
                'editprice' => 'false',
                'editdiscount' => 'false',
            ]);
        }

        $pdo->prepare('DELETE FROM user_module_access WHERE user_tid = :uid')->execute(['uid' => $selectedUserId]);
        $stmtModule = $pdo->prepare(
            'INSERT INTO user_module_access (user_tid, module_key, can_view, can_add, can_edit, can_delete, can_report)
             VALUES (:uid, :module_key, :can_view, :can_add, :can_edit, :can_delete, :can_report)'
        );
        foreach ($moduleRights as $moduleKey => $r) {
            $stmtModule->execute([
                'uid' => $selectedUserId,
                'module_key' => $moduleKey,
                'can_view' => $r['can_view'],
                'can_add' => $r['can_add'],
                'can_edit' => $r['can_edit'],
                'can_delete' => $r['can_delete'],
                'can_report' => $r['can_report'],
            ]);
        }

        $pdo->commit();
        $notice = 'Applied department template: ' . $selectedDepartment;
        if ((int)($authService->user()['id'] ?? 0) === $selectedUserId) {
            $authService->refreshPermissionCache();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$departmentMap = [];
$deptRows = $pdo->query('SELECT department_code, department_name FROM erp_department WHERE is_active = 1 ORDER BY department_code')->fetchAll();
foreach ($deptRows as $d) {
    $departmentMap[(string)$d['department_code']] = (string)$d['department_name'];
}

$userDeptMap = [];
$userDeptRows = $pdo->query('SELECT user_tid, department_code FROM erp_user_department')->fetchAll();
foreach ($userDeptRows as $r) {
    $userDeptMap[(int)$r['user_tid']] = (string)$r['department_code'];
}

$summaries = [];
foreach ($users as $u) {
    $uid = (int)$u['id'];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_module_access WHERE user_tid = :uid AND can_view = 1');
    $stmt->execute(['uid' => $uid]);
    $viewCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_module_access WHERE user_tid = :uid AND can_add = 1');
    $stmt->execute(['uid' => $uid]);
    $addCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_module_access WHERE user_tid = :uid AND can_edit = 1');
    $stmt->execute(['uid' => $uid]);
    $editCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_module_access WHERE user_tid = :uid AND can_delete = 1');
    $stmt->execute(['uid' => $uid]);
    $deleteCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM user_module_access WHERE user_tid = :uid AND can_report = 1');
    $stmt->execute(['uid' => $uid]);
    $reportCount = (int)$stmt->fetchColumn();

    $deptCode = $userDeptMap[$uid] ?? '';
    $summaries[] = [
        'uid' => $uid,
        'username' => (string)$u['username'],
        'name' => (string)($u['name'] ?? ''),
        'department_code' => $deptCode,
        'department_name' => $departmentMap[$deptCode] ?? '',
        'view_count' => $viewCount,
        'add_count' => $addCount,
        'edit_count' => $editCount,
        'delete_count' => $deleteCount,
        'report_count' => $reportCount,
    ];
}

?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Department Access | SynergyERP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/global-menu.css">
    <style>
        body { background: #f2f6fb; }
        .wrap { max-width: 1600px; margin: 0 auto; padding: 14px; }
        .table th { white-space: nowrap; font-size: .85rem; }
        .table td { vertical-align: middle; font-size: .85rem; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="m-0">Department Permission Templates</h3>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="index.php?page=dashboard">Dashboard</a>
            <a class="btn btn-sm btn-outline-primary" href="admin_access.php">Admin Rights</a>
            <a class="btn btn-sm btn-outline-primary" href="erp_flow.php">ERP Flow</a>
            <a class="btn btn-sm btn-outline-primary" href="capture_screen.php">Screen Capture</a>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success py-2"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header py-2"><strong>Apply Department Template</strong></div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="apply_template">
                <div class="col-md-4">
                    <label class="form-label mb-1">User</label>
                    <select class="form-select form-select-sm" name="uid" required>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $selectedUserId ? 'selected' : '' ?>>
                                <?= h((string)$u['username']) ?> - <?= h((string)($u['name'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1">Department Template</label>
                    <select class="form-select form-select-sm" name="department_code" required>
                        <option value="">-- select department --</option>
                        <?php foreach ($templates as $code => $tpl): ?>
                            <option value="<?= h($code) ?>" <?= $selectedDepartment === $code ? 'selected' : '' ?>>
                                <?= h($code . ' - ' . (string)($tpl['label'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary">Apply Template</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header py-2"><strong>User Permission Summary</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>View</th>
                        <th>Add</th>
                        <th>Edit</th>
                        <th>Delete</th>
                        <th>Report</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($summaries as $s): ?>
                        <tr>
                            <td><?= h($s['username']) ?></td>
                            <td><?= h($s['name']) ?></td>
                            <td><?= h($s['department_code']) ?> <?= $s['department_name'] !== '' ? ('- ' . h($s['department_name'])) : '' ?></td>
                            <td><?= (int)$s['view_count'] ?></td>
                            <td><?= (int)$s['add_count'] ?></td>
                            <td><?= (int)$s['edit_count'] ?></td>
                            <td><?= (int)$s['delete_count'] ?></td>
                            <td><?= (int)$s['report_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="assets/global-menu.js"></script>
</body>
</html>
