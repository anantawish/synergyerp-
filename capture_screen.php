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

$moduleKey = 'erp_screen_capture';
$formId = 22;
if (!$authService->hasModulePermission($moduleKey, $formId)) {
    http_response_code(403);
    echo 'forbidden';
    exit;
}

$rights = $authService->moduleRights($moduleKey, $formId);
$pdo = $database->pdo();
$notice = '';
$error = '';

try {
    $pdo->exec(file_get_contents(__DIR__ . '/scripts/migrate_erp_core.sql') ?: '');
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$uploadRoot = __DIR__ . '/storage/captures';
if (!is_dir($uploadRoot)) {
    @mkdir($uploadRoot, 0777, true);
}

if (($_POST['action'] ?? '') === 'upload') {
    try {
        if (!$rights['add']) {
            throw new RuntimeException('forbidden_add');
        }

        if (!isset($_FILES['capture_image']) || !is_array($_FILES['capture_image'])) {
            throw new RuntimeException('capture_image is required');
        }
        $file = $_FILES['capture_image'];
        $tmp = (string)($file['tmp_name'] ?? '');
        $fileError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($fileError !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('upload failed');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0 || $size > (15 * 1024 * 1024)) {
            throw new RuntimeException('invalid image size (max 15MB)');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/bmp' => 'bmp',
        ];
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('invalid image type');
        }

        $stage = trim((string)($_POST['process_stage'] ?? ''));
        $screenName = trim((string)($_POST['screen_name'] ?? ''));
        if ($screenName === '') {
            throw new RuntimeException('screen_name is required');
        }

        $captureNo = 'CAP-' . date('YmdHis') . '-' . random_int(100, 999);
        $subDir = date('Y/m');
        $targetDir = $uploadRoot . '/' . $subDir;
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            throw new RuntimeException('cannot create target directory');
        }

        $ext = $allowed[$mime];
        $targetName = $captureNo . '.' . $ext;
        $targetFull = $targetDir . '/' . $targetName;
        if (!move_uploaded_file($tmp, $targetFull)) {
            throw new RuntimeException('cannot move upload file');
        }

        $imageWidth = null;
        $imageHeight = null;
        $imageInfo = @getimagesize($targetFull);
        if (is_array($imageInfo)) {
            $imageWidth = (int)($imageInfo[0] ?? 0);
            $imageHeight = (int)($imageInfo[1] ?? 0);
        }

        $capturedAtInput = trim((string)($_POST['captured_at'] ?? ''));
        $capturedAt = $capturedAtInput === '' ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime($capturedAtInput));
        if ($capturedAt === '1970-01-01 07:00:00' && $capturedAtInput !== '1970-01-01T00:00') {
            throw new RuntimeException('invalid captured_at');
        }

        $tableService->saveRow('erp_screen_capture', [
            'capture_no' => $captureNo,
            'module_key' => trim((string)($_POST['module_key'] ?? '')),
            'screen_name' => $screenName,
            'process_stage' => $stage,
            'department_code' => trim((string)($_POST['department_code'] ?? '')),
            'project_code' => trim((string)($_POST['project_code'] ?? '')),
            'run_no' => trim((string)($_POST['run_no'] ?? '')),
            'doc_ref' => trim((string)($_POST['doc_ref'] ?? '')),
            'file_name' => $targetName,
            'file_path' => 'storage/captures/' . str_replace('\\', '/', $subDir) . '/' . $targetName,
            'mime_type' => $mime,
            'file_size' => $size,
            'image_width' => $imageWidth,
            'image_height' => $imageHeight,
            'note' => trim((string)($_POST['note'] ?? '')),
            'captured_at' => $capturedAt,
            'captured_by' => (string)($authService->user()['username'] ?? ''),
        ]);

        $notice = 'Capture uploaded: ' . $captureNo;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (($_POST['action'] ?? '') === 'delete_capture') {
    try {
        if (!$rights['delete']) {
            throw new RuntimeException('forbidden_delete');
        }
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('id is required');
        }
        $tableService->deleteRow('erp_screen_capture', $id, (string)($authService->user()['username'] ?? ''), 'manual capture delete');
        $notice = 'Capture deleted (soft delete): ' . $id;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$modules = $legacyModuleService->allModules();
usort($modules, static function (array $a, array $b): int {
    return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
});

$departments = $pdo->query('SELECT department_code, department_name FROM erp_department WHERE is_active = 1 ORDER BY department_code')->fetchAll();
$rows = $pdo->query(
    "SELECT c.*
     FROM erp_screen_capture c
     WHERE NOT EXISTS (
        SELECT 1 FROM app_soft_delete sd
        WHERE sd.table_name = 'erp_screen_capture'
          AND sd.pk_value = CAST(c.id AS CHAR)
     )
     ORDER BY c.id DESC
     LIMIT 300"
)->fetchAll();

$deletedCount = (int)$pdo->query("SELECT COUNT(*) FROM app_soft_delete WHERE table_name = 'erp_screen_capture'")->fetchColumn();

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Screen Capture Log | SynergyERP</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/global-menu.css">
    <style>
        body { background: #f2f6fb; }
        .wrap { max-width: 1700px; margin: 0 auto; padding: 14px; }
        .table td, .table th { vertical-align: middle; font-size: .86rem; }
        .thumb { width: 120px; max-height: 68px; object-fit: cover; border: 1px solid #d8e0ea; border-radius: 6px; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h3 class="m-0">Screen Capture Log</h3>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="index.php?page=dashboard">Dashboard</a>
            <a class="btn btn-sm btn-outline-primary" href="erp_flow.php">ERP Flow</a>
            <a class="btn btn-sm btn-outline-primary" href="department_access.php">Department Access</a>
            <a class="btn btn-sm btn-outline-dark" href="docs/user_manual_departments.html" target="_blank">Dept Manual</a>
            <a class="btn btn-sm btn-outline-dark" href="docs/user_manual_full.html" target="_blank">User Manual</a>
        </div>
    </div>

    <div class="small text-muted mb-2">
        Rights: view=<strong><?= $rights['view'] ? 'Y' : 'N' ?></strong>,
        add=<strong><?= $rights['add'] ? 'Y' : 'N' ?></strong>,
        edit=<strong><?= $rights['edit'] ? 'Y' : 'N' ?></strong>,
        delete=<strong><?= $rights['delete'] ? 'Y' : 'N' ?></strong>,
        report=<strong><?= $rights['report'] ? 'Y' : 'N' ?></strong>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success py-2"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="card mb-3">
        <div class="card-header"><strong>Upload Capture</strong></div>
        <div class="card-body">
            <?php if (!$rights['add']): ?>
                <div class="alert alert-warning py-2 mb-0">No add permission for this module.</div>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data" class="row g-2">
                    <input type="hidden" name="action" value="upload">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Module</label>
                        <select class="form-select form-select-sm" name="module_key">
                            <option value="">-- select module --</option>
                            <?php foreach ($modules as $m): ?>
                                <option value="<?= h((string)$m['key']) ?>">
                                    <?= h((string)$m['key'] . ' | ' . (string)$m['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Screen Name</label>
                        <input class="form-control form-control-sm" name="screen_name" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Process Stage</label>
                        <input class="form-control form-control-sm" name="process_stage" placeholder="WH_STOCK_IN">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Department</label>
                        <select class="form-select form-select-sm" name="department_code">
                            <option value="">-- select --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= h((string)$d['department_code']) ?>">
                                    <?= h((string)$d['department_code'] . ' | ' . (string)$d['department_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Captured At</label>
                        <input class="form-control form-control-sm" type="datetime-local" name="captured_at">
                    </div>

                    <div class="col-md-2">
                        <label class="form-label mb-1">Project Code</label>
                        <input class="form-control form-control-sm" name="project_code">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Run No</label>
                        <input class="form-control form-control-sm" name="run_no">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Doc Ref</label>
                        <input class="form-control form-control-sm" name="doc_ref">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-1">Note</label>
                        <input class="form-control form-control-sm" name="note" maxlength="255">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label mb-1">Image File</label>
                        <input class="form-control form-control-sm" type="file" name="capture_image" accept="image/*" required>
                    </div>

                    <div class="col-12 d-flex justify-content-end">
                        <button class="btn btn-sm btn-primary">Upload Capture</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Capture History</strong>
            <small class="text-muted">Active: <?= count($rows) ?> | Deleted(soft): <?= $deletedCount ?></small>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-bordered table-striped mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Capture No</th>
                        <th>Image</th>
                        <th>Module</th>
                        <th>Screen</th>
                        <th>Stage</th>
                        <th>Department</th>
                        <th>Project/Run/Doc</th>
                        <th>Captured</th>
                        <th>By</th>
                        <th>Note</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $path = (string)($row['file_path'] ?? '');
                        $exists = $path !== '' && is_file(__DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $path));
                        ?>
                        <tr>
                            <td><?= h($row['id']) ?></td>
                            <td class="mono"><?= h($row['capture_no']) ?></td>
                            <td>
                                <?php if ($path !== ''): ?>
                                    <a href="<?= h($path) ?>" target="_blank" rel="noopener">
                                        <?php if ($exists): ?>
                                            <img class="thumb" src="<?= h($path) ?>" alt="capture">
                                        <?php else: ?>
                                            <span class="small text-danger">file missing</span>
                                        <?php endif; ?>
                                    </a>
                                <?php endif; ?>
                                <div class="small text-muted mono"><?= h((string)($row['mime_type'] ?? '')) ?> | <?= h((string)($row['file_size'] ?? 0)) ?> bytes</div>
                            </td>
                            <td class="mono"><?= h($row['module_key']) ?></td>
                            <td><?= h($row['screen_name']) ?></td>
                            <td class="mono"><?= h($row['process_stage']) ?></td>
                            <td><?= h($row['department_code']) ?></td>
                            <td class="mono">
                                <?= h($row['project_code']) ?><br>
                                <?= h($row['run_no']) ?><br>
                                <?= h($row['doc_ref']) ?>
                            </td>
                            <td class="mono"><?= h($row['captured_at']) ?></td>
                            <td><?= h($row['captured_by']) ?></td>
                            <td><?= h($row['note']) ?></td>
                            <td>
                                <?php if ($rights['delete']): ?>
                                    <form method="post" onsubmit="return confirm('Soft delete this capture?');">
                                        <input type="hidden" name="action" value="delete_capture">
                                        <input type="hidden" name="id" value="<?= h($row['id']) ?>">
                                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="small text-muted">read only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>
<script src="assets/global-menu.js"></script>
</body>
</html>
