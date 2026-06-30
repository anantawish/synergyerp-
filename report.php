<?php

require __DIR__ . '/bootstrap.php';

/** @param mixed $value */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** @param mixed $value */
function formatScalar($value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_scalar($value)) {
        return (string)$value;
    }

    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? '' : $json;
}

/** @param mixed $value */
function formatNumber($value, int $decimals = 2): string
{
    return number_format((float)$value, $decimals);
}

if (!$authService->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$moduleKey = trim((string)($_GET['module'] ?? ''));
if ($moduleKey === '') {
    http_response_code(400);
    echo 'module is required';
    exit;
}

$module = $legacyModuleService->find($moduleKey);
if (!$module) {
    http_response_code(404);
    echo 'module not found';
    exit;
}

$formId = (int)($module['form_id'] ?? 0);
if (!$authService->hasModulePermission($moduleKey, $formId)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}
$moduleRights = $authService->moduleRights($moduleKey, $formId);
if (!$moduleRights['report']) {
    http_response_code(403);
    echo 'forbidden report';
    exit;
}

$company = $reportService->company();
$reportTitle = (string)($module['title'] ?? $moduleKey);
$mainId = trim((string)($_GET['id'] ?? ''));
$mode = trim((string)($_GET['mode'] ?? ''));
if ($mode === '') {
    $mode = $mainId !== '' ? 'doc' : 'list';
}
if ($mode !== 'doc' && $mode !== 'list') {
    $mode = 'list';
}

$isPlaceholder = (($module['mode'] ?? '') === 'placeholder');
$isDoc = false;
$doc = null;
$list = null;
$error = '';
$listPk = '';

$filters = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
    'limit' => trim((string)($_GET['limit'] ?? '300')),
];

if (!$isPlaceholder) {
    try {
        if ($mode === 'doc' && $mainId !== '') {
            $doc = $reportService->documentReport($moduleKey, $mainId);
            $isDoc = true;
        } else {
            $list = $reportService->listReport($moduleKey, $filters);
            $isDoc = false;
            $listPk = $schemaService->getPrimaryKey((string)$list['main_table']);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$displayColumns = is_array($list['display_columns'] ?? null) ? $list['display_columns'] : (is_array($list['columns'] ?? null) ? $list['columns'] : []);
$columnLabels = is_array($list['column_labels'] ?? null) ? $list['column_labels'] : [];
$detailLabels = is_array($doc['detail_column_labels'] ?? null) ? $doc['detail_column_labels'] : [];

?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($reportTitle) ?> | SynergyERP Report</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/report.css">
    <link rel="stylesheet" href="assets/global-menu.css">
</head>
<body>
<div class="report-shell">
    <div class="report-toolbar no-print">
        <a class="btn btn-sm btn-outline-secondary" href="index.php?page=module&amp;module=<?= urlencode($moduleKey) ?>">Back</a>
        <a class="btn btn-sm btn-outline-primary" href="report.php?module=<?= urlencode($moduleKey) ?>&amp;mode=list">List View</a>
        <?php if ($mainId !== ''): ?>
            <a class="btn btn-sm btn-outline-primary" href="report.php?module=<?= urlencode($moduleKey) ?>&amp;id=<?= urlencode($mainId) ?>">Doc View</a>
        <?php endif; ?>
        <?php if (!$isPlaceholder && $error === ''): ?>
            <button class="btn btn-sm btn-primary" onclick="window.print()">Print / Save PDF</button>
        <?php endif; ?>
    </div>

    <?php if (!$isPlaceholder && !$isDoc): ?>
        <form class="report-filter no-print" method="get" action="report.php">
            <input type="hidden" name="module" value="<?= h($moduleKey) ?>">
            <input type="hidden" name="mode" value="list">
            <div>
                <label class="form-label mb-1">Search</label>
                <input class="form-control form-control-sm" name="q" value="<?= h($filters['q']) ?>" placeholder="keyword">
            </div>
            <div>
                <label class="form-label mb-1">Date From</label>
                <input class="form-control form-control-sm" type="date" name="date_from" value="<?= h($filters['date_from']) ?>">
            </div>
            <div>
                <label class="form-label mb-1">Date To</label>
                <input class="form-control form-control-sm" type="date" name="date_to" value="<?= h($filters['date_to']) ?>">
            </div>
            <div>
                <label class="form-label mb-1">Limit</label>
                <input class="form-control form-control-sm" type="number" min="1" max="2000" name="limit" value="<?= h($filters['limit']) ?>">
            </div>
            <div class="align-self-end">
                <button class="btn btn-sm btn-dark w-100">Apply Filter</button>
            </div>
        </form>
    <?php endif; ?>

    <section class="paper">
        <header class="report-header">
            <div>
                <h1><?= h($company['cname'] ?? 'SynergyERP') ?></h1>
                <div class="meta-line"><?= h($company['address'] ?? '') ?> <?= h($company['amper'] ?? '') ?> <?= h($company['province'] ?? '') ?> <?= h($company['zipcode'] ?? '') ?></div>
                <div class="meta-line">Tel: <?= h($company['tel'] ?? '-') ?> | Tax ID: <?= h($company['taxid'] ?? '-') ?></div>
            </div>
            <div class="header-right">
                <div class="report-badge">LEGACY 1:1 REPORT</div>
                <h2><?= h($reportTitle) ?></h2>
                <div class="meta-line">Mode: <?= h(strtoupper($mode)) ?> | Module: <?= h($moduleKey) ?></div>
            </div>
        </header>

        <?php if ($isPlaceholder): ?>
            <div class="placeholder-box">
                <h5 class="mb-2">Placeholder Report</h5>
                <div>This module does not have document logic in legacy source code.</div>
                <div class="mt-2 text-muted"><?= h((string)($module['message'] ?? '')) ?></div>
            </div>
        <?php elseif ($error !== ''): ?>
            <div class="alert alert-danger">
                <h5 class="mb-2">Report Error</h5>
                <pre class="mb-0"><?= h($error) ?></pre>
            </div>
        <?php elseif ($isDoc): ?>
            <section class="doc-meta-grid">
                <div class="meta-box"><label>Document No.</label><span class="value"><?= h(formatScalar($doc['doc_no'])) ?></span></div>
                <div class="meta-box"><label>Document Date</label><span class="value"><?= h(formatScalar($doc['doc_date'])) ?></span></div>
                <div class="meta-box"><label>Party Code</label><span class="value"><?= h(formatScalar($doc['party_code'])) ?></span></div>
                <div class="meta-box"><label>Party Name</label><span class="value"><?= h(formatScalar($doc['party_name'])) ?></span></div>
                <div class="meta-box"><label>Main Total</label><span class="value"><?= h(formatNumber($doc['main_total'] ?? 0)) ?></span></div>
                <div class="meta-box wide"><label>Note</label><span class="value"><?= h(formatScalar($doc['note'])) ?></span></div>
            </section>

            <?php if (!empty($doc['main_fields'])): ?>
                <h3 class="section-title">Document Fields</h3>
                <section class="field-grid">
                    <?php foreach ($doc['main_fields'] as $field): ?>
                        <div class="field-card">
                            <label><?= h($field['label'] ?? ($field['column'] ?? '')) ?></label>
                            <div class="value"><?= h(formatScalar($field['value'] ?? '')) ?></div>
                        </div>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if (!empty($doc['detail_rows'])): ?>
                <h3 class="section-title">Detail Items</h3>
                <table class="table table-bordered table-sm report-table">
                    <thead>
                    <tr>
                        <th style="width:42px">#</th>
                        <?php foreach ($doc['detail_display_columns'] as $col): ?>
                            <th><?= h($detailLabels[$col] ?? $col) ?></th>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($doc['detail_rows'] as $idx => $row): ?>
                        <tr>
                            <td class="text-end"><?= $idx + 1 ?></td>
                            <?php foreach ($doc['detail_display_columns'] as $col): ?>
                                <td><?= h(formatScalar($row[$col] ?? '')) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="alert alert-warning">No detail rows.</div>
            <?php endif; ?>

            <section class="totals-box">
                <div><span>Total Qty:</span> <strong><?= formatNumber($doc['detail_totals']['qty'] ?? 0) ?></strong></div>
                <div><span>Total Amount:</span> <strong><?= formatNumber($doc['detail_totals']['amount'] ?? 0) ?></strong></div>
                <div><span>Main Total:</span> <strong><?= formatNumber($doc['main_total'] ?? 0) ?></strong></div>
            </section>

            <section class="signature-grid">
                <div class="signature-box">Prepared By<br><br>__________________________</div>
                <div class="signature-box">Checked By<br><br>__________________________</div>
                <div class="signature-box">Approved By<br><br>__________________________</div>
            </section>
        <?php else: ?>
            <section class="list-summary">
                <div class="summary-card"><label>Rows</label><span class="value"><?= number_format((int)($list['count'] ?? 0)) ?></span></div>
                <div class="summary-card"><label>Table</label><span class="value"><?= h((string)($list['main_table'] ?? '')) ?></span></div>
                <div class="summary-card"><label>Date Column</label><span class="value"><?= h((string)($list['date_column'] ?? '-')) ?></span></div>
                <?php foreach (($list['summary'] ?? []) as $col => $sum): ?>
                    <div class="summary-card"><label>SUM <?= h($columnLabels[$col] ?? $col) ?></label><span class="value"><?= formatNumber($sum) ?></span></div>
                <?php endforeach; ?>
            </section>

            <table class="table table-bordered table-sm report-table">
                <thead>
                <tr>
                    <th style="width:42px">#</th>
                    <?php foreach ($displayColumns as $col): ?>
                        <th><?= h($columnLabels[$col] ?? $col) ?></th>
                    <?php endforeach; ?>
                    <?php if ($listPk !== ''): ?>
                        <th style="width:84px" class="no-print">Doc</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach (($list['rows'] ?? []) as $idx => $row): ?>
                    <tr>
                        <td class="text-end"><?= $idx + 1 ?></td>
                        <?php foreach ($displayColumns as $col): ?>
                            <td><?= h(formatScalar($row[$col] ?? '')) ?></td>
                        <?php endforeach; ?>
                        <?php if ($listPk !== ''): ?>
                            <td class="no-print text-center">
                                <?php $idValue = (string)($row[$listPk] ?? ''); ?>
                                <?php if ($idValue !== ''): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="report.php?module=<?= urlencode($moduleKey) ?>&amp;id=<?= urlencode($idValue) ?>">Doc</a>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <footer class="report-footer">
            Generated at <?= h(date('Y-m-d H:i:s')) ?> by <?= h((string)($authService->user()['username'] ?? '')) ?>
        </footer>
    </section>
</div>
<script src="assets/global-menu.js"></script>
</body>
</html>
