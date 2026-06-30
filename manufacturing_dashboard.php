<?php

require __DIR__ . '/bootstrap.php';

if (!$authService->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

if (!$authService->hasPermission(22)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$periodHours = max((int)($_GET['period_hours'] ?? 24), 1);
$utilDays = max((int)($_GET['util_days'] ?? 7), 1);
?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MFG Realtime Dashboard | SynergyERP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/global-menu.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        body { background: #f3f6fb; }
        .wrap { max-width: 1600px; margin: 0 auto; padding: 16px; }
        .metric-card { border: 1px solid #d8e0ea; border-radius: 8px; background: #fff; padding: 12px; }
        .metric-label { font-size: .82rem; color: #637486; }
        .metric-value { font-size: 1.2rem; font-weight: 700; color: #1f2b3a; }
        .chart-box { background: #fff; border: 1px solid #d8e0ea; border-radius: 10px; padding: 12px; }
        .chart-title { font-weight: 600; margin-bottom: 8px; }
        .table-box { background: #fff; border: 1px solid #d8e0ea; border-radius: 10px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="d-flex flex-wrap gap-2 mb-2">
        <a class="btn btn-sm btn-outline-secondary" href="index.php?page=dashboard">Dashboard</a>
        <a class="btn btn-sm btn-outline-primary" href="manufacturing_report.php?report=aps_board">APS Report</a>
        <a class="btn btn-sm btn-outline-primary" href="manufacturing_report.php?report=resource_utilization">Utilization Report</a>
        <a class="btn btn-sm btn-outline-success" href="manufacturing_report.php?report=maintenance_risk">Maintenance Risk</a>
        <a class="btn btn-sm btn-outline-dark" href="manufacturing_report.php?report=inventory_reorder">Reorder</a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="m-0">Manufacturing Realtime Dashboard</h3>
            <small class="text-muted">Auto refresh every 15 seconds</small>
        </div>
        <div class="d-flex gap-2">
            <div>
                <label class="form-label form-label-sm mb-1">Sensor Window (Hours)</label>
                <input id="periodHours" class="form-control form-control-sm" type="number" min="1" max="240" value="<?= (int)$periodHours ?>">
            </div>
            <div>
                <label class="form-label form-label-sm mb-1">Util Window (Days)</label>
                <input id="utilDays" class="form-control form-control-sm" type="number" min="1" max="60" value="<?= (int)$utilDays ?>">
            </div>
            <div class="align-self-end">
                <button id="btnReload" class="btn btn-sm btn-primary">Reload</button>
            </div>
        </div>
    </div>

    <div class="row g-2 mb-3" id="metricCards">
        <div class="col-6 col-md-2"><div class="metric-card"><div class="metric-label">Open Orders</div><div class="metric-value" id="mOpenOrders">-</div></div></div>
        <div class="col-6 col-md-2"><div class="metric-card"><div class="metric-label">Due 7 Days</div><div class="metric-value" id="mDue7">-</div></div></div>
        <div class="col-6 col-md-2"><div class="metric-card"><div class="metric-label">Live Operations</div><div class="metric-value" id="mLiveOps">-</div></div></div>
        <div class="col-6 col-md-2"><div class="metric-card"><div class="metric-label">PM Due</div><div class="metric-value" id="mPmDue">-</div></div></div>
        <div class="col-6 col-md-2"><div class="metric-card"><div class="metric-label">PM High Risk</div><div class="metric-value" id="mPmRisk">-</div></div></div>
        <div class="col-6 col-md-2"><div class="metric-card"><div class="metric-label">Reorder Count</div><div class="metric-value" id="mReorder">-</div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="chart-box">
                <div class="chart-title">Order Status</div>
                <canvas id="chartOrderStatus" height="180"></canvas>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-box">
                <div class="chart-title">Work Center Utilization (%)</div>
                <canvas id="chartUtil" height="180"></canvas>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-box">
                <div class="chart-title">Machine Health (Avg Vibration)</div>
                <canvas id="chartSensor" height="180"></canvas>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="chart-box">
                <div class="chart-title">Top Shortages (Recommended Qty)</div>
                <canvas id="chartShortage" height="180"></canvas>
            </div>
        </div>
    </div>

    <div class="table-box mt-3">
        <div class="p-2 border-bottom fw-semibold">Live Operations</div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0" id="liveTable">
                <thead class="table-light">
                <tr>
                    <th>Center</th>
                    <th>Order</th>
                    <th>Item</th>
                    <th>Op</th>
                    <th>Start</th>
                    <th>End</th>
                </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const apiBase = 'api/mfg.php?action=dashboard_snapshot';
    const periodInput = document.getElementById('periodHours');
    const utilInput = document.getElementById('utilDays');

    let chartOrderStatus = null;
    let chartUtil = null;
    let chartSensor = null;
    let chartShortage = null;

    function num(v) {
        const n = Number(v || 0);
        return Number.isFinite(n) ? n : 0;
    }

    function setMetric(id, value) {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = num(value).toLocaleString();
        }
    }

    function buildBarChart(el, labels, values, label, color) {
        return new Chart(el, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: label,
                    data: values,
                    backgroundColor: color,
                    borderColor: color,
                    borderWidth: 1,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } },
            },
        });
    }

    function buildDoughnutChart(el, labels, values) {
        return new Chart(el, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#6b7280', '#8b5cf6'],
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
            },
        });
    }

    function renderLiveTable(rows) {
        const tbody = document.querySelector('#liveTable tbody');
        if (!tbody) return;
        tbody.innerHTML = '';

        (rows || []).forEach(function (row) {
            const tr = document.createElement('tr');
            tr.innerHTML = ''
                + '<td>' + (row.work_center_code || '') + '</td>'
                + '<td>' + (row.order_no || '') + '</td>'
                + '<td>' + (row.item_code || '') + '</td>'
                + '<td>' + (row.op_no || '') + ' - ' + (row.operation_name || '') + '</td>'
                + '<td>' + (row.planned_start || '') + '</td>'
                + '<td>' + (row.planned_end || '') + '</td>';
            tbody.appendChild(tr);
        });
    }

    async function loadDashboard() {
        const period = Math.max(parseInt(periodInput.value || '24', 10), 1);
        const util = Math.max(parseInt(utilInput.value || '7', 10), 1);
        const url = apiBase + '&period_hours=' + encodeURIComponent(period) + '&util_days=' + encodeURIComponent(util);

        const res = await fetch(url, { credentials: 'same-origin' });
        const json = await res.json();
        if (!res.ok || json.ok === false) {
            throw new Error((json && json.error) || 'dashboard load failed');
        }

        const data = json.result || {};
        const summary = data.summary || {};
        setMetric('mOpenOrders', summary.open_orders || 0);
        setMetric('mDue7', summary.due_7d || 0);
        setMetric('mLiveOps', summary.live_operations || 0);
        setMetric('mPmDue', summary.maintenance_due || 0);
        setMetric('mPmRisk', summary.maintenance_high_risk || 0);
        setMetric('mReorder', summary.reorder_count || 0);

        const statusRows = data.order_status || [];
        const statusLabels = statusRows.map(r => r.status || '-');
        const statusValues = statusRows.map(r => num(r.count));
        if (chartOrderStatus) chartOrderStatus.destroy();
        chartOrderStatus = buildDoughnutChart(document.getElementById('chartOrderStatus'), statusLabels, statusValues);

        const utilRows = data.utilization || [];
        if (chartUtil) chartUtil.destroy();
        chartUtil = buildBarChart(
            document.getElementById('chartUtil'),
            utilRows.map(r => r.work_center_code || '-'),
            utilRows.map(r => num(r.utilization_pct)),
            'Utilization %',
            '#2563eb'
        );

        const sensorRows = data.sensor_health || [];
        if (chartSensor) chartSensor.destroy();
        chartSensor = buildBarChart(
            document.getElementById('chartSensor'),
            sensorRows.map(r => r.machine_code || '-'),
            sensorRows.map(r => num(r.avg_vibration)),
            'Avg Vibration',
            '#dc2626'
        );

        const shortageRows = data.top_shortages || [];
        if (chartShortage) chartShortage.destroy();
        chartShortage = buildBarChart(
            document.getElementById('chartShortage'),
            shortageRows.map(r => r.item_code || '-'),
            shortageRows.map(r => num(r.recommended_qty)),
            'Recommended Qty',
            '#f59e0b'
        );

        renderLiveTable(data.live_operations || []);
    }

    document.getElementById('btnReload').addEventListener('click', function () {
        loadDashboard().catch(err => alert(err.message || 'Load failed'));
    });

    loadDashboard().catch(err => alert(err.message || 'Load failed'));
    setInterval(function () {
        loadDashboard().catch(function () {});
    }, 15000);
})();
</script>
<script src="assets/global-menu.js"></script>
</body>
</html>
