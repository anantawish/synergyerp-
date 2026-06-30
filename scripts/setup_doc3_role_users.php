<?php

require __DIR__ . '/../bootstrap.php';

$pdo = $database->pdo();

$pdo->exec(file_get_contents(__DIR__ . '/migrate_erp_core.sql') ?: '');
$pdo->exec(file_get_contents(__DIR__ . '/migrate_user_module_access.sql') ?: '');

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

$roleAccounts = [
    'ADMIN' => ['username' => 'doc3_admin', 'password' => 'doc3_admin_2026', 'name' => 'Doc3 Admin'],
    'PURCHASE' => ['username' => 'doc3_purchase', 'password' => 'doc3_purchase_2026', 'name' => 'Doc3 Purchase'],
    'HR' => ['username' => 'doc3_hr', 'password' => 'doc3_hr_2026', 'name' => 'Doc3 HR'],
    'SALE' => ['username' => 'doc3_sale', 'password' => 'doc3_sale_2026', 'name' => 'Doc3 Sale'],
    'ACCOUNT' => ['username' => 'doc3_account', 'password' => 'doc3_account_2026', 'name' => 'Doc3 Account'],
    'POS' => ['username' => 'doc3_pos', 'password' => 'doc3_pos_2026', 'name' => 'Doc3 POS'],
    'WAREHOUSE' => ['username' => 'doc3_warehouse', 'password' => 'doc3_warehouse_2026', 'name' => 'Doc3 Warehouse'],
    'PRODUCTION' => ['username' => 'doc3_production', 'password' => 'doc3_production_2026', 'name' => 'Doc3 Production'],
    'AUDIT' => ['username' => 'doc3_audit', 'password' => 'doc3_audit_2026', 'name' => 'Doc3 Audit'],
];

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

$selectUser = $pdo->prepare('SELECT id FROM unpw WHERE username = :username LIMIT 1');
$insertUser = $pdo->prepare('INSERT INTO unpw (username, password, name, user_level) VALUES (:username, :password, :name, NULL)');
$updateUser = $pdo->prepare('UPDATE unpw SET password = :password, name = :name WHERE id = :id');

$upsertDept = $pdo->prepare(
    'INSERT INTO erp_user_department (user_tid, department_code, assigned_by)
     VALUES (:uid, :department_code, :assigned_by)
     ON DUPLICATE KEY UPDATE
        department_code = VALUES(department_code),
        assigned_by = VALUES(assigned_by)'
);

$delAccess = $pdo->prepare('DELETE FROM user_access WHERE user_tid = :uid');
$insAccess = $pdo->prepare(
    'INSERT INTO user_access (user_tid, form_id, permision, addright, editright, deleteright, editprice, editdiscount)
     VALUES (:uid, :form_id, :permision, :addright, :editright, :deleteright, :editprice, :editdiscount)'
);

$delModule = $pdo->prepare('DELETE FROM user_module_access WHERE user_tid = :uid');
$insModule = $pdo->prepare(
    'INSERT INTO user_module_access (user_tid, module_key, can_view, can_add, can_edit, can_delete, can_report)
     VALUES (:uid, :module_key, :can_view, :can_add, :can_edit, :can_delete, :can_report)'
);

$results = [];
$assignedBy = 'doc3_setup';

foreach ($roleAccounts as $departmentCode => $account) {
    if (!isset($templates[$departmentCode])) {
        continue;
    }

    $selectUser->execute(['username' => $account['username']]);
    $row = $selectUser->fetch();

    if ($row) {
        $uid = (int)$row['id'];
        $updateUser->execute([
            'id' => $uid,
            'password' => $account['password'],
            'name' => $account['name'],
        ]);
    } else {
        $insertUser->execute([
            'username' => $account['username'],
            'password' => $account['password'],
            'name' => $account['name'],
        ]);
        $uid = (int)$pdo->lastInsertId();
    }

    $template = $templates[$departmentCode];
    $moduleRights = [];

    foreach ($moduleMap as $moduleKey => $module) {
        $allowed = false;

        if (!empty($template['allow_all'])) {
            $allowed = true;
        } else {
            foreach (($template['prefixes'] ?? []) as $prefix) {
                $prefix = (string)$prefix;
                if ($prefix !== '' && str_starts_with($moduleKey, $prefix)) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed && in_array($moduleKey, (array)($template['keys'] ?? []), true)) {
                $allowed = true;
            }
        }

        $readOnly = !empty($module['read_only']);

        $moduleRights[$moduleKey] = [
            'can_view' => $allowed ? 1 : 0,
            'can_add' => ($allowed && !$readOnly) ? 1 : 0,
            'can_edit' => ($allowed && !$readOnly) ? 1 : 0,
            'can_delete' => ($allowed && !$readOnly && !empty($template['can_delete'])) ? 1 : 0,
            'can_report' => ($allowed && !empty($template['can_report'])) ? 1 : 0,
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
    try {
        $upsertDept->execute([
            'uid' => $uid,
            'department_code' => $departmentCode,
            'assigned_by' => $assignedBy,
        ]);

        $delAccess->execute(['uid' => $uid]);
        foreach ($formRights as $formId => $r) {
            $insAccess->execute([
                'uid' => $uid,
                'form_id' => $formId,
                'permision' => $r['permision'] ? 'true' : 'false',
                'addright' => $r['addright'] ? 'true' : 'false',
                'editright' => $r['editright'] ? 'true' : 'false',
                'deleteright' => $r['deleteright'] ? 'true' : 'false',
                'editprice' => 'false',
                'editdiscount' => 'false',
            ]);
        }

        $delModule->execute(['uid' => $uid]);
        foreach ($moduleRights as $moduleKey => $r) {
            $insModule->execute([
                'uid' => $uid,
                'module_key' => $moduleKey,
                'can_view' => $r['can_view'],
                'can_add' => $r['can_add'],
                'can_edit' => $r['can_edit'],
                'can_delete' => $r['can_delete'],
                'can_report' => $r['can_report'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $viewCount = 0;
    $addCount = 0;
    $editCount = 0;
    $deleteCount = 0;
    $reportCount = 0;
    foreach ($moduleRights as $r) {
        $viewCount += $r['can_view'];
        $addCount += $r['can_add'];
        $editCount += $r['can_edit'];
        $deleteCount += $r['can_delete'];
        $reportCount += $r['can_report'];
    }

    $results[] = [
        'department_code' => $departmentCode,
        'department_label' => (string)($template['label'] ?? $departmentCode),
        'user_id' => $uid,
        'username' => $account['username'],
        'password' => $account['password'],
        'name' => $account['name'],
        'module_view_count' => $viewCount,
        'module_add_count' => $addCount,
        'module_edit_count' => $editCount,
        'module_delete_count' => $deleteCount,
        'module_report_count' => $reportCount,
    ];
}

$outputPath = __DIR__ . '/../docs/doc3_role_accounts.json';
file_put_contents($outputPath, json_encode([
    'generated_at' => date('c'),
    'source' => 'setup_doc3_role_users.php',
    'roles' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

foreach ($results as $r) {
    echo sprintf(
        "[OK] %s user=%s id=%d view=%d add=%d edit=%d delete=%d report=%d\n",
        $r['department_code'],
        $r['username'],
        $r['user_id'],
        $r['module_view_count'],
        $r['module_add_count'],
        $r['module_edit_count'],
        $r['module_delete_count'],
        $r['module_report_count']
    );
}

echo '[DONE] Wrote ' . $outputPath . PHP_EOL;
