<?php

require __DIR__ . '/bootstrap.php';

/** @param mixed $value */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** @param mixed $value */
function toBool($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 'true', 'yes', 'y', 'on'], true);
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
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_module_access (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_tid INT UNSIGNED NOT NULL,
            module_key VARCHAR(100) NOT NULL,
            can_view TINYINT(1) NOT NULL DEFAULT 1,
            can_add TINYINT(1) NOT NULL DEFAULT 1,
            can_edit TINYINT(1) NOT NULL DEFAULT 1,
            can_delete TINYINT(1) NOT NULL DEFAULT 1,
            can_report TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_module (user_tid, module_key),
            KEY idx_user_tid (user_tid),
            KEY idx_module_key (module_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci'
    );
} catch (Throwable $e) {
    $error = 'create table failed: ' . $e->getMessage();
}

$users = $pdo->query('SELECT id, username, name FROM unpw ORDER BY username')->fetchAll();
$selectedUserId = (int)($_POST['uid'] ?? $_GET['uid'] ?? ($users[0]['id'] ?? 0));

/** @var array<int, array<string, mixed>> $modules */
$modules = $legacyModuleService->allModules();
usort($modules, static function (array $a, array $b): int {
    $fa = (int)($a['form_id'] ?? 0);
    $fb = (int)($b['form_id'] ?? 0);
    if ($fa !== $fb) {
        return $fa <=> $fb;
    }
    return strcmp((string)($a['key'] ?? ''), (string)($b['key'] ?? ''));
});

if (($_POST['action'] ?? '') === 'save_access' && $selectedUserId > 0 && $error === '') {
    try {
        $pdo->beginTransaction();
        $stmtDel = $pdo->prepare('DELETE FROM user_module_access WHERE user_tid = :uid');
        $stmtDel->execute(['uid' => $selectedUserId]);

        $stmtIns = $pdo->prepare(
            'INSERT INTO user_module_access
                (user_tid, module_key, can_view, can_add, can_edit, can_delete, can_report)
             VALUES
                (:uid, :module_key, :can_view, :can_add, :can_edit, :can_delete, :can_report)'
        );

        $viewMap = isset($_POST['can_view']) && is_array($_POST['can_view']) ? $_POST['can_view'] : [];
        $addMap = isset($_POST['can_add']) && is_array($_POST['can_add']) ? $_POST['can_add'] : [];
        $editMap = isset($_POST['can_edit']) && is_array($_POST['can_edit']) ? $_POST['can_edit'] : [];
        $deleteMap = isset($_POST['can_delete']) && is_array($_POST['can_delete']) ? $_POST['can_delete'] : [];
        $reportMap = isset($_POST['can_report']) && is_array($_POST['can_report']) ? $_POST['can_report'] : [];

        foreach ($modules as $module) {
            $moduleKey = (string)($module['key'] ?? '');
            if ($moduleKey === '') {
                continue;
            }

            $stmtIns->execute([
                'uid' => $selectedUserId,
                'module_key' => $moduleKey,
                'can_view' => isset($viewMap[$moduleKey]) ? 1 : 0,
                'can_add' => isset($addMap[$moduleKey]) ? 1 : 0,
                'can_edit' => isset($editMap[$moduleKey]) ? 1 : 0,
                'can_delete' => isset($deleteMap[$moduleKey]) ? 1 : 0,
                'can_report' => isset($reportMap[$moduleKey]) ? 1 : 0,
            ]);
        }

        $pdo->commit();
        $notice = 'saved permissions for user id ' . $selectedUserId;

        $currentUserId = (int)($authService->user()['id'] ?? 0);
        if ($currentUserId === $selectedUserId) {
            $authService->refreshPermissionCache();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$formPermission = [];
if ($selectedUserId > 0) {
    $stmt = $pdo->prepare('SELECT form_id, permision FROM user_access WHERE user_tid = :uid');
    $stmt->execute(['uid' => $selectedUserId]);
    foreach ($stmt->fetchAll() as $row) {
        $formPermission[(int)$row['form_id']] = toBool($row['permision'] ?? null);
    }
}

$overrides = [];
if ($selectedUserId > 0 && $error === '') {
    $stmt = $pdo->prepare('SELECT module_key, can_view, can_add, can_edit, can_delete, can_report FROM user_module_access WHERE user_tid = :uid');
    $stmt->execute(['uid' => $selectedUserId]);
    foreach ($stmt->fetchAll() as $row) {
        $overrides[(string)$row['module_key']] = $row;
    }
}

$rows = [];
foreach ($modules as $module) {
    $key = (string)($module['key'] ?? '');
    if ($key === '') {
        continue;
    }

    $formId = (int)($module['form_id'] ?? 0);
    $baseAllowed = $formId <= 0 ? true : (bool)($formPermission[$formId] ?? false);
    $override = $overrides[$key] ?? null;

    $rows[] = [
        'key' => $key,
        'title' => (string)($module['title'] ?? $key),
        'form_id' => $formId,
        'base_allowed' => $baseAllowed,
        'can_view' => $override ? toBool($override['can_view']) : $baseAllowed,
        'can_add' => $override ? toBool($override['can_add']) : $baseAllowed,
        'can_edit' => $override ? toBool($override['can_edit']) : $baseAllowed,
        'can_delete' => $override ? toBool($override['can_delete']) : $baseAllowed,
        'can_report' => $override ? toBool($override['can_report']) : $baseAllowed,
    ];
}

$formPermissionByUser = [];
$stmt = $pdo->query('SELECT user_tid, form_id, permision FROM user_access');
foreach ($stmt->fetchAll() as $row) {
    $uid = (int)($row['user_tid'] ?? 0);
    if ($uid <= 0) {
        continue;
    }
    if (!isset($formPermissionByUser[$uid])) {
        $formPermissionByUser[$uid] = [];
    }
    $formPermissionByUser[$uid][(int)$row['form_id']] = toBool($row['permision'] ?? null);
}

$moduleOverrideByUser = [];
$stmt = $pdo->query('SELECT user_tid, module_key, can_view, can_add, can_edit, can_delete, can_report FROM user_module_access');
foreach ($stmt->fetchAll() as $row) {
    $uid = (int)($row['user_tid'] ?? 0);
    $moduleKey = (string)($row['module_key'] ?? '');
    if ($uid <= 0 || $moduleKey === '') {
        continue;
    }
    if (!isset($moduleOverrideByUser[$uid])) {
        $moduleOverrideByUser[$uid] = [];
    }
    $moduleOverrideByUser[$uid][$moduleKey] = $row;
}

$userSummaries = [];
foreach ($users as $u) {
    $uid = (int)$u['id'];
    $userFormMap = $formPermissionByUser[$uid] ?? [];
    $userOverrideMap = $moduleOverrideByUser[$uid] ?? [];

    $summary = [
        'uid' => $uid,
        'username' => (string)$u['username'],
        'name' => (string)($u['name'] ?? ''),
        'view' => 0,
        'add' => 0,
        'edit' => 0,
        'delete' => 0,
        'report' => 0,
        'total_modules' => 0,
    ];

    foreach ($modules as $module) {
        $key = (string)($module['key'] ?? '');
        if ($key === '') {
            continue;
        }

        $formId = (int)($module['form_id'] ?? 0);
        $baseAllowed = $formId <= 0 ? true : (bool)($userFormMap[$formId] ?? false);
        $override = $userOverrideMap[$key] ?? null;
        $canView = $override ? toBool($override['can_view']) : $baseAllowed;
        $canAdd = $override ? toBool($override['can_add']) : $baseAllowed;
        $canEdit = $override ? toBool($override['can_edit']) : $baseAllowed;
        $canDelete = $override ? toBool($override['can_delete']) : $baseAllowed;
        $canReport = $override ? toBool($override['can_report']) : $baseAllowed;

        $summary['total_modules']++;
        if ($canView) {
            $summary['view']++;
        }
        if ($canAdd) {
            $summary['add']++;
        }
        if ($canEdit) {
            $summary['edit']++;
        }
        if ($canDelete) {
            $summary['delete']++;
        }
        if ($canReport) {
            $summary['report']++;
        }
    }

    $userSummaries[] = $summary;
}

?><!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Access | SynergyERP</title>
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
        <h3 class="m-0">Admin Module Permissions</h3>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="index.php?page=dashboard">Dashboard</a>
            <a class="btn btn-sm btn-outline-primary" href="index.php?page=module&module=setup_user">Users</a>
        </div>
    </div>

    <?php if ($notice !== ''): ?>
        <div class="alert alert-success py-2"><?= h($notice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="card mb-3">
        <div class="card-header py-2"><strong>Permission Audit By User</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>User</th>
                        <th>Name</th>
                        <th>Modules</th>
                        <th>View</th>
                        <th>Add</th>
                        <th>Edit</th>
                        <th>Delete</th>
                        <th>Report</th>
                        <th>Open</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($userSummaries as $s): ?>
                        <tr>
                            <td><?= h($s['username']) ?></td>
                            <td><?= h($s['name']) ?></td>
                            <td><?= (int)$s['total_modules'] ?></td>
                            <td><?= (int)$s['view'] ?></td>
                            <td><?= (int)$s['add'] ?></td>
                            <td><?= (int)$s['edit'] ?></td>
                            <td><?= (int)$s['delete'] ?></td>
                            <td><?= (int)$s['report'] ?></td>
                            <td><a class="btn btn-sm btn-outline-primary" href="admin_access.php?uid=<?= (int)$s['uid'] ?>">Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-md-4">
            <label class="form-label mb-1">User</label>
            <select class="form-select form-select-sm" name="uid" onchange="this.form.submit()">
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $selectedUserId ? 'selected' : '' ?>>
                        <?= h((string)$u['username']) ?> - <?= h((string)($u['name'] ?? '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <form method="post">
        <input type="hidden" name="action" value="save_access">
        <input type="hidden" name="uid" value="<?= (int)$selectedUserId ?>">

        <div class="d-flex gap-2 mb-2">
            <button class="btn btn-sm btn-primary">Save Permissions</button>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="toggleAll(true)">Check All</button>
            <button class="btn btn-sm btn-outline-secondary" type="button" onclick="toggleAll(false)">Uncheck All</button>
        </div>

        <div class="table-responsive bg-white border rounded">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Module Key</th>
                    <th>Title</th>
                    <th>Form ID</th>
                    <th>Form Access</th>
                    <th>View</th>
                    <th>Add</th>
                    <th>Edit</th>
                    <th>Delete</th>
                    <th>Report</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $idx => $r): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><code><?= h($r['key']) ?></code></td>
                        <td><?= h($r['title']) ?></td>
                        <td><?= (int)$r['form_id'] ?></td>
                        <td><?= $r['base_allowed'] ? 'Allowed' : 'Denied by form' ?></td>
                        <td class="text-center"><input class="perm-check" type="checkbox" name="can_view[<?= h($r['key']) ?>]" <?= $r['can_view'] ? 'checked' : '' ?>></td>
                        <td class="text-center"><input class="perm-check" type="checkbox" name="can_add[<?= h($r['key']) ?>]" <?= $r['can_add'] ? 'checked' : '' ?>></td>
                        <td class="text-center"><input class="perm-check" type="checkbox" name="can_edit[<?= h($r['key']) ?>]" <?= $r['can_edit'] ? 'checked' : '' ?>></td>
                        <td class="text-center"><input class="perm-check" type="checkbox" name="can_delete[<?= h($r['key']) ?>]" <?= $r['can_delete'] ? 'checked' : '' ?>></td>
                        <td class="text-center"><input class="perm-check" type="checkbox" name="can_report[<?= h($r['key']) ?>]" <?= $r['can_report'] ? 'checked' : '' ?>></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<script>
function toggleAll(state) {
    document.querySelectorAll('.perm-check').forEach(function (el) {
        el.checked = state;
    });
}
</script>
<script src="assets/global-menu.js"></script>
</body>
</html>
