<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../bootstrap.php';

/** @return array<string, mixed> */
function getRequestPayload(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

/** @param array<string, mixed> $data */
function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @param array<string, mixed> $module */
function moduleAllowsTable(array $module, string $table): bool
{
    $table = strtolower(trim($table));
    $main = strtolower(trim((string)($module['main_table'] ?? '')));
    $detail = strtolower(trim((string)($module['detail_table'] ?? '')));

    if ($main !== '' && $main === $table) {
        return true;
    }
    if ($detail !== '' && $detail === $table) {
        return true;
    }

    return false;
}

try {
    if (!$authService->isLoggedIn()) {
        respond([
            'ok' => false,
            'error' => 'unauthorized',
        ], 401);
    }

    $action = strtolower((string)($_GET['action'] ?? 'list'));
    $table = (string)($_GET['table'] ?? $_POST['table'] ?? '');
    $moduleKey = trim((string)($_GET['module_key'] ?? $_POST['module_key'] ?? ''));

    if ($table === '') {
        throw new RuntimeException('table is required');
    }

    /** @var array<string, mixed>|null $module */
    $module = null;
    $moduleRights = [
        'view' => true,
        'add' => true,
        'edit' => true,
        'delete' => true,
        'report' => true,
    ];

    if ($moduleKey !== '') {
        $module = $legacyModuleService->find($moduleKey);
        if (!$module) {
            respond(['ok' => false, 'error' => 'module_not_found'], 400);
        }

        $formId = (int)($module['form_id'] ?? 0);
        if (!$authService->hasModulePermission($moduleKey, $formId)) {
            respond(['ok' => false, 'error' => 'forbidden'], 403);
        }

        if (!moduleAllowsTable($module, $table)) {
            respond(['ok' => false, 'error' => 'forbidden_table'], 403);
        }

        $moduleRights = $authService->moduleRights($moduleKey, $formId);
    }

    switch ($action) {
        case 'schema':
            if (!$moduleRights['view']) {
                respond(['ok' => false, 'error' => 'forbidden_view'], 403);
            }

            $table = $schemaService->normalizeTable($table);
            respond([
                'ok' => true,
                'table' => $table,
                'primaryKey' => $schemaService->getPrimaryKey($table),
                'columns' => $schemaService->listColumns($table),
                'searchableColumns' => $schemaService->getSearchableColumns($table),
            ]);
            break;

        case 'row':
            if (!$moduleRights['view']) {
                respond(['ok' => false, 'error' => 'forbidden_view'], 403);
            }

            $id = (string)($_GET['id'] ?? '');
            if ($id === '') {
                throw new RuntimeException('id is required');
            }
            $row = $tableService->fetchRow($table, $id);
            respond(['ok' => true, 'row' => $row]);
            break;

        case 'save':
            $payload = getRequestPayload();
            if (isset($payload['data']) && is_array($payload['data'])) {
                $payload = $payload['data'];
            }

            if ($moduleKey !== '') {
                $normalized = $schemaService->normalizeTable($table);
                $pk = $schemaService->getPrimaryKey($normalized);
                $pkValue = isset($payload[$pk]) ? trim((string)$payload[$pk]) : '';
                $isEdit = $pkValue !== '';

                if ($isEdit && !$moduleRights['edit']) {
                    respond(['ok' => false, 'error' => 'forbidden_edit'], 403);
                }
                if (!$isEdit && !$moduleRights['add']) {
                    respond(['ok' => false, 'error' => 'forbidden_add'], 403);
                }
            }

            $result = $tableService->saveRow($table, $payload);
            respond(['ok' => true, 'result' => $result]);
            break;

        case 'delete':
            if (!$moduleRights['delete']) {
                respond(['ok' => false, 'error' => 'forbidden_delete'], 403);
            }

            $payload = getRequestPayload();
            $id = (string)($payload['id'] ?? $_POST['id'] ?? $_GET['id'] ?? '');
            if ($id === '') {
                throw new RuntimeException('id is required');
            }
            $deletedBy = (string)($authService->user()['username'] ?? '');
            $reason = (string)($payload['reason'] ?? $_POST['reason'] ?? $_GET['reason'] ?? '');
            $affected = $tableService->deleteRow($table, $id, $deletedBy, $reason);
            respond(['ok' => true, 'affected' => $affected]);
            break;

        case 'restore':
            if (!$moduleRights['edit']) {
                respond(['ok' => false, 'error' => 'forbidden_edit'], 403);
            }

            $payload = getRequestPayload();
            $id = (string)($payload['id'] ?? $_POST['id'] ?? $_GET['id'] ?? '');
            if ($id === '') {
                throw new RuntimeException('id is required');
            }
            $affected = $tableService->restoreRow($table, $id);
            respond(['ok' => true, 'affected' => $affected]);
            break;

        case 'deleted':
            if (!$moduleRights['view']) {
                respond(['ok' => false, 'error' => 'forbidden_view'], 403);
            }

            $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 200);
            $rows = $tableService->deletedRows($table, $limit);
            respond(['ok' => true, 'rows' => $rows]);
            break;

        case 'summary':
            if (!$moduleRights['view']) {
                respond(['ok' => false, 'error' => 'forbidden_view'], 403);
            }

            $summary = $tableService->summary($table);
            respond(['ok' => true, 'summary' => $summary]);
            break;

        case 'list':
        default:
            if (!$moduleRights['view']) {
                respond(['ok' => false, 'error' => 'forbidden_view'], 403);
            }

            $request = $_POST + $_GET;
            if (isset($_GET['filter_column'])) {
                $request['filter_column'] = (string)$_GET['filter_column'];
            }
            if (isset($_GET['filter_value'])) {
                $request['filter_value'] = (string)$_GET['filter_value'];
            }
            $response = $tableService->fetchForDataTable($table, $request);
            respond($response);
            break;
    }
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);
}
