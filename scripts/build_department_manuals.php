<?php

declare(strict_types=1);

date_default_timezone_set('Asia/Bangkok');

$root = dirname(__DIR__);
$docsDir = $root . '/docs';
if (!is_dir($docsDir)) {
    mkdir($docsDir, 0777, true);
}

/**
 * @param mixed $value
 */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string, mixed> $dept
 * @param array<int, array<string, mixed>> $all
 */
function renderDepartmentManual(array $dept, array $all, string $generatedAt): string
{
    $title = (string)$dept['title'];
    $slug = (string)$dept['slug'];
    $objective = (string)$dept['objective'];
    $role = (string)$dept['role'];
    $upstream = (string)$dept['upstream'];
    $downstream = (string)$dept['downstream'];
    $modules = (array)$dept['modules'];
    $dailyStart = (array)$dept['daily_start'];
    $dailyOps = (array)$dept['daily_operations'];
    $dailyClose = (array)$dept['daily_close'];
    $evidence = (array)$dept['evidence'];
    $kpi = (array)$dept['kpi'];

    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> | SynergyERP Department Manual</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --card: #ffffff;
            --line: #d4deea;
            --ink: #1f2d3d;
            --muted: #50657d;
            --accent: #0e7490;
            --ok: #0f766e;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.45;
        }
        .wrap { max-width: 1180px; margin: 0 auto; padding: 18px; }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 14px;
        }
        h1, h2, h3 { margin: 0 0 10px 0; color: #143a52; }
        h1 { font-size: 1.5rem; }
        h2 { font-size: 1.06rem; border-bottom: 2px solid #e4edf6; padding-bottom: 6px; }
        h3 { font-size: .96rem; margin-top: 4px; }
        .muted { color: var(--muted); }
        .pill {
            display: inline-block;
            border: 1px solid #9eb3cb;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: .75rem;
            margin-right: 6px;
            background: #edf4fc;
        }
        .grid {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(12, minmax(0, 1fr));
        }
        .col-6 { grid-column: span 6; }
        .col-12 { grid-column: span 12; }
        @media (max-width: 980px) {
            .col-6 { grid-column: span 12; }
        }
        table {
            border-collapse: collapse;
            width: 100%;
            font-size: .88rem;
        }
        th, td {
            border: 1px solid var(--line);
            padding: 6px 8px;
            vertical-align: top;
        }
        th {
            background: #edf4fb;
            text-align: left;
            white-space: nowrap;
        }
        code {
            background: #edf2f7;
            border: 1px solid #d7e1ec;
            border-radius: 4px;
            padding: 1px 4px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
        }
        .checklist li { margin: 4px 0; }
        .ok { color: var(--ok); }
        a { color: var(--accent); text-decoration: none; }
        @media print {
            body { background: #fff; }
            .card { border-color: #b9c4d3; }
            a { color: #000; text-decoration: none; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <section class="card">
        <h1><?= h($title) ?></h1>
        <div class="muted">Generated: <?= h($generatedAt) ?> | Static File: <code>docs/manual_<?= h($slug) ?>.html</code></div>
        <div style="margin-top:8px;">
            <span class="pill"><?= h($role) ?></span>
            <span class="pill">Upstream: <?= h($upstream) ?></span>
            <span class="pill">Downstream: <?= h($downstream) ?></span>
        </div>
    </section>

    <section class="card">
        <h2>Role Objective</h2>
        <p style="margin:0;"><?= h($objective) ?></p>
    </section>

    <section class="card">
        <h2>Screen Scope</h2>
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>Module Key</th>
                <th>Open Screen</th>
                <th>Main Purpose</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (array_values($modules) as $idx => $m): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <td><code><?= h($m['key']) ?></code></td>
                    <td><code><?= h($m['url']) ?></code></td>
                    <td><?= h($m['purpose']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="card">
        <h2>Daily Checklist</h2>
        <div class="grid">
            <div class="col-6 card" style="margin:0;">
                <h3>Start of Day</h3>
                <ol class="checklist">
                    <?php foreach ($dailyStart as $line): ?>
                        <li><?= h($line) ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <div class="col-6 card" style="margin:0;">
                <h3>Operations</h3>
                <ol class="checklist">
                    <?php foreach ($dailyOps as $line): ?>
                        <li><?= h($line) ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <div class="col-12 card" style="margin:0;">
                <h3>Close of Day</h3>
                <ol class="checklist">
                    <?php foreach ($dailyClose as $line): ?>
                        <li><?= h($line) ?></li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </div>
    </section>

    <section class="card">
        <h2>Audit Evidence To Capture</h2>
        <ol class="checklist">
            <?php foreach ($evidence as $line): ?>
                <li><?= h($line) ?></li>
            <?php endforeach; ?>
        </ol>
        <p class="muted" style="margin:8px 0 0 0;">Use <code>capture_screen.php</code> and tag each screenshot with <code>module_key</code>, <code>process_stage</code>, <code>project_code</code>, <code>run_no</code>, and <code>doc_ref</code>.</p>
    </section>

    <section class="card">
        <h2>Role KPIs</h2>
        <ul class="checklist">
            <?php foreach ($kpi as $line): ?>
                <li class="ok"><?= h($line) ?></li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card">
        <h2>Related Manuals</h2>
        <ul>
            <li><a href="user_manual_departments.html">Department Manual Index</a></li>
            <li><a href="user_manual_full.html">Full ERP Manual (All Screens)</a></li>
        </ul>
        <p class="muted" style="margin:0;">Generated by <code>scripts/build_department_manuals.php</code>.</p>
    </section>
</div>
</body>
</html>
    <?php

    return (string)ob_get_clean();
}

/**
 * @param array<int, array<string, mixed>> $depts
 */
function renderIndex(array $depts, string $generatedAt): string
{
    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SynergyERP Department Manuals</title>
    <style>
        body { margin: 0; background: #f3f6fb; color: #1f2d3d; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; }
        .wrap { max-width: 980px; margin: 0 auto; padding: 20px; }
        .card { background: #fff; border: 1px solid #d5deea; border-radius: 10px; padding: 14px; margin-bottom: 14px; }
        h1, h2 { margin: 0 0 10px 0; color: #143a52; }
        ul { margin: 0; padding-left: 20px; }
        li { margin: 4px 0; }
        a { color: #0e7490; text-decoration: none; }
        code { background: #edf2f7; border: 1px solid #d8e1ed; border-radius: 4px; padding: 1px 4px; }
    </style>
</head>
<body>
<div class="wrap">
    <section class="card">
        <h1>SynergyERP Department Manuals (Static HTML)</h1>
        <div>Generated: <?= h($generatedAt) ?></div>
    </section>

    <section class="card">
        <h2>Open Manual By Department</h2>
        <ul>
            <?php foreach ($depts as $dept): ?>
                <li>
                    <a href="manual_<?= h($dept['slug']) ?>.html"><?= h($dept['title']) ?></a>
                    <span style="color:#52667e;">(<?= h($dept['role']) ?>)</span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="card">
        <h2>Cross Manual</h2>
        <ul>
            <li><a href="user_manual_full.html">Full ERP Manual (All Screens)</a></li>
            <li>Source generator: <code>scripts/build_department_manuals.php</code></li>
        </ul>
    </section>
</div>
</body>
</html>
    <?php

    return (string)ob_get_clean();
}

$generatedAt = date('Y-m-d H:i:s');

/** @var array<int, array<string, mixed>> $departments */
$departments = [
    [
        'slug' => 'purchase',
        'title' => 'Purchasing Department Manual',
        'role' => 'PURCHASE',
        'objective' => 'Convert approved demand into purchase orders, receive materials on time, and complete AP cycle with full traceability.',
        'upstream' => 'Sales, Production Planning, Warehouse Reorder',
        'downstream' => 'Warehouse, Accounting/AP, Production',
        'modules' => [
            ['key' => 'buy_order', 'url' => 'index.php?page=module&module=buy_order', 'purpose' => 'Issue purchase order'],
            ['key' => 'buy_credit', 'url' => 'index.php?page=module&module=buy_credit', 'purpose' => 'Record credit purchase receipt'],
            ['key' => 'buy_cash', 'url' => 'index.php?page=module&module=buy_cash', 'purpose' => 'Record cash purchase receipt'],
            ['key' => 'buy_sendback', 'url' => 'index.php?page=module&module=buy_sendback', 'purpose' => 'Return defective goods to supplier'],
            ['key' => 'creditor_billing', 'url' => 'index.php?page=module&module=creditor_billing', 'purpose' => 'Supplier billing control'],
            ['key' => 'creditor_paid', 'url' => 'index.php?page=module&module=creditor_paid', 'purpose' => 'AP payment execution'],
            ['key' => 'mfg_purchase_req', 'url' => 'index.php?page=module&module=mfg_purchase_req', 'purpose' => 'Production-driven material request'],
        ],
        'daily_start' => [
            'Review due purchase requisitions and open production/project demand.',
            'Validate supplier lead time and stock risk from reorder reports.',
            'Confirm approval status and document numbering policy.',
        ],
        'daily_operations' => [
            'Create PO and keep references to project_code / item_code / expected date.',
            'Post goods receipt and match quantity and unit cost against PO.',
            'Escalate mismatches and post supplier return if needed.',
            'Coordinate AP billing and payment with Accounting.',
        ],
        'daily_close' => [
            'Ensure all received documents are posted and not left in draft.',
            'Reconcile open PO vs received vs billed status.',
            'Capture evidence screens for high-value or exceptional transactions.',
        ],
        'evidence' => [
            'PO approval screen with supplier and due date.',
            'Goods receipt with item lot/qty.',
            'AP billing and payment posting confirmation.',
        ],
        'kpi' => [
            'PO on-time rate >= target.',
            'Receipt variance within approved tolerance.',
            'No missing AP document reference at day close.',
        ],
    ],
    [
        'slug' => 'warehouse',
        'title' => 'Warehouse Department Manual',
        'role' => 'WAREHOUSE',
        'objective' => 'Control stock movement across Main, Production, Finished Goods, and Packing warehouses with traceable transfers.',
        'upstream' => 'Purchasing receipts, Production release, Sales delivery plan',
        'downstream' => 'Production line, Packing, Sales shipment, Audit',
        'modules' => [
            ['key' => 'stock_card', 'url' => 'index.php?page=module&module=stock_card', 'purpose' => 'Inspect movement ledger'],
            ['key' => 'transfer_stock', 'url' => 'index.php?page=module&module=transfer_stock', 'purpose' => 'Post transfer between warehouses'],
            ['key' => 'mfg_inventory_snapshot', 'url' => 'index.php?page=module&module=mfg_inventory_snapshot', 'purpose' => 'Current on-hand and reserved balance'],
            ['key' => 'mfg_lot', 'url' => 'index.php?page=module&module=mfg_lot', 'purpose' => 'FG lot registration'],
            ['key' => 'mfg_lot_dispatch', 'url' => 'index.php?page=module&module=mfg_lot_dispatch', 'purpose' => 'Lot dispatch to next warehouse or customer'],
            ['key' => 'client_receive', 'url' => 'index.php?page=module&module=client_receive', 'purpose' => 'Delivery confirmation'],
        ],
        'daily_start' => [
            'Check inbound schedule and outbound commitments.',
            'Verify stock discrepancies from prior shift.',
            'Prepare transfer routes: Main -> Production -> FG -> Packing.',
        ],
        'daily_operations' => [
            'Post all transfers with project_code, lot_no, qty, and warehouse route.',
            'Validate available qty before issue to production.',
            'Receive FG from production and dispatch to packing in sequence.',
            'Confirm shipment issue to Sales/Delivery documents.',
        ],
        'daily_close' => [
            'Reconcile stock_card against transfer and dispatch records.',
            'Verify no negative stock remains unresolved.',
            'Capture final movement summary screens.',
        ],
        'evidence' => [
            'Transfer posting screen for Main -> Production.',
            'FG receipt and FG -> Packing transfer records.',
            'Shipment dispatch linked to customer document.',
        ],
        'kpi' => [
            'Inventory accuracy >= target.',
            'Transfer posting latency within shift SLA.',
            'Zero untraceable lot movement.',
        ],
    ],
    [
        'slug' => 'production',
        'title' => 'Production Department Manual',
        'role' => 'PRODUCTION',
        'objective' => 'Execute production plan using controlled BOM/routing, collect shop floor data, and release quality-approved lots.',
        'upstream' => 'Project planning, material issue from Warehouse',
        'downstream' => 'Finished Goods Warehouse, QA, Maintenance, Accounting',
        'modules' => [
            ['key' => 'erp_flow_console', 'url' => 'erp_flow.php', 'purpose' => 'Run end-to-end project flow'],
            ['key' => 'mfg_bom_header', 'url' => 'index.php?page=module&module=mfg_bom_header', 'purpose' => 'Versioned BOM master'],
            ['key' => 'mfg_routing_step', 'url' => 'index.php?page=module&module=mfg_routing_step', 'purpose' => 'Operation routing and centers'],
            ['key' => 'mfg_production_order', 'url' => 'index.php?page=module&module=mfg_production_order', 'purpose' => 'Production order release'],
            ['key' => 'mfg_aps_schedule', 'url' => 'index.php?page=module&module=mfg_aps_schedule', 'purpose' => 'Scheduling board'],
            ['key' => 'mfg_job_sheet', 'url' => 'index.php?page=module&module=mfg_job_sheet', 'purpose' => 'Digital execution records'],
            ['key' => 'mfg_qms_result', 'url' => 'index.php?page=module&module=mfg_qms_result', 'purpose' => 'QC decision and SPC feed'],
        ],
        'daily_start' => [
            'Verify production orders and APS priorities.',
            'Confirm material availability from Production Warehouse.',
            'Validate approved BOM/routing version before launch.',
        ],
        'daily_operations' => [
            'Run job sheets per operation and record machine/operator result.',
            'Collect IoT or runtime data for performance and maintenance.',
            'Submit inspection results and isolate failed lots immediately.',
            'Release only PASS lots to Finished Goods warehouse.',
        ],
        'daily_close' => [
            'Close completed operations and update order status.',
            'Review bottleneck/idle-time and open maintenance risk.',
            'Capture execution timeline evidence by order and lot.',
        ],
        'evidence' => [
            'APS board snapshot before dispatch.',
            'Digital job sheet completion and operator sign-off.',
            'QMS PASS/FAIL screen and released lot record.',
        ],
        'kpi' => [
            'Plan adherence >= target.',
            'OEE/utilization trend up and downtime trend down.',
            'First-pass quality rate >= target.',
        ],
    ],
    [
        'slug' => 'sales',
        'title' => 'Sales And POS Department Manual',
        'role' => 'SALE / POS',
        'objective' => 'Capture customer demand, commit delivery, post sales documents, and hand off complete billing data to accounting.',
        'upstream' => 'Customer order intake, available stock from Warehouse',
        'downstream' => 'Accounting AR, Finance cash collection, Production demand',
        'modules' => [
            ['key' => 'quotation', 'url' => 'index.php?page=module&module=quotation', 'purpose' => 'Quotation and commercial terms'],
            ['key' => 'booking', 'url' => 'index.php?page=module&module=booking', 'purpose' => 'Sales reservation/order booking'],
            ['key' => 'client_receive', 'url' => 'index.php?page=module&module=client_receive', 'purpose' => 'Delivery/receipt confirmation'],
            ['key' => 'sale_cash', 'url' => 'index.php?page=module&module=sale_cash', 'purpose' => 'Cash sale posting'],
            ['key' => 'sale_credit', 'url' => 'index.php?page=module&module=sale_credit', 'purpose' => 'Credit sale posting'],
            ['key' => 'sale_return', 'url' => 'index.php?page=module&module=sale_return', 'purpose' => 'Sales return management'],
            ['key' => 'deptor_billing', 'url' => 'index.php?page=module&module=deptor_billing', 'purpose' => 'AR billing process'],
        ],
        'daily_start' => [
            'Review customer commitments due today.',
            'Check available-to-promise and pending dispatch from Warehouse.',
            'Prepare open quotations and booking follow-up list.',
        ],
        'daily_operations' => [
            'Create quotation and booking with clear item, qty, and due date.',
            'Coordinate delivery and confirm client receive document.',
            'Post sale cash/credit document and reference shipment.',
            'Process returns with reason and quantity validation.',
        ],
        'daily_close' => [
            'Reconcile sales documents against dispatches and bookings.',
            'Confirm AR billing handoff completeness.',
            'Capture evidence for major orders and exceptions.',
        ],
        'evidence' => [
            'Quotation-to-booking conversion record.',
            'Delivery confirmation with customer reference.',
            'Sale posting with amount and tax base.',
        ],
        'kpi' => [
            'On-time delivery >= target.',
            'Order-to-cash cycle time within SLA.',
            'Return rate controlled below threshold.',
        ],
    ],
    [
        'slug' => 'accounting',
        'title' => 'Accounting And Finance Department Manual',
        'role' => 'ACCOUNT / FINANCE',
        'objective' => 'Post complete financial transactions, control AP/AR, taxes, and produce reliable daily/monthly financial statements.',
        'upstream' => 'Purchasing, Sales, Warehouse, HR Payroll',
        'downstream' => 'Management reporting, statutory filing, audit',
        'modules' => [
            ['key' => 'gl_journal', 'url' => 'index.php?page=module&module=gl_journal', 'purpose' => 'General journal posting'],
            ['key' => 'gl_vat_tx', 'url' => 'index.php?page=module&module=gl_vat_tx', 'purpose' => 'VAT transaction log'],
            ['key' => 'gl_withholding_tx', 'url' => 'index.php?page=module&module=gl_withholding_tx', 'purpose' => 'WHT transaction log'],
            ['key' => 'deptor_paid', 'url' => 'index.php?page=module&module=deptor_paid', 'purpose' => 'AR settlement'],
            ['key' => 'creditor_paid', 'url' => 'index.php?page=module&module=creditor_paid', 'purpose' => 'AP settlement'],
            ['key' => 'gl_balance_sheet_report', 'url' => 'business_report.php?report=gl_balance_sheet', 'purpose' => 'Balance sheet'],
            ['key' => 'gl_trial_balance_report', 'url' => 'business_report.php?report=gl_trial_balance', 'purpose' => 'Trial balance'],
        ],
        'daily_start' => [
            'Check previous-day unposted journal and open reconciliation items.',
            'Validate AP/AR cash movements expected for today.',
            'Confirm document sequence and posting date controls.',
        ],
        'daily_operations' => [
            'Post journals from sales, purchase, inventory, and payroll events.',
            'Record VAT/WHT and verify taxable base against source docs.',
            'Settle AP/AR and tie each payment to billing reference.',
            'Investigate and resolve out-of-balance entries immediately.',
        ],
        'daily_close' => [
            'Run Trial Balance and verify debit=credit.',
            'Run Balance Sheet snapshot and compare with prior close.',
            'Archive report and evidence set for audit trail.',
        ],
        'evidence' => [
            'Posted GL journal with source_ref and line details.',
            'VAT/WHT summary report for period checkpoint.',
            'Daily balance sheet snapshot.',
        ],
        'kpi' => [
            'Daily close completed within agreed cutoff.',
            'Zero unresolved out-of-balance journals.',
            'Tax and statutory report readiness on schedule.',
        ],
    ],
    [
        'slug' => 'hr',
        'title' => 'HR Department Manual',
        'role' => 'HR',
        'objective' => 'Maintain accurate employee attendance, leave, and payroll records aligned to policy and legal configuration.',
        'upstream' => 'Employee master data and attendance source',
        'downstream' => 'Accounting payroll posting, management compliance reporting',
        'modules' => [
            ['key' => 'hr_employee', 'url' => 'index.php?page=module&module=hr_employee', 'purpose' => 'Employee master'],
            ['key' => 'hr_attendance', 'url' => 'index.php?page=module&module=hr_attendance', 'purpose' => 'Attendance and OT log'],
            ['key' => 'hr_leave_type', 'url' => 'index.php?page=module&module=hr_leave_type', 'purpose' => 'Leave policy type'],
            ['key' => 'hr_leave_request', 'url' => 'index.php?page=module&module=hr_leave_request', 'purpose' => 'Leave transactions'],
            ['key' => 'hr_policy', 'url' => 'index.php?page=module&module=hr_policy', 'purpose' => 'Payroll policy settings'],
            ['key' => 'hr_payroll_period', 'url' => 'index.php?page=module&module=hr_payroll_period', 'purpose' => 'Payroll cycle'],
            ['key' => 'hr_payroll_report', 'url' => 'business_report.php?report=hr_payroll', 'purpose' => 'Payroll summary report'],
        ],
        'daily_start' => [
            'Validate attendance feed and unresolved anomalies.',
            'Check pending leave approvals and policy exceptions.',
            'Confirm current payroll period status.',
        ],
        'daily_operations' => [
            'Post attendance corrections with clear reason.',
            'Approve/reject leave and update paid/unpaid impact.',
            'Review OT, lateness, and absence deductions.',
            'Run payroll calculation for active period as needed.',
        ],
        'daily_close' => [
            'Verify attendance, leave, and payroll consistency.',
            'Export HR summary for management and accounting handoff.',
            'Capture compliance evidence for sensitive adjustments.',
        ],
        'evidence' => [
            'Attendance correction approval screen.',
            'Leave decision record and balances.',
            'Payroll calculation result by employee.',
        ],
        'kpi' => [
            'Attendance data completeness >= target.',
            'Payroll rework cases below threshold.',
            'Policy compliance exceptions resolved within SLA.',
        ],
    ],
    [
        'slug' => 'audit',
        'title' => 'Audit And QA Department Manual',
        'role' => 'AUDIT / QA',
        'objective' => 'Independently verify process compliance and maintain evidence package from operation screens and reports.',
        'upstream' => 'All operational departments',
        'downstream' => 'Management review, internal/external audit',
        'modules' => [
            ['key' => 'erp_screen_capture', 'url' => 'capture_screen.php', 'purpose' => 'Store screenshot evidence'],
            ['key' => 'erp_flow_console', 'url' => 'erp_flow.php', 'purpose' => 'Validate end-to-end flow timeline'],
            ['key' => 'report_list', 'url' => 'report.php', 'purpose' => 'Module report verification'],
            ['key' => 'business_reports', 'url' => 'business_report.php', 'purpose' => 'GL/HR compliance reports'],
            ['key' => 'manufacturing_reports', 'url' => 'manufacturing_report.php', 'purpose' => 'Manufacturing traceability and control reports'],
            ['key' => 'department_access', 'url' => 'department_access.php', 'purpose' => 'Role permission matrix review'],
        ],
        'daily_start' => [
            'Review audit plan and critical transactions for the day.',
            'Check rights changes and high-risk module activity.',
            'Prepare evidence naming and reference standards.',
        ],
        'daily_operations' => [
            'Validate transaction trace from source to report output.',
            'Capture key screens with stage code and document reference.',
            'Cross-check warehouse, production, and accounting consistency.',
            'Log nonconformities with reproducible screenshot evidence.',
        ],
        'daily_close' => [
            'Compile evidence set by project/run and department.',
            'Issue finding summary with severity and owner.',
            'Verify corrective actions and reopen unresolved critical points.',
        ],
        'evidence' => [
            'Permission matrix and affected user scope.',
            'Process timeline screen with linked document references.',
            'Financial and inventory report snapshots for reconciliation.',
        ],
        'kpi' => [
            'Audit coverage of critical steps = 100%.',
            'All critical findings have owner and target date.',
            'Evidence package completeness pass before sign-off.',
        ],
    ],
];

$generatedFiles = [];
foreach ($departments as $dept) {
    $slug = (string)$dept['slug'];
    $fileName = 'manual_' . $slug . '.html';
    $html = renderDepartmentManual($dept, $departments, $generatedAt);
    file_put_contents($docsDir . '/' . $fileName, $html);
    $generatedFiles[] = $fileName;
}

$indexHtml = renderIndex($departments, $generatedAt);
file_put_contents($docsDir . '/user_manual_departments.html', $indexHtml);
$generatedFiles[] = 'user_manual_departments.html';

echo 'generated files:' . PHP_EOL;
foreach ($generatedFiles as $file) {
    echo ' - docs/' . $file . PHP_EOL;
}
