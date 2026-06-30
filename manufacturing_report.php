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

$report = trim((string)($_GET['report'] ?? 'bom_explosion'));
$validReports = [
    'bom_explosion',
    'routing_plan',
    'aps_board',
    'resource_utilization',
    'trace_backward',
    'trace_forward',
    'spc',
    'maintenance_due',
    'maintenance_risk',
    'inventory_reorder',
    'jit_plan',
];
if (!in_array($report, $validReports, true)) {
    $report = 'bom_explosion';
}

$titleMap = [
    'bom_explosion' => 'รายละเอียดโครงสร้างผลิตภัณฑ์ BOM',
    'routing_plan' => 'Dynamic Routing Plan',
    'aps_board' => 'APS Scheduling Board',
    'resource_utilization' => 'Resource Utilization',
    'trace_backward' => 'Traceability Backward',
    'trace_forward' => 'Traceability Forward',
    'spc' => 'SPC Control Report',
    'maintenance_due' => 'Preventive Maintenance Due',
    'maintenance_risk' => 'Predictive Maintenance Risk',
    'inventory_reorder' => 'Auto Reorder Point',
    'jit_plan' => 'JIT Material Plan',
];
$title = $titleMap[$report] ?? $report;

$today = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-29 days'));
$defaultTo = $today;

$error = '';
$notice = '';
$result = [];
$rows = [];
$columns = [];
$metrics = [];
$warnings = [];

try {
    switch ($report) {
        case 'bom_explosion':
            $itemCode = trim((string)($_GET['item_code'] ?? ''));
            if ($itemCode === '') {
                $itemCode = (string)($database->pdo()->query("SELECT item_code FROM mfg_bom_header WHERE status='APPROVED' ORDER BY effective_from DESC, id DESC LIMIT 1")->fetchColumn() ?? '');
            }
            $qty = (float)($_GET['order_qty'] ?? 1);
            $asOf = (string)($_GET['as_of_date'] ?? $today);
            $result = $manufacturingService->bomExplosion($itemCode, $qty, $asOf);
            $rows = $result['components'] ?? [];
            $columns = ['component_item_code' => 'Component', 'depth' => 'Level', 'required_qty' => 'Required Qty'];
            $metrics = ['Item' => $result['item_code'] ?? '-', 'Version' => $result['bom_header']['version_no'] ?? '-', 'Blueprint' => $result['bom_header']['blueprint_no'] ?? '-', 'Components' => (int)($result['count'] ?? 0)];
            break;

        case 'routing_plan':
            $itemCode = trim((string)($_GET['item_code'] ?? ''));
            if ($itemCode === '') {
                $itemCode = (string)($database->pdo()->query("SELECT item_code FROM mfg_routing_header WHERE status='APPROVED' ORDER BY effective_from DESC, id DESC LIMIT 1")->fetchColumn() ?? '');
            }
            $qty = (float)($_GET['qty'] ?? 1);
            $asOf = (string)($_GET['as_of_date'] ?? $today);
            $result = $manufacturingService->routingPlan($itemCode, $qty, $asOf);
            $rows = $result['steps'] ?? [];
            $columns = ['op_no' => 'Op', 'operation_name' => 'Operation', 'primary_center_code' => 'Primary', 'selected_center_code' => 'Selected', 'selected_center_status' => 'Status', 'is_alternative' => 'Alt?', 'planned_hours' => 'Hours'];
            $metrics = ['Item' => $result['item_code'] ?? '-', 'Routing Ver' => $result['routing_header']['version_no'] ?? '-', 'Steps' => count($rows), 'Total Hours' => $result['total_hours'] ?? 0];
            break;

        case 'aps_board':
            $from = (string)($_GET['date_from'] ?? $defaultFrom);
            $to = (string)($_GET['date_to'] ?? $defaultTo);
            if (($_POST['action'] ?? '') === 'aps_generate') {
                $res = $manufacturingService->generateApsAdvanced(
                    (string)($_POST['date_from'] ?? $from),
                    (string)($_POST['date_to'] ?? $to),
                    isset($_POST['reschedule']) && (string)$_POST['reschedule'] === '1',
                    (string)($_POST['reason'] ?? ''),
                    [
                        'advanced' => true,
                        'weight_late' => (float)($_POST['weight_late'] ?? 20),
                        'weight_setup' => (float)($_POST['weight_setup'] ?? 3),
                        'weight_load' => (float)($_POST['weight_load'] ?? 1),
                        'split_lot_default' => (float)($_POST['split_lot_default'] ?? 0),
                        'allow_alternative' => isset($_POST['allow_alternative']) ? 1 : 0,
                        'max_batches' => (int)($_POST['max_batches'] ?? 100),
                    ]
                );
                $notice = 'APS generated: ' . (string)($res['scheduled_rows'] ?? 0) . ' rows / penalty ' . (string)($res['penalty_total'] ?? 0);
            }
            $result = $manufacturingService->apsBoard($from, $to);
            $rows = $result['rows'] ?? [];
            $columns = [
                'order_no' => 'Order',
                'item_code' => 'Item',
                'batch_no' => 'Batch',
                'batch_qty' => 'Batch Qty',
                'op_no' => 'Op',
                'operation_name' => 'Operation',
                'work_center_code' => 'Center',
                'setup_hours_applied' => 'Setup+',
                'planned_start' => 'Start',
                'planned_end' => 'End',
                'planned_hours' => 'Hours',
                'tardiness_hours' => 'Late Hrs',
                'penalty_cost' => 'Penalty',
                'heuristic_tag' => 'Tag',
                'status' => 'Status',
            ];
            $penaltySum = 0.0;
            $tardyCount = 0;
            foreach ($rows as $r) {
                $penaltySum += (float)($r['penalty_cost'] ?? 0);
                if ((float)($r['tardiness_hours'] ?? 0) > 0) {
                    $tardyCount++;
                }
            }
            $metrics = [
                'Rows' => count($rows),
                'From' => $result['from_date'] ?? '-',
                'To' => $result['to_date'] ?? '-',
                'Penalty Total' => round($penaltySum, 4),
                'Tardy Ops' => $tardyCount,
            ];
            break;

        case 'resource_utilization':
            $from = (string)($_GET['date_from'] ?? $defaultFrom);
            $to = (string)($_GET['date_to'] ?? $defaultTo);
            $result = $manufacturingService->resourceUtilization($from, $to);
            $rows = $result['rows'] ?? [];
            $columns = ['work_center_code' => 'Center', 'work_date' => 'Date', 'planned_hours' => 'Planned', 'capacity_hours' => 'Capacity', 'idle_hours' => 'Idle', 'utilization_pct' => 'Util %'];
            $metrics = ['From' => $result['from_date'] ?? '-', 'To' => $result['to_date'] ?? '-', 'Overall Util %' => $result['overall_utilization_pct'] ?? 0];
            break;

        case 'trace_backward':
            $lotNo = trim((string)($_GET['lot_no'] ?? ''));
            if ($lotNo === '') {
                $lotNo = (string)($database->pdo()->query('SELECT lot_no FROM mfg_lot ORDER BY produced_at DESC, id DESC LIMIT 1')->fetchColumn() ?? '');
            }
            $result = $manufacturingService->traceBackward($lotNo);
            $rows = $result['links'] ?? [];
            $columns = ['depth' => 'Depth', 'produced_lot_no' => 'Produced Lot', 'component_lot_no' => 'Component Lot', 'component_item_code' => 'Component Item', 'qty' => 'Qty', 'uom' => 'UOM'];
            $metrics = ['Root Lot' => $result['root_lot']['lot_no'] ?? '-', 'Item' => $result['root_lot']['item_code'] ?? '-', 'Links' => count($rows)];
            break;

        case 'trace_forward':
            $lotNo = trim((string)($_GET['lot_no'] ?? ''));
            if ($lotNo === '') {
                $lotNo = (string)($database->pdo()->query('SELECT lot_no FROM mfg_lot ORDER BY produced_at DESC, id DESC LIMIT 1')->fetchColumn() ?? '');
            }
            $result = $manufacturingService->traceForward($lotNo);
            $rows = $result['links'] ?? [];
            $columns = ['depth' => 'Depth', 'component_lot_no' => 'Component Lot', 'produced_lot_no' => 'Produced Lot', 'component_item_code' => 'Component Item', 'qty' => 'Qty', 'uom' => 'UOM'];
            $metrics = ['Root Lot' => $result['root_lot']['lot_no'] ?? '-', 'Item' => $result['root_lot']['item_code'] ?? '-', 'Links' => count($rows)];
            break;

        case 'spc':
            $itemCode = trim((string)($_GET['item_code'] ?? ''));
            $characteristic = trim((string)($_GET['characteristic'] ?? ''));
            $from = (string)($_GET['date_from'] ?? $defaultFrom);
            $to = (string)($_GET['date_to'] ?? $defaultTo);
            $result = $manufacturingService->spc($itemCode, $characteristic, $from, $to);
            $rows = $result['points'] ?? [];
            $columns = ['inspected_at' => 'Inspected At', 'lot_no' => 'Lot No', 'measured_value' => 'Value', 'decision' => 'Decision', 'out_of_control' => 'Out?'];
            $metrics = ['Item' => $result['item_code'] ?? '-', 'Characteristic' => $result['characteristic'] ?? '-', 'Samples' => $result['stats']['sample_size'] ?? 0, 'UCL' => $result['stats']['ucl'] ?? 0, 'LCL' => $result['stats']['lcl'] ?? 0, 'Out Points' => $result['stats']['out_of_control_points'] ?? 0];
            break;

        case 'maintenance_due':
            $asOf = (string)($_GET['as_of_date'] ?? date('Y-m-d H:i:s'));
            if (($_POST['action'] ?? '') === 'maintenance_generate_wo') {
                $woResult = $manufacturingService->generateMaintenanceWorkOrders((string)($_POST['as_of_date'] ?? $asOf), (int)($_POST['window_hours'] ?? 72));
                $notice = 'WO generated: ' . (string)($woResult['created_count'] ?? 0);
            }
            $result = $manufacturingService->maintenanceDue($asOf);
            $rows = $result['rows'] ?? [];
            $columns = ['machine_code' => 'Machine', 'machine_name' => 'Name', 'plan_type' => 'Type', 'runtime_since_maintenance' => 'Runtime Since', 'interval_hours' => 'Interval Hrs', 'interval_days' => 'Interval Days', 'due_reason' => 'Due Reason'];
            $metrics = ['As Of' => $result['as_of'] ?? '-', 'Due Count' => count($rows)];
            break;

        case 'maintenance_risk':
            $windowHours = (int)($_GET['window_hours'] ?? 72);
            if (($_POST['action'] ?? '') === 'maintenance_generate_wo') {
                $woResult = $manufacturingService->generateMaintenanceWorkOrders('', (int)($_POST['window_hours'] ?? $windowHours));
                $notice = 'WO generated: ' . (string)($woResult['created_count'] ?? 0);
            }
            $result = $manufacturingService->maintenanceRisk($windowHours);
            $rows = $result['rows'] ?? [];
            $columns = ['machine_code' => 'Machine', 'machine_name' => 'Name', 'sample_count' => 'Samples', 'avg_vibration' => 'Avg Vib', 'avg_temp' => 'Avg Temp', 'risk_score' => 'Risk Score', 'risk_level' => 'Risk Level'];
            $metrics = ['Window Hours' => $result['window_hours'] ?? 72, 'High Risk' => $result['high_risk_count'] ?? 0];
            break;

        case 'inventory_reorder':
            $asOf = (string)($_GET['as_of_date'] ?? $today);
            $horizon = (int)($_GET['horizon_days'] ?? 30);
            if (($_POST['action'] ?? '') === 'create_requisitions') {
                $prResult = $manufacturingService->createRequisitions('ROP', (string)($_POST['as_of_date'] ?? $asOf), (int)($_POST['horizon_days'] ?? $horizon));
                $notice = 'PR created: ' . (string)($prResult['created_count'] ?? 0);
            }
            $result = $manufacturingService->inventoryReorder($asOf, $horizon);
            $rows = $result['rows'] ?? [];
            $columns = ['item_code' => 'Item', 'method' => 'Method', 'on_hand_qty' => 'On Hand', 'reserved_qty' => 'Reserved', 'forecast_demand' => 'Forecast', 'reorder_point' => 'ROP', 'recommended_qty' => 'Recommended', 'status' => 'Status'];
            $metrics = ['As Of' => $result['as_of'] ?? '-', 'Horizon Days' => $result['horizon_days'] ?? 30, 'Reorder Count' => $result['reorder_count'] ?? 0];
            break;

        case 'jit_plan':
            $asOf = (string)($_GET['as_of_date'] ?? $today);
            $horizon = (int)($_GET['horizon_days'] ?? 14);
            if (($_POST['action'] ?? '') === 'create_requisitions') {
                $prResult = $manufacturingService->createRequisitions('JIT', (string)($_POST['as_of_date'] ?? $asOf), (int)($_POST['horizon_days'] ?? $horizon));
                $notice = 'PR created: ' . (string)($prResult['created_count'] ?? 0);
            }
            $result = $manufacturingService->jitPlan($asOf, $horizon);
            $rows = $result['rows'] ?? [];
            $columns = ['item_code' => 'Item', 'required_qty' => 'Required', 'available_qty' => 'Available', 'shortage_qty' => 'Shortage', 'status' => 'Status'];
            $metrics = ['As Of' => $result['as_of'] ?? '-', 'Horizon Days' => $result['horizon_days'] ?? 14, 'Shortage Count' => $result['shortage_count'] ?? 0];
            $warnings = $result['warnings'] ?? [];
            break;
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
        .wrap { max-width: 1500px; margin: 0 auto; padding: 14px; }
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
        <a class="btn btn-sm btn-outline-secondary" href="manufacturing_dashboard.php">Realtime</a>
        <a class="btn btn-sm btn-outline-primary" href="manufacturing_report.php?report=bom_explosion">BOM</a>
        <a class="btn btn-sm btn-outline-primary" href="manufacturing_report.php?report=routing_plan">Routing</a>
        <a class="btn btn-sm btn-outline-primary" href="manufacturing_report.php?report=aps_board">APS</a>
        <a class="btn btn-sm btn-outline-primary" href="manufacturing_report.php?report=resource_utilization">Utilization</a>
        <a class="btn btn-sm btn-outline-primary" href="manufacturing_report.php?report=trace_backward">Trace Backward</a>
        <a class="btn btn-sm btn-outline-primary" href="manufacturing_report.php?report=trace_forward">Trace Forward</a>
        <a class="btn btn-sm btn-outline-primary" href="manufacturing_report.php?report=spc">SPC</a>
        <a class="btn btn-sm btn-outline-success" href="manufacturing_report.php?report=maintenance_due">PM Due</a>
        <a class="btn btn-sm btn-outline-success" href="manufacturing_report.php?report=maintenance_risk">PM Risk</a>
        <a class="btn btn-sm btn-outline-dark" href="manufacturing_report.php?report=inventory_reorder">Reorder</a>
        <a class="btn btn-sm btn-outline-dark" href="manufacturing_report.php?report=jit_plan">JIT</a>
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
        <div class="alert alert-danger"><strong>Report Error:</strong> <?= h($error) ?></div>
    <?php else: ?>
        <form method="get" class="row g-2 mb-3 no-print">
            <input type="hidden" name="report" value="<?= h($report) ?>">
            <?php if (in_array($report, ['bom_explosion', 'routing_plan', 'spc'], true)): ?>
                <div class="col-md-3">
                    <label class="form-label mb-1">Item Code</label>
                    <input class="form-control form-control-sm" name="item_code" value="<?= h((string)($result['item_code'] ?? ($_GET['item_code'] ?? ''))) ?>">
                </div>
            <?php endif; ?>
            <?php if ($report === 'bom_explosion'): ?>
                <div class="col-auto">
                    <label class="form-label mb-1">Order Qty</label>
                    <input class="form-control form-control-sm" type="number" step="0.0001" name="order_qty" value="<?= h((string)($_GET['order_qty'] ?? ($result['order_qty'] ?? '1'))) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1">As Of</label>
                    <input class="form-control form-control-sm" type="date" name="as_of_date" value="<?= h((string)($_GET['as_of_date'] ?? ($result['as_of'] ?? $today))) ?>">
                </div>
            <?php endif; ?>
            <?php if ($report === 'routing_plan'): ?>
                <div class="col-auto">
                    <label class="form-label mb-1">Qty</label>
                    <input class="form-control form-control-sm" type="number" step="0.0001" name="qty" value="<?= h((string)($_GET['qty'] ?? ($result['qty'] ?? '1'))) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1">As Of</label>
                    <input class="form-control form-control-sm" type="date" name="as_of_date" value="<?= h((string)($_GET['as_of_date'] ?? ($result['as_of'] ?? $today))) ?>">
                </div>
            <?php endif; ?>
            <?php if (in_array($report, ['aps_board', 'resource_utilization', 'spc'], true)): ?>
                <div class="col-auto">
                    <label class="form-label mb-1">From</label>
                    <input class="form-control form-control-sm" type="date" name="date_from" value="<?= h((string)($_GET['date_from'] ?? ($result['from_date'] ?? $defaultFrom))) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1">To</label>
                    <input class="form-control form-control-sm" type="date" name="date_to" value="<?= h((string)($_GET['date_to'] ?? ($result['to_date'] ?? $defaultTo))) ?>">
                </div>
            <?php endif; ?>
            <?php if (in_array($report, ['trace_backward', 'trace_forward'], true)): ?>
                <div class="col-md-4">
                    <label class="form-label mb-1">Lot No</label>
                    <input class="form-control form-control-sm" name="lot_no" value="<?= h((string)($_GET['lot_no'] ?? ($result['root_lot']['lot_no'] ?? ''))) ?>">
                </div>
            <?php endif; ?>
            <?php if ($report === 'spc'): ?>
                <div class="col-md-3">
                    <label class="form-label mb-1">Characteristic</label>
                    <input class="form-control form-control-sm" name="characteristic" value="<?= h((string)($_GET['characteristic'] ?? ($result['characteristic'] ?? ''))) ?>">
                </div>
            <?php endif; ?>
            <?php if ($report === 'maintenance_due'): ?>
                <div class="col-md-3">
                    <label class="form-label mb-1">As Of</label>
                    <input class="form-control form-control-sm" name="as_of_date" value="<?= h((string)($_GET['as_of_date'] ?? ($result['as_of'] ?? date('Y-m-d H:i:s')))) ?>">
                </div>
            <?php endif; ?>
            <?php if ($report === 'maintenance_risk'): ?>
                <div class="col-auto">
                    <label class="form-label mb-1">Window Hours</label>
                    <input class="form-control form-control-sm" type="number" name="window_hours" value="<?= h((string)($_GET['window_hours'] ?? ($result['window_hours'] ?? 72))) ?>">
                </div>
            <?php endif; ?>
            <?php if (in_array($report, ['inventory_reorder', 'jit_plan'], true)): ?>
                <div class="col-auto">
                    <label class="form-label mb-1">As Of</label>
                    <input class="form-control form-control-sm" type="date" name="as_of_date" value="<?= h((string)($_GET['as_of_date'] ?? ($result['as_of'] ?? $today))) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1">Horizon Days</label>
                    <input class="form-control form-control-sm" type="number" name="horizon_days" value="<?= h((string)($_GET['horizon_days'] ?? ($result['horizon_days'] ?? ($report === 'jit_plan' ? 14 : 30)))) ?>">
                </div>
            <?php endif; ?>
            <div class="col-auto align-self-end">
                <button class="btn btn-sm btn-primary">Run</button>
            </div>
        </form>

        <?php if (in_array($report, ['aps_board', 'maintenance_due', 'maintenance_risk', 'inventory_reorder', 'jit_plan'], true)): ?>
            <form method="post" class="row g-2 mb-3 no-print">
                <?php if ($report === 'aps_board'): ?>
                    <input type="hidden" name="action" value="aps_generate">
                    <div class="col-auto"><input class="form-control form-control-sm" type="date" name="date_from" value="<?= h((string)($result['from_date'] ?? $defaultFrom)) ?>"></div>
                    <div class="col-auto"><input class="form-control form-control-sm" type="date" name="date_to" value="<?= h((string)($result['to_date'] ?? $defaultTo)) ?>"></div>
                    <div class="col-auto"><select class="form-select form-select-sm" name="reschedule"><option value="0">No Reschedule</option><option value="1">Reschedule</option></select></div>
                    <div class="col-md-3"><input class="form-control form-control-sm" name="reason" value="advanced heuristic"></div>
                    <div class="col-auto"><input class="form-control form-control-sm" type="number" step="0.1" name="weight_late" value="20" title="weight late"></div>
                    <div class="col-auto"><input class="form-control form-control-sm" type="number" step="0.1" name="weight_setup" value="3" title="weight setup"></div>
                    <div class="col-auto"><input class="form-control form-control-sm" type="number" step="0.1" name="weight_load" value="1" title="weight load"></div>
                    <div class="col-auto"><input class="form-control form-control-sm" type="number" step="0.1" name="split_lot_default" value="0" title="split lot"></div>
                    <div class="col-auto"><input class="form-control form-control-sm" type="number" name="max_batches" value="100" title="max batches"></div>
                    <div class="col-auto form-check align-self-center"><input class="form-check-input" type="checkbox" name="allow_alternative" checked> <label class="form-check-label">Alt Center</label></div>
                    <div class="col-auto"><button class="btn btn-sm btn-success">Generate APS+</button></div>
                <?php elseif (in_array($report, ['maintenance_due', 'maintenance_risk'], true)): ?>
                    <input type="hidden" name="action" value="maintenance_generate_wo">
                    <div class="col-auto"><input class="form-control form-control-sm" name="as_of_date" value="<?= h(date('Y-m-d H:i:s')) ?>"></div>
                    <div class="col-auto"><input class="form-control form-control-sm" type="number" name="window_hours" value="<?= h((string)($result['window_hours'] ?? 72)) ?>"></div>
                    <div class="col-auto"><button class="btn btn-sm btn-success">Generate WO</button></div>
                <?php else: ?>
                    <input type="hidden" name="action" value="create_requisitions">
                    <div class="col-auto"><input class="form-control form-control-sm" type="date" name="as_of_date" value="<?= h((string)($result['as_of'] ?? $today)) ?>"></div>
                    <div class="col-auto"><input class="form-control form-control-sm" type="number" name="horizon_days" value="<?= h((string)($result['horizon_days'] ?? ($report === 'jit_plan' ? 14 : 30))) ?>"></div>
                    <div class="col-auto"><button class="btn btn-sm btn-success">Create PR (<?= $report === 'jit_plan' ? 'JIT' : 'ROP' ?>)</button></div>
                <?php endif; ?>
            </form>
        <?php endif; ?>

        <div class="row g-2 mb-2">
            <?php foreach ($metrics as $k => $v): ?>
                <div class="col-md-3"><div class="metric"><div class="k"><?= h($k) ?></div><div class="v"><?= h(is_numeric($v) ? n((float)$v, (abs((float)$v - (int)$v) < 0.000001 ? 0 : 4)) : (string)$v) ?></div></div></div>
            <?php endforeach; ?>
        </div>

        <div class="table-responsive bg-white border rounded">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                <tr>
                    <?php foreach ($columns as $label): ?>
                        <th><?= h($label) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($columns as $col => $_label): ?>
                            <td><?= h(is_bool($row[$col] ?? null) ? (($row[$col] ?? false) ? 'Yes' : 'No') : (string)($row[$col] ?? '')) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (!empty($warnings)): ?>
            <div class="alert alert-warning mt-2 mb-0">
                <?php foreach ($warnings as $w): ?>
                    <div><?= h((string)($w['order_no'] ?? '-')) ?>: <?= h((string)($w['warning'] ?? '')) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>
<script src="assets/global-menu.js"></script>
</body>
</html>


