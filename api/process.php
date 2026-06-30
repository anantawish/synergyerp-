<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../bootstrap.php';

/** @return array<string, mixed> */
function requestBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $_POST;
}

/** @param array<string, mixed> $data */
function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (!$authService->isLoggedIn()) {
        respond(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    $user = $authService->user();
    $username = (string)($user['username'] ?? '');

    $action = strtolower((string)($_GET['action'] ?? 'create'));
    $payload = requestBody();

    $moduleKey = (string)($payload['module_key'] ?? $_GET['module_key'] ?? '');
    if ($moduleKey === '') {
        throw new RuntimeException('module_key is required');
    }

    $module = $legacyModuleService->find($moduleKey);
    if (!$module) {
        throw new RuntimeException('module not found');
    }

    $formId = (int)($module['form_id'] ?? 0);
    if (!$authService->hasModulePermission($moduleKey, $formId)) {
        respond(['ok' => false, 'error' => 'forbidden'], 403);
    }
    $moduleRights = $authService->moduleRights($moduleKey, $formId);

    if ($action === 'create') {
        if (!$moduleRights['add']) {
            respond(['ok' => false, 'error' => 'forbidden_add'], 403);
        }

        $useMock = (bool)($payload['use_mock'] ?? false);

        if ($useMock) {
            $result = $legacyProcessService->createMock($moduleKey, $username);
            respond(['ok' => true, 'result' => $result]);
        }

        $main = $payload['main'] ?? [];
        $details = $payload['details'] ?? [];

        if (!is_array($main)) {
            throw new RuntimeException('main must be object');
        }
        if (!is_array($details)) {
            throw new RuntimeException('details must be array');
        }

        $detailRows = [];
        foreach ($details as $row) {
            if (is_array($row)) {
                $detailRows[] = $row;
            }
        }

        $result = $legacyProcessService->create($moduleKey, $main, $detailRows, $username);
        respond(['ok' => true, 'result' => $result]);
    }

    if ($action === 'delete') {
        if (!$moduleRights['delete']) {
            respond(['ok' => false, 'error' => 'forbidden_delete'], 403);
        }

        $mainId = (string)($payload['main_id'] ?? '');
        $sourceValue = isset($payload['source_value']) ? (string)$payload['source_value'] : null;

        if ($mainId === '') {
            throw new RuntimeException('main_id is required');
        }

        $result = $legacyProcessService->delete($moduleKey, $mainId, $sourceValue, $username);
        respond(['ok' => true, 'result' => $result]);
    }

    throw new RuntimeException('unknown action');
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);
}
