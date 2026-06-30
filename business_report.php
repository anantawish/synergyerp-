<?php

require __DIR__ . '/bootstrap.php';

/** @param mixed $value */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** @param mixed $value */
function n($value, int $decimals = 2): string
{
    return number_format((float)$value, $decimals);
}

if (!$authService->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

if (!$authService->hasPermission(22)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$report = trim((string)($_GET['report'] ?? 'gl_trial_balance'));
$validReports = [
    'gl_trial_balance',
    'gl_ledger',
    'gl_tax_summary',
    'gl_balance_sheet',
    'hr_attendance',
    'hr_leave',
    'hr_payroll',
];
if (!in_array($report, $validReports, true)) {
    $report = 'gl_trial_balance';
}

$today = new DateTimeImmutable('now');
$defaultMonth = $today->format('Y-m');
$defaultFrom = $today->format('Y-m-01');
$defaultTo = $today->format('Y-m-t');

$titleMap = [
    'gl_trial_balance' => 'GL Trial Balance',
    'gl_ledger' => 'GL General Ledger',
    'gl_tax_summary' => 'Thai Tax Summary (VAT/WHT)',
    'gl_balance_sheet' => 'GL Balance Sheet',
    'hr_attendance' => 'HR Attendance Summary',
    'hr_leave' => 'HR Leave Summary',
    'hr_payroll' => 'HR Payroll Summary',
];
$title = $titleMap[$report] ?? $report;

$error = '';
$notice = '';
$result = [];

try {
    if ($report === 'gl_trial_balance') {
        $from = (string)($_GET['date_from'] ?? $defaultFrom);
        $to = (string)($_GET['date_to'] ?? $defaultTo);
        $result = $businessService->glTrialBalance($from, $to);
    } elseif ($report === 'gl_ledger') {
        $from = (string)($_GET['date_from'] ?? $defaultFrom);
        $to = (string)($_GET['date_to'] ?? $defaultTo);
        $accountCode = trim((string)($_GET['account_code'] ?? ''));
        if ($accountCode === '') {
            $row = $database->pdo()->query('SELECT account_code FROM gl_account WHERE is_active = 1 ORDER BY account_code LIMIT 1')->fetch();
            $accountCode = is_array($row) ? (string)$row['account_code'] : '';
        }
        $result = $businessService->glLedger($accountCode, $from, $to);
    } elseif ($report === 'gl_tax_summary') {
        $month = (string)($_GET['month'] ?? $defaultMonth);
        $result = $businessService->glTaxSummary($month);
    } elseif ($report === 'gl_balance_sheet') {
        $asOfDate = (string)($_GET['as_of_date'] ?? $defaultTo);
        $result = $businessService->glBalanceSheet($asOfDate);
    } elseif ($report === 'hr_attendance') {
        $month = (string)($_GET['month'] ?? $defaultMonth);
        $result = $businessService->hrAttendanceSummary($month);
    } elseif ($report === 'hr_leave') {
        $month = (string)($_GET['month'] ?? $defaultMonth);
        $result = $businessService->hrLeaveSummary($month);
    } elseif ($report === 'hr_payroll') {
        $periodCode = trim((string)($_GET['period_code'] ?? ''));
        if (($_POST['action'] ?? '') === 'calculate_payroll') {
            $postCode = trim((string)($_POST['period_code'] ?? ''));
            $cal = $businessService->calculatePayroll(null, $postCode, (string)($authService->user()['username'] ?? 'system'));
            $periodCode = (string)($cal['period']['period_code'] ?? $postCode);
            $notice = 'Payroll calculated for period ' . $periodCode;
        }
        $result = $businessService->hrPayrollSummary(null, $periodCode);
        $result['periods'] = $businessService->listPayrollPeriods();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> | SynergyERP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/global-menu.css">
    <style>
        body { background: #f1f5f9; }
        .wrap { max-width: 1400px; margin: 0 auto; padding: 14px; }
        .table th { white-space: nowrap; }
        .metric { background: #fff; border: 1px solid #d8e0ea; border-radius: 8px; padding: 10px; }
        .metric .k { font-size: 0.8rem; color: #5d6f81; }
        .metric .v { font-weight: 700; font-size: 1rem; }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .wrap { max-width: none; padding: 0; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="d-flex flex-wrap gap-2 mb-2 no-print">
        <a class="btn btn-sm btn-outline-secondary" href="index.php?page=dashboard">Dashboard</a>
        <a class="btn btn-sm btn-outline-primary" href="business_report.php?report=gl_trial_balance">GL Trial Balance</a>
        <a class="btn btn-sm btn-outline-primary" href="business_report.php?report=gl_ledger">GL Ledger</a>
        <a class="btn btn-sm btn-outline-primary" href="business_report.php?report=gl_tax_summary">Tax Summary</a>
        <a class="btn btn-sm btn-outline-primary" href="business_report.php?report=gl_balance_sheet">Balance Sheet</a>
        <a class="btn btn-sm btn-outline-success" href="business_report.php?report=hr_attendance">HR Attendance</a>
        <a class="btn btn-sm btn-outline-success" href="business_report.php?report=hr_leave">HR Leave</a>
        <a class="btn btn-sm btn-outline-success" href="business_report.php?report=hr_payroll">HR Payroll</a>
        <button class="btn btn-sm btn-dark" onclick="window.print()">Print / PDF</button>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="m-0"><?= h($title) ?></h3>
        <small class="text-muted">Generated: <?= h(date('Y-m-d H:i:s')) ?></small>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success py-2"><?= h($notice) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <strong>Report Error:</strong> <?= h($error) ?>
        </div>
    <?php else: ?>

        <?php if ($report === 'gl_trial_balance'): ?>
            <form method="get" class="row g-2 mb-3 no-print">
                <input type="hidden" name="report" value="gl_trial_balance">
                <div class="col-auto">
                    <label class="form-label mb-1">From</label>
                    <input class="form-control form-control-sm" type="date" name="date_from" value="<?= h($result['from_date'] ?? $defaultFrom) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1">To</label>
                    <input class="form-control form-control-sm" type="date" name="date_to" value="<?= h($result['to_date'] ?? $defaultTo) ?>">
                </div>
                <div class="col-auto align-self-end">
                    <button class="btn btn-sm btn-primary">Run</button>
                </div>
            </form>

            <div class="row g-2 mb-2">
                <div class="col-md-3"><div class="metric"><div class="k">Total Debit</div><div class="v"><?= n($result['total_debit'] ?? 0) ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">Total Credit</div><div class="v"><?= n($result['total_credit'] ?? 0) ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">Balanced</div><div class="v"><?= !empty($result['is_balanced']) ? 'YES' : 'NO' ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">Range</div><div class="v"><?= h(($result['from_date'] ?? '-') . ' to ' . ($result['to_date'] ?? '-')) ?></div></div></div>
            </div>

            <div class="table-responsive bg-white border rounded">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Account Code</th>
                        <th>Account Name</th>
                        <th>Type</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                        <th class="text-end">Balance</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($result['rows'] ?? []) as $row): ?>
                        <tr>
                            <td><?= h($row['account_code']) ?></td>
                            <td><?= h($row['account_name']) ?></td>
                            <td><?= h($row['account_type']) ?></td>
                            <td class="text-end"><?= n($row['debit_total']) ?></td>
                            <td class="text-end"><?= n($row['credit_total']) ?></td>
                            <td class="text-end"><?= n($row['balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($report === 'gl_ledger'): ?>
            <?php
            $accounts = $database->pdo()->query('SELECT account_code, account_name FROM gl_account WHERE is_active = 1 ORDER BY account_code')->fetchAll();
            $selectedCode = (string)($result['account']['account_code'] ?? '');
            ?>
            <form method="get" class="row g-2 mb-3 no-print">
                <input type="hidden" name="report" value="gl_ledger">
                <div class="col-md-3">
                    <label class="form-label mb-1">Account</label>
                    <select class="form-select form-select-sm" name="account_code">
                        <?php foreach ($accounts as $acc): ?>
                            <?php $code = (string)$acc['account_code']; ?>
                            <option value="<?= h($code) ?>" <?= $code === $selectedCode ? 'selected' : '' ?>>
                                <?= h($code . ' - ' . (string)$acc['account_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1">From</label>
                    <input class="form-control form-control-sm" type="date" name="date_from" value="<?= h($result['from_date'] ?? $defaultFrom) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1">To</label>
                    <input class="form-control form-control-sm" type="date" name="date_to" value="<?= h($result['to_date'] ?? $defaultTo) ?>">
                </div>
                <div class="col-auto align-self-end">
                    <button class="btn btn-sm btn-primary">Run</button>
                </div>
            </form>

            <div class="row g-2 mb-2">
                <div class="col-md-4"><div class="metric"><div class="k">Account</div><div class="v"><?= h(($result['account']['account_code'] ?? '') . ' - ' . ($result['account']['account_name'] ?? '')) ?></div></div></div>
                <div class="col-md-4"><div class="metric"><div class="k">Type</div><div class="v"><?= h($result['account']['account_type'] ?? '-') ?></div></div></div>
                <div class="col-md-4"><div class="metric"><div class="k">Ending Balance</div><div class="v"><?= n($result['ending_balance'] ?? 0) ?></div></div></div>
            </div>

            <div class="table-responsive bg-white border rounded">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Journal No</th>
                        <th>Description</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Credit</th>
                        <th class="text-end">Running</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($result['rows'] ?? []) as $row): ?>
                        <tr>
                            <td><?= h($row['journal_date']) ?></td>
                            <td><?= h($row['journal_no']) ?></td>
                            <td><?= h($row['description']) ?></td>
                            <td class="text-end"><?= n($row['debit']) ?></td>
                            <td class="text-end"><?= n($row['credit']) ?></td>
                            <td class="text-end"><?= n($row['running_balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($report === 'gl_tax_summary'): ?>
            <form method="get" class="row g-2 mb-3 no-print">
                <input type="hidden" name="report" value="gl_tax_summary">
                <div class="col-auto">
                    <label class="form-label mb-1">Month</label>
                    <input class="form-control form-control-sm" type="month" name="month" value="<?= h($result['month'] ?? $defaultMonth) ?>">
                </div>
                <div class="col-auto align-self-end">
                    <button class="btn btn-sm btn-primary">Run</button>
                </div>
            </form>

            <div class="row g-2 mb-2">
                <div class="col-md-3"><div class="metric"><div class="k">Sale VAT</div><div class="v"><?= n($result['vat']['sale_vat'] ?? 0) ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">Purchase VAT</div><div class="v"><?= n($result['vat']['purchase_vat'] ?? 0) ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">VAT Payable (PP30)</div><div class="v"><?= n($result['vat']['vat_payable'] ?? 0) ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">WHT Total</div><div class="v"><?= n($result['withholding']['withholding_total'] ?? 0) ?></div></div></div>
            </div>

            <div class="table-responsive bg-white border rounded mb-3">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>PND Form</th>
                        <th class="text-end">Document Count</th>
                        <th class="text-end">Gross Amount</th>
                        <th class="text-end">Withholding Amount</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($result['withholding']['rows'] ?? []) as $row): ?>
                        <tr>
                            <td><?= h($row['pnd_form']) ?></td>
                            <td class="text-end"><?= n($row['doc_count'], 0) ?></td>
                            <td class="text-end"><?= n($row['gross_total']) ?></td>
                            <td class="text-end"><?= n($row['withholding_total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($report === 'gl_balance_sheet'): ?>
            <form method="get" class="row g-2 mb-3 no-print">
                <input type="hidden" name="report" value="gl_balance_sheet">
                <div class="col-auto">
                    <label class="form-label mb-1">As Of Date</label>
                    <input class="form-control form-control-sm" type="date" name="as_of_date" value="<?= h($result['as_of_date'] ?? $defaultTo) ?>">
                </div>
                <div class="col-auto align-self-end">
                    <button class="btn btn-sm btn-primary">Run</button>
                </div>
            </form>

            <div class="row g-2 mb-2">
                <div class="col-md-3"><div class="metric"><div class="k">Total Assets</div><div class="v"><?= n($result['total_assets'] ?? 0) ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">Total Liabilities</div><div class="v"><?= n($result['total_liabilities'] ?? 0) ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">Total Equity (incl. Profit)</div><div class="v"><?= n($result['total_equity'] ?? 0) ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">Balanced</div><div class="v"><?= !empty($result['is_balanced']) ? 'YES' : 'NO' ?></div></div></div>
            </div>

            <div class="row g-2">
                <div class="col-lg-6">
                    <div class="table-responsive bg-white border rounded">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                            <tr>
                                <th colspan="3">Assets</th>
                            </tr>
                            <tr>
                                <th>Account Code</th>
                                <th>Account Name</th>
                                <th class="text-end">Amount</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (($result['assets'] ?? []) as $row): ?>
                                <tr>
                                    <td><?= h($row['account_code']) ?></td>
                                    <td><?= h($row['account_name']) ?></td>
                                    <td class="text-end"><?= n($row['balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-light">
                                <th colspan="2">Total Assets</th>
                                <th class="text-end"><?= n($result['total_assets'] ?? 0) ?></th>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="table-responsive bg-white border rounded">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                            <tr>
                                <th colspan="3">Liabilities & Equity</th>
                            </tr>
                            <tr>
                                <th>Account Code</th>
                                <th>Account Name</th>
                                <th class="text-end">Amount</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (($result['liabilities'] ?? []) as $row): ?>
                                <tr>
                                    <td><?= h($row['account_code']) ?></td>
                                    <td><?= h($row['account_name']) ?></td>
                                    <td class="text-end"><?= n($row['balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-light">
                                <th colspan="2">Total Liabilities</th>
                                <th class="text-end"><?= n($result['total_liabilities'] ?? 0) ?></th>
                            </tr>
                            <?php foreach (($result['equity'] ?? []) as $row): ?>
                                <tr>
                                    <td><?= h($row['account_code']) ?></td>
                                    <td><?= h($row['account_name']) ?></td>
                                    <td class="text-end"><?= n($row['balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td></td>
                                <td>Current Profit / Loss</td>
                                <td class="text-end"><?= n($result['current_profit'] ?? 0) ?></td>
                            </tr>
                            <tr class="table-light">
                                <th colspan="2">Total Liabilities + Equity</th>
                                <th class="text-end"><?= n($result['total_liabilities_equity'] ?? 0) ?></th>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($report === 'hr_attendance'): ?>
            <form method="get" class="row g-2 mb-3 no-print">
                <input type="hidden" name="report" value="hr_attendance">
                <div class="col-auto">
                    <label class="form-label mb-1">Month</label>
                    <input class="form-control form-control-sm" type="month" name="month" value="<?= h($result['month'] ?? $defaultMonth) ?>">
                </div>
                <div class="col-auto align-self-end">
                    <button class="btn btn-sm btn-primary">Run</button>
                </div>
            </form>

            <div class="table-responsive bg-white border rounded">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th class="text-end">Work Days</th>
                        <th class="text-end">Absent</th>
                        <th class="text-end">Late (times)</th>
                        <th class="text-end">Late (mins)</th>
                        <th class="text-end">OT Workday</th>
                        <th class="text-end">OT Holiday</th>
                        <th class="text-end">OT Holiday+</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($result['rows'] ?? []) as $row): ?>
                        <tr>
                            <td><?= h($row['employee_code'] . ' - ' . $row['full_name']) ?></td>
                            <td><?= h($row['department']) ?></td>
                            <td class="text-end"><?= n($row['work_days'], 2) ?></td>
                            <td class="text-end"><?= n($row['absent_days'], 2) ?></td>
                            <td class="text-end"><?= n($row['late_count'], 0) ?></td>
                            <td class="text-end"><?= n($row['late_minutes'], 0) ?></td>
                            <td class="text-end"><?= n($row['ot_workday']) ?></td>
                            <td class="text-end"><?= n($row['ot_holiday']) ?></td>
                            <td class="text-end"><?= n($row['ot_holiday_ot']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($report === 'hr_leave'): ?>
            <form method="get" class="row g-2 mb-3 no-print">
                <input type="hidden" name="report" value="hr_leave">
                <div class="col-auto">
                    <label class="form-label mb-1">Month</label>
                    <input class="form-control form-control-sm" type="month" name="month" value="<?= h($result['month'] ?? $defaultMonth) ?>">
                </div>
                <div class="col-auto align-self-end">
                    <button class="btn btn-sm btn-primary">Run</button>
                </div>
            </form>

            <div class="table-responsive bg-white border rounded">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Leave Code</th>
                        <th>Leave Name</th>
                        <th class="text-end">Total Days</th>
                        <th class="text-end">Paid Days</th>
                        <th class="text-end">Unpaid Days</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($result['rows'] ?? []) as $row): ?>
                        <tr>
                            <td><?= h($row['employee_code'] . ' - ' . $row['full_name']) ?></td>
                            <td><?= h($row['leave_code']) ?></td>
                            <td><?= h($row['leave_name']) ?></td>
                            <td class="text-end"><?= n($row['total_days']) ?></td>
                            <td class="text-end"><?= n($row['paid_days']) ?></td>
                            <td class="text-end"><?= n($row['unpaid_days']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($report === 'hr_payroll'): ?>
            <?php $periods = $result['periods'] ?? []; $selectedPeriod = (string)($result['period']['period_code'] ?? ''); ?>
            <div class="row g-2 mb-3 no-print">
                <div class="col-md-6">
                    <form method="get" class="row g-2">
                        <input type="hidden" name="report" value="hr_payroll">
                        <div class="col-md-8">
                            <label class="form-label mb-1">Period</label>
                            <select class="form-select form-select-sm" name="period_code">
                                <?php foreach ($periods as $p): ?>
                                    <?php $code = (string)$p['period_code']; ?>
                                    <option value="<?= h($code) ?>" <?= $code === $selectedPeriod ? 'selected' : '' ?>>
                                        <?= h($code . ' | ' . (string)$p['date_from'] . ' - ' . (string)$p['date_to'] . ' | ' . (string)$p['status']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto align-self-end">
                            <button class="btn btn-sm btn-primary">Load</button>
                        </div>
                    </form>
                </div>
                <div class="col-md-6">
                    <form method="post" class="row g-2 justify-content-md-end">
                        <input type="hidden" name="action" value="calculate_payroll">
                        <div class="col-md-7">
                            <label class="form-label mb-1">Calculate Period</label>
                            <select class="form-select form-select-sm" name="period_code">
                                <?php foreach ($periods as $p): ?>
                                    <?php $code = (string)$p['period_code']; ?>
                                    <option value="<?= h($code) ?>" <?= $code === $selectedPeriod ? 'selected' : '' ?>>
                                        <?= h($code . ' | ' . (string)$p['date_from'] . ' - ' . (string)$p['date_to']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto align-self-end">
                            <button class="btn btn-sm btn-success">Calculate Payroll</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="row g-2 mb-2">
                <div class="col-md-3"><div class="metric"><div class="k">Period</div><div class="v"><?= h($result['period']['period_code'] ?? '-') ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">Status</div><div class="v"><?= h($result['period']['status'] ?? '-') ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">Total Gross</div><div class="v"><?= n($result['totals']['gross_salary'] ?? 0) ?></div></div></div>
                <div class="col-md-3"><div class="metric"><div class="k">Total Net</div><div class="v"><?= n($result['totals']['net_pay'] ?? 0) ?></div></div></div>
            </div>

            <div class="table-responsive bg-white border rounded">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th class="text-end">Gross</th>
                        <th class="text-end">OT</th>
                        <th class="text-end">Late Deduct</th>
                        <th class="text-end">Absent Deduct</th>
                        <th class="text-end">Leave Deduct</th>
                        <th class="text-end">Social Security</th>
                        <th class="text-end">WHT</th>
                        <th class="text-end">Net Pay</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($result['rows'] ?? []) as $row): ?>
                        <tr>
                            <td><?= h($row['employee_code'] . ' - ' . $row['full_name']) ?></td>
                            <td><?= h($row['department']) ?></td>
                            <td class="text-end"><?= n($row['gross_salary']) ?></td>
                            <td class="text-end"><?= n($row['ot_pay']) ?></td>
                            <td class="text-end"><?= n($row['late_deduct']) ?></td>
                            <td class="text-end"><?= n($row['absent_deduct']) ?></td>
                            <td class="text-end"><?= n($row['leave_deduct']) ?></td>
                            <td class="text-end"><?= n($row['social_security']) ?></td>
                            <td class="text-end"><?= n($row['withholding_tax']) ?></td>
                            <td class="text-end"><?= n($row['net_pay']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    <div class="small text-muted mt-3">
        Compliance note: โปรดตรวจสอบผลรายงาน/การยื่นภาษีจริงกับนักบัญชีหรือที่ปรึกษากฎหมายแรงงานอีกครั้งก่อนใช้งานจริง
    </div>
</div>
<script src="assets/global-menu.js"></script>
</body>
</html>
