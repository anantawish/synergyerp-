<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Bangkok');

$root = dirname(__DIR__);

/** @param mixed $value */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function looksMojibake(string $text): bool
{
    return preg_match('/Ã|Â|à¸|à¹|àº|à»/u', $text) === 1;
}

function normalizeText(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return $text;
    }

    if (!looksMojibake($text)) {
        return $text;
    }

    $converted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
    if (is_string($converted) && $converted !== '' && strpos($converted, '?') === false) {
        return $converted;
    }

    $converted2 = @iconv('UTF-8', 'Windows-1252//IGNORE', $text);
    if (is_string($converted2) && $converted2 !== '') {
        return $converted2;
    }

    return $text;
}

/**
 * @param array<string, mixed> $item
 */
function moduleUrl(array $item): string
{
    return 'index.php?page=module&module=' . rawurlencode((string)($item['key'] ?? ''));
}

/**
 * @param array<string, mixed> $item
 */
function reportUrl(array $item): string
{
    return 'report.php?module=' . rawurlencode((string)($item['key'] ?? '')) . '&mode=list';
}

$moduleFiles = glob($root . '/config/*_modules.php');
if (!is_array($moduleFiles)) {
    $moduleFiles = [];
}
sort($moduleFiles);

/** @var array<int, array<string, mixed>> $groups */
$groups = [];
foreach ($moduleFiles as $file) {
    $loaded = require $file;
    if (!is_array($loaded)) {
        continue;
    }

    foreach ($loaded as &$group) {
        if (isset($group['group_title'])) {
            $group['group_title'] = normalizeText((string)$group['group_title']);
        }

        if (!isset($group['items']) || !is_array($group['items'])) {
            continue;
        }

        foreach ($group['items'] as &$item) {
            if (isset($item['title'])) {
                $item['title'] = normalizeText((string)$item['title']);
            }
        }
        unset($item);
    }
    unset($group);

    $groups = array_merge($groups, $loaded);
}

$departmentGuides = [
    ['dept' => 'ADMIN', 'scope' => 'Governance, users, rights, setup', 'pages' => 'setup_user, admin_access.php, department_access.php, erp_flow.php, capture_screen.php', 'deliverable' => 'User provisioning and permission matrix'],
    ['dept' => 'PURCHASE', 'scope' => 'Supplier cycle + inbound material', 'pages' => 'buy_order, buy_credit, buy_cash, buy_sendback, creditor_billing, creditor_paid, mfg_purchase_req', 'deliverable' => 'PO, GRN, AP billing/payment'],
    ['dept' => 'WAREHOUSE', 'scope' => 'Stock movement and lot trace', 'pages' => 'stock_card, transfer_stock, mfg_inventory_snapshot, mfg_lot, mfg_lot_dispatch', 'deliverable' => 'Main -> Production -> FG -> Packing flow'],
    ['dept' => 'PRODUCTION', 'scope' => 'BOM/routing, plan, execute, QC', 'pages' => 'mfg_bom_header, mfg_routing_step, mfg_production_order, mfg_aps_schedule, mfg_job_sheet, mfg_qms_result', 'deliverable' => 'Released production lots'],
    ['dept' => 'SALE / POS', 'scope' => 'Order to delivery to billing', 'pages' => 'quotation, booking, client_receive, sale_cash, sale_credit, sale_return', 'deliverable' => 'Sales docs and delivery confirmation'],
    ['dept' => 'ACCOUNT / FINANCE', 'scope' => 'GL, tax, AP/AR, financial report', 'pages' => 'gl_journal, gl_vat_tx, gl_withholding_tx, business_report.php, deptor_paid, creditor_paid', 'deliverable' => 'Balanced ledger and statements'],
    ['dept' => 'HR', 'scope' => 'Attendance, leave, payroll', 'pages' => 'hr_employee, hr_attendance, hr_leave_request, hr_payroll_period, hr_policy', 'deliverable' => 'Payroll-ready HR dataset'],
    ['dept' => 'AUDIT / QA', 'scope' => 'Evidence and compliance check', 'pages' => 'capture_screen.php, report.php, manufacturing_report.php, business_report.php', 'deliverable' => 'Traceable audit package'],
];

$flowRows = [
    ['01', 'ADMIN', 'Prepare users and rights', 'department_access.php, admin_access.php', 'Users ready by department'],
    ['02', 'SALE', 'Capture demand and customer order', 'quotation, booking', 'Confirmed sales demand'],
    ['03', 'PRODUCTION', 'Create project and plan', 'erp_flow.php, erp_project, mfg_production_order', 'Project baseline'],
    ['04', 'PURCHASE', 'Create purchase order', 'buy_order, mfg_purchase_req', 'Approved PO'],
    ['05', 'PURCHASE + WAREHOUSE', 'Receive material', 'buy_credit / buy_cash, stock_card', 'RM stock increased'],
    ['06', 'WAREHOUSE', 'Transfer Main -> Production warehouse', 'transfer_stock, mfg_material_reservation', 'RM at production point'],
    ['07', 'PRODUCTION', 'Execute production', 'mfg_job_sheet, mfg_iot_log, mfg_lot_consumption', 'WIP execution records'],
    ['08', 'PRODUCTION + QA', 'Inspect and release', 'mfg_qms_plan, mfg_qms_result, mfg_report_spc', 'Released FG lot'],
    ['09', 'WAREHOUSE', 'Receive FG into FG warehouse', 'mfg_lot, mfg_inventory_snapshot', 'FG ready'],
    ['10', 'WAREHOUSE', 'Transfer FG -> Packing warehouse', 'transfer_stock, mfg_lot_dispatch', 'Packed-ready stock'],
    ['11', 'SALE', 'Deliver to customer', 'client_receive, WH_SHIP_OUT', 'Delivery completed'],
    ['12', 'SALE + ACCOUNT', 'Invoice and AR posting', 'sale_cash / sale_credit, deptor_billing', 'Sales billing'],
    ['13', 'ACCOUNT + FINANCE', 'AP/AR settlement and GL', 'deptor_paid, creditor_paid, gl_journal', 'Cash + ledger update'],
    ['14', 'ACCOUNT', 'Run financial reports', 'business_report.php', 'Trial/Ledger/Tax/Balance Sheet'],
    ['15', 'AUDIT', 'Capture and archive evidence', 'capture_screen.php', 'Audit package'],
];

$staticPages = [
    ['index.php', 'Main shell and menu', 'Dashboard and module entry point'],
    ['report.php', 'Generic list/document report', 'List and selected doc print/PDF'],
    ['business_report.php', 'GL and HR reporting', 'Trial balance, ledger, tax, balance sheet, payroll'],
    ['manufacturing_report.php', 'Manufacturing analytics', 'BOM, routing, APS, SPC, traceability, maintenance'],
    ['manufacturing_dashboard.php', 'Manufacturing realtime dashboard', 'Load, status, sensor health'],
    ['erp_flow.php', 'Project to delivery orchestration', 'Flow run + timeline'],
    ['department_access.php', 'Department rights template', 'Apply department permission sets'],
    ['admin_access.php', 'Permission audit page', 'Per-user permission audit'],
    ['capture_screen.php', 'Screen capture evidence', 'Audit evidence upload/catalog'],
    ['docs/user_manual_full.html', 'This manual', 'Full static operational handbook'],
];

$generatedAt = date('Y-m-d H:i:s');

ob_start();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SynergyERP User Manual</title>
    <style>
        body { margin: 0; background: #f4f6fa; color: #1f2a37; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; line-height: 1.45; }
        .wrap { max-width: 1320px; margin: 0 auto; padding: 18px; }
        .card { background: #fff; border: 1px solid #d6dfeb; border-radius: 10px; padding: 14px; margin-bottom: 14px; }
        h1, h2, h3 { margin: 0 0 10px 0; color: #102a43; }
        h1 { font-size: 1.55rem; }
        h2 { font-size: 1.14rem; border-bottom: 2px solid #e7eef8; padding-bottom: 6px; }
        h3 { font-size: 1rem; }
        .muted { color: #4b5f75; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
        table { border-collapse: collapse; width: 100%; font-size: .88rem; }
        th, td { border: 1px solid #d6dfeb; padding: 6px 8px; vertical-align: top; }
        th { background: #eef4fb; text-align: left; white-space: nowrap; }
        code { background: #ecf2f7; border: 1px solid #d7e2ef; border-radius: 4px; padding: 1px 4px; }
        .toc a { text-decoration: none; color: #0b7285; }
        .tag { display: inline-block; border: 1px solid #98b3cf; border-radius: 12px; padding: 1px 8px; font-size: .75rem; background: #edf5ff; margin-right: 6px; }
    </style>
</head>
<body>
<div class="wrap">
    <section class="card">
        <h1>SynergyERP Operations Manual</h1>
        <div class="muted">Generated: <?= h($generatedAt) ?> | Scope: all configured screens + end-to-end process + audit evidence</div>
        <div style="margin-top:8px;">
            <span class="tag">Department-based access</span>
            <span class="tag">Project-to-delivery flow</span>
            <span class="tag">Accounting and balance sheet</span>
            <span class="tag">Capture audit evidence</span>
        </div>
    </section>

    <section class="card toc" id="toc">
        <h2>Table Of Contents</h2>
        <ol>
            <li><a href="#prestart">Before Start</a></li>
            <li><a href="#departments">Department Roles</a></li>
            <li><a href="#flow">End-To-End Flow</a></li>
            <li><a href="#capture">Capture Module</a></li>
            <li><a href="#static-pages">Static Pages</a></li>
            <li><a href="#module-catalog">Module Catalog</a></li>
            <li><a href="#checklist">Close Checklist</a></li>
        </ol>
    </section>

    <section class="card" id="prestart">
        <h2>1) Before Start</h2>
        <ol>
            <li>Sign in at <code>index.php</code> and verify permission scope from <code>department_access.php</code> or <code>admin_access.php</code>.</li>
            <li>Confirm core master data: items, customers/suppliers, chart of accounts, BOM/routing, warehouse mapping.</li>
            <li>Prepare references for traceability: project code, document number, lot number, and run number.</li>
            <li>Capture critical evidence using <code>capture_screen.php</code> during the process.</li>
        </ol>
    </section>

    <section class="card" id="departments">
        <h2>2) Department Roles</h2>
        <table>
            <thead><tr><th>Department</th><th>Scope</th><th>Main Screens</th><th>Expected Deliverables</th></tr></thead>
            <tbody>
            <?php foreach ($departmentGuides as $row): ?>
                <tr>
                    <td class="mono"><?= h($row['dept']) ?></td>
                    <td><?= h($row['scope']) ?></td>
                    <td class="mono"><?= h($row['pages']) ?></td>
                    <td><?= h($row['deliverable']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card" id="flow">
        <h2>3) End-To-End Flow (Project to Delivery and Financial Close)</h2>
        <table>
            <thead><tr><th>Step</th><th>Owner</th><th>Action</th><th>Screen / Module</th><th>Output</th></tr></thead>
            <tbody>
            <?php foreach ($flowRows as $row): ?>
                <tr>
                    <td class="mono"><?= h($row[0]) ?></td>
                    <td><?= h($row[1]) ?></td>
                    <td><?= h($row[2]) ?></td>
                    <td class="mono"><?= h($row[3]) ?></td>
                    <td><?= h($row[4]) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card" id="capture">
        <h2>4) Capture Module (Audit Evidence)</h2>
        <ol>
            <li>Open <code>capture_screen.php</code> and choose the relevant <code>module_key</code>.</li>
            <li>Fill <code>screen_name</code>, <code>process_stage</code>, <code>project_code/run_no/doc_ref</code>.</li>
            <li>Upload screenshot image (PNG/JPG/WEBP/GIF/BMP, max 15MB).</li>
            <li>System writes <code>capture_no</code> and stores file under <code>storage/captures/YYYY/MM/</code>.</li>
            <li>Deletion is soft-delete and remains traceable in <code>app_soft_delete</code>.</li>
        </ol>
    </section>

    <section class="card" id="static-pages">
        <h2>5) Static Pages (Non-module)</h2>
        <table>
            <thead><tr><th>Page</th><th>Purpose</th><th>Output</th></tr></thead>
            <tbody>
            <?php foreach ($staticPages as $row): ?>
                <tr><td class="mono"><?= h($row[0]) ?></td><td><?= h($row[1]) ?></td><td><?= h($row[2]) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card" id="module-catalog">
        <h2>6) Module Catalog (Auto from config/*_modules.php)</h2>
        <?php foreach ($groups as $group): ?>
            <?php
            $items = $group['items'] ?? [];
            if (!is_array($items) || count($items) === 0) {
                continue;
            }
            ?>
            <h3><?= h(normalizeText((string)($group['group_title'] ?? ''))) ?> <span class="mono muted">(<?= h((string)($group['group_key'] ?? '')) ?>)</span></h3>
            <table style="margin-bottom:14px;">
                <thead>
                <tr>
                    <th>#</th><th>Module Key</th><th>Title</th><th>Form</th><th>Mode</th>
                    <th>Main Table</th><th>Detail Table</th><th>Menu URL</th><th>List Report</th><th>Custom URL</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach (array_values($items) as $idx => $item): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td class="mono"><?= h((string)($item['key'] ?? '')) ?></td>
                        <td><?= h(normalizeText((string)($item['title'] ?? ''))) ?></td>
                        <td><?= h((string)($item['form_id'] ?? '')) ?></td>
                        <td class="mono"><?= h((string)($item['mode'] ?? '')) ?></td>
                        <td class="mono"><?= h((string)($item['main_table'] ?? '')) ?></td>
                        <td class="mono"><?= h((string)($item['detail_table'] ?? '')) ?></td>
                        <td class="mono"><?= h(moduleUrl($item)) ?></td>
                        <td class="mono"><?= h(reportUrl($item)) ?></td>
                        <td class="mono"><?= h((string)($item['custom_report_url'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    </section>

    <section class="card" id="checklist">
        <h2>7) Close Checklist</h2>
        <ol>
            <li>All buy/sell/production documents link by project, lot, and reference number.</li>
            <li>Warehouse movement chain is complete: Main -> Production -> FG -> Packing -> Delivery.</li>
            <li>GL posting is complete and verified with Trial Balance, Ledger, and Balance Sheet.</li>
            <li>Critical evidence is captured in <code>capture_screen.php</code> with process stage tags.</li>
            <li>Audit package can be produced by project/run with report + evidence references.</li>
        </ol>
    </section>
</div>
</body>
</html>
<?php

$html = (string)ob_get_clean();
$target = $root . '/docs/user_manual_full.html';
file_put_contents($target, $html);

echo 'generated: ' . $target . PHP_EOL;
