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

/** @return array{view: bool, add: bool, edit: bool, delete: bool, report: bool} */
function moduleRightsOrDeny(Stock2\AuthService $authService, string $moduleKey, int $formId): array
{
    if (!$authService->hasModulePermission($moduleKey, $formId)) {
        respond(['ok' => false, 'error' => 'forbidden'], 403);
    }
    return $authService->moduleRights($moduleKey, $formId);
}

try {
    if (!$authService->isLoggedIn()) {
        respond(['ok' => false, 'error' => 'unauthorized'], 401);
    }

    $action = strtolower((string)($_GET['action'] ?? 'dashboard'));
    $payload = requestBody();
    $username = (string)($authService->user()['username'] ?? 'system');

    switch ($action) {
        case 'create_project':
            $rights = moduleRightsOrDeny($authService, 'erp_project', 22);
            if (!$rights['add']) {
                respond(['ok' => false, 'error' => 'forbidden_add'], 403);
            }
            $result = $erpService->createProject($payload, $username);
            respond(['ok' => true, 'result' => $result]);
            break;

        case 'run_project_flow':
            $rights = moduleRightsOrDeny($authService, 'erp_flow_console', 22);
            if (!$rights['add']) {
                respond(['ok' => false, 'error' => 'forbidden_add'], 403);
            }
            $projectId = (int)($payload['project_id'] ?? $_POST['project_id'] ?? $_GET['project_id'] ?? 0);
            $result = $erpService->runProjectFlow($projectId, $username);
            respond(['ok' => true, 'result' => $result]);
            break;

        case 'flow_timeline':
            $rights = moduleRightsOrDeny($authService, 'erp_flow_run', 22);
            if (!$rights['view']) {
                respond(['ok' => false, 'error' => 'forbidden_view'], 403);
            }
            $runId = (int)($_GET['run_id'] ?? $_POST['run_id'] ?? $payload['run_id'] ?? 0);
            $result = $erpService->flowTimeline($runId);
            respond(['ok' => true, 'result' => $result]);
            break;

        case 'dashboard':
        default:
            $rights = moduleRightsOrDeny($authService, 'erp_flow_run', 22);
            if (!$rights['view']) {
                respond(['ok' => false, 'error' => 'forbidden_view'], 403);
            }
            $projectLimit = (int)($_GET['project_limit'] ?? 20);
            $runLimit = (int)($_GET['run_limit'] ?? 20);
            $result = $erpService->dashboard($projectLimit, $runLimit);
            respond(['ok' => true, 'result' => $result]);
            break;
    }
} catch (Throwable $e) {
    respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);
}

