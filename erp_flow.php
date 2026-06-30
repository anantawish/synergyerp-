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

if (!$authService->hasModulePermission('erp_flow_console', 22)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$user = $authService->user();
$username = (string)($user['username'] ?? 'system');

$notice = '';
$error = '';
$selectedRunId = (int)($_GET['run_id'] ?? $_POST['run_id'] ?? 0);

if (($_POST['action'] ?? '') === 'create_project') {
    try {
        $projectRights = $authService->moduleRights('erp_project', 22);
        if (!$projectRights['add']) {
            throw new RuntimeException('no add permission for project');
        }

        $res = $erpService->createProject([
            'project_code' => (string)($_POST['project_code'] ?? ''),
            'project_name' => (string)($_POST['project_name'] ?? ''),
            'customer_name' => (string)($_POST['customer_name'] ?? ''),
            'product_code' => (string)($_POST['product_code'] ?? ''),
            'product_name' => (string)($_POST['product_name'] ?? ''),
            'plan_qty' => (string)($_POST['plan_qty'] ?? ''),
            'uom' => (string)($_POST['uom'] ?? 'PCS'),
            'start_date' => (string)($_POST['start_date'] ?? ''),
            'due_date' => (string)($_POST['due_date'] ?? ''),
            'status' => 'PLANNED',
        ], $username);

        $notice = 'Project created: ' . (string)($res['project_code'] ?? '');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (($_POST['action'] ?? '') === 'run_flow') {
    try {
        $flowRights = $authService->moduleRights('erp_flow_console', 22);
        if (!$flowRights['add']) {
            throw new RuntimeException('no add permission for flow');
        }

        $projectId = (int)($_POST['project_id'] ?? 0);
        $res = $erpService->runProjectFlow($projectId, $username);
        $selectedRunId = (int)($res['run']['id'] ?? 0);
        $notice = 'Flow completed: ' . (string)($res['run']['run_no'] ?? '');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$dashboard = $erpService->dashboard(50, 50);
$projects = $dashboard['projects'] ?? [];
$runs = $dashboard['runs'] ?? [];
$summary = $dashboard['summary'] ?? [];

$timeline = null;
if ($selectedRunId > 0) {
    try {
        $timeline = $erpService->flowTimeline($selectedRunId);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ERP Flow Console | SynergyERP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/global-menu.css">
    <style>
        body { background: #f1f5f9; }
        .wrap { max-width: 1600px; margin: 0 auto; padding: 14px; }
        .metric { background: #fff; border: 1px solid #d8e0ea; border-radius: 8px; padding: 10px; }
        .metric .k { font-size: 0.8rem; color: #5d6f81; }
        .metric .v { font-weight: 700; font-size: 1rem; }
        .table th { white-space: nowrap; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="d-flex flex-wrap gap-2 mb-2">
        <a class="btn btn-sm btn-outline-secondary" href="index.php?page=dashboard">Dashboard</a>
        <a class="btn btn-sm btn-outline-primary" href="index.php?page=module&module=erp_project">Projects</a>
        <a class="btn btn-sm btn-outline-primary" href="index.php?page=module&module=erp_flow_run">Flow Runs</a>
        <a class="btn btn-sm btn-outline-primary" href="business_report.php?report=gl_balance_sheet">Balance Sheet</a>
        <a class="btn btn-sm btn-outline-primary" href="capture_screen.php">Screen Capture</a>
        <a class="btn btn-sm btn-outline-primary" href="docs/user_manual_departments.html" target="_blank">Dept Manual</a>
        <a class="btn btn-sm btn-outline-dark" href="department_access.php">Department Access</a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="m-0">ERP Full Flow Console</h3>
        <small class="text-muted">Project -> Purchase -> Main WH -> Production WH -> FG -> Packing -> Shipment -> Sales -> GL -> Balance Sheet</small>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success py-2"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="row g-2 mb-3">
        <div class="col-md-2"><div class="metric"><div class="k">Projects</div><div class="v"><?= n($summary['projects_total'] ?? 0, 0) ?></div></div></div>
        <div class="col-md-2"><div class="metric"><div class="k">Runs</div><div class="v"><?= n($summary['runs_total'] ?? 0, 0) ?></div></div></div>
        <div class="col-md-2"><div class="metric"><div class="k">Done</div><div class="v"><?= n($summary['runs_done'] ?? 0, 0) ?></div></div></div>
        <div class="col-md-2"><div class="metric"><div class="k">Failed</div><div class="v"><?= n($summary['runs_failed'] ?? 0, 0) ?></div></div></div>
        <div class="col-md-2"><div class="metric"><div class="k">Running</div><div class="v"><?= n($summary['runs_running'] ?? 0, 0) ?></div></div></div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Create Project</strong></div>
        <div class="card-body">
            <form method="post" class="row g-2">
                <input type="hidden" name="action" value="create_project">
                <div class="col-md-2">
                    <label class="form-label mb-1">Project Code</label>
                    <input class="form-control form-control-sm" name="project_code" placeholder="Auto if empty">
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1">Project Name</label>
                    <input class="form-control form-control-sm" name="project_name" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Customer</label>
                    <input class="form-control form-control-sm" name="customer_name">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1">Product Code</label>
                    <input class="form-control form-control-sm" name="product_code" value="A" required>
                </div>
                <div class="col-md-1">
                    <label class="form-label mb-1">Qty</label>
                    <input class="form-control form-control-sm" type="number" step="0.0001" name="plan_qty" value="100">
                </div>
                <div class="col-md-1">
                    <label class="form-label mb-1">Start</label>
                    <input class="form-control form-control-sm" type="date" name="start_date" value="<?= h(date('Y-m-d')) ?>">
                </div>
                <div class="col-md-1">
                    <label class="form-label mb-1">Due</label>
                    <input class="form-control form-control-sm" type="date" name="due_date" value="<?= h(date('Y-m-d', strtotime('+14 days'))) ?>">
                </div>
                <div class="col-12">
                    <button class="btn btn-sm btn-primary">Create Project</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Run Full ERP Flow (Product A Template)</strong></div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="action" value="run_flow">
                <div class="col-md-5">
                    <label class="form-label mb-1">Project</label>
                    <select class="form-select form-select-sm" name="project_id" required>
                        <option value="">-- select project --</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?= (int)$p['id'] ?>">
                                <?= h((string)$p['project_code'] . ' | ' . (string)$p['project_name'] . ' | ' . (string)$p['product_code'] . ' | Qty ' . (string)$p['plan_qty']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-success">Run End-to-End Flow</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><strong>Latest Flow Runs</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Run No</th>
                                <th>Project</th>
                                <th>Product</th>
                                <th>Status</th>
                                <th>Started</th>
                                <th>Completed</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($runs as $r): ?>
                                <tr>
                                    <td><?= h($r['run_no']) ?></td>
                                    <td><?= h($r['project_code']) ?></td>
                                    <td><?= h($r['product_code']) ?></td>
                                    <td><?= h($r['status']) ?></td>
                                    <td><?= h($r['started_at']) ?></td>
                                    <td><?= h((string)($r['completed_at'] ?? '')) ?></td>
                                    <td><a class="btn btn-sm btn-outline-primary" href="erp_flow.php?run_id=<?= (int)$r['id'] ?>">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><strong>Projects</strong></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Project Code</th>
                                <th>Name</th>
                                <th>Product</th>
                                <th>Qty</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($projects as $p): ?>
                                <tr>
                                    <td><?= h($p['project_code']) ?></td>
                                    <td><?= h($p['project_name']) ?></td>
                                    <td><?= h($p['product_code']) ?></td>
                                    <td class="text-end"><?= n($p['plan_qty'], 2) ?></td>
                                    <td><?= h($p['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (is_array($timeline)): ?>
        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Run Timeline: <?= h((string)($timeline['run']['run_no'] ?? '')) ?></strong>
                <span class="badge text-bg-secondary"><?= h((string)($timeline['run']['status'] ?? '-')) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Seq</th>
                            <th>Stage</th>
                            <th>Module</th>
                            <th>Ref ID</th>
                            <th>Ref No</th>
                            <th>Status</th>
                            <th>Note</th>
                            <th>Time</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($timeline['steps'] ?? []) as $s): ?>
                            <tr>
                                <td><?= h($s['seq_no']) ?></td>
                                <td><?= h($s['stage_name']) ?></td>
                                <td><?= h((string)($s['module_key'] ?? '')) ?></td>
                                <td><?= h((string)($s['ref_id'] ?? '')) ?></td>
                                <td><?= h((string)($s['ref_no'] ?? '')) ?></td>
                                <td><?= h($s['status']) ?></td>
                                <td><?= h((string)($s['note'] ?? '')) ?></td>
                                <td><?= h((string)($s['event_time'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<script src="assets/global-menu.js"></script>
</body>
</html>
