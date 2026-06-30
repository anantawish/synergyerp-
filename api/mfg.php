<?php

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../bootstrap.php';

/** @return array<string, mixed> */
function requestPayload(): array
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
function out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** @param array<string, mixed> $payload */
function boolParam(array $payload, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $payload)) {
        return $default;
    }

    $raw = $payload[$key];
    if (is_bool($raw)) {
        return $raw;
    }

    $text = strtolower(trim((string)$raw));
    return in_array($text, ['1', 'true', 'yes', 'y', 'on'], true);
}

try {
    if (!$authService->isLoggedIn()) {
        out([
            'ok' => false,
            'error' => 'unauthorized',
        ], 401);
    }

    $action = strtolower((string)($_GET['action'] ?? ''));
    if ($action === '') {
        throw new RuntimeException('action is required');
    }

    $payload = requestPayload();

    switch ($action) {
        case 'bom_explosion':
            $result = $manufacturingService->bomExplosion(
                (string)($_GET['item_code'] ?? $payload['item_code'] ?? ''),
                (float)($_GET['order_qty'] ?? $payload['order_qty'] ?? 1),
                (string)($_GET['as_of_date'] ?? $payload['as_of_date'] ?? '')
            );
            out(['ok' => true, 'result' => $result]);
            break;

        case 'routing_plan':
            $result = $manufacturingService->routingPlan(
                (string)($_GET['item_code'] ?? $payload['item_code'] ?? ''),
                (float)($_GET['qty'] ?? $payload['qty'] ?? 1),
                (string)($_GET['as_of_date'] ?? $payload['as_of_date'] ?? '')
            );
            out(['ok' => true, 'result' => $result]);
            break;

        case 'aps_generate':
            $advanced = boolParam($payload + $_GET, 'advanced', true);
            if ($advanced) {
                $options = [
                    'advanced' => true,
                    'weight_late' => (float)($_GET['weight_late'] ?? $payload['weight_late'] ?? 20),
                    'weight_setup' => (float)($_GET['weight_setup'] ?? $payload['weight_setup'] ?? 3),
                    'weight_load' => (float)($_GET['weight_load'] ?? $payload['weight_load'] ?? 1),
                    'split_lot_default' => (float)($_GET['split_lot_default'] ?? $payload['split_lot_default'] ?? 0),
                    'allow_alternative' => boolParam($payload + $_GET, 'allow_alternative', true) ? 1 : 0,
                    'max_batches' => (int)($_GET['max_batches'] ?? $payload['max_batches'] ?? 100),
                ];

                $result = $manufacturingService->generateApsAdvanced(
                    (string)($_GET['date_from'] ?? $payload['date_from'] ?? ''),
                    (string)($_GET['date_to'] ?? $payload['date_to'] ?? ''),
                    boolParam($payload + $_GET, 'reschedule', false),
                    (string)($_GET['reason'] ?? $payload['reason'] ?? ''),
                    $options
                );
            } else {
                $result = $manufacturingService->generateAps(
                    (string)($_GET['date_from'] ?? $payload['date_from'] ?? ''),
                    (string)($_GET['date_to'] ?? $payload['date_to'] ?? ''),
                    boolParam($payload + $_GET, 'reschedule', false),
                    (string)($_GET['reason'] ?? $payload['reason'] ?? ''),
                    ['advanced' => false]
                );
            }

            out(['ok' => true, 'result' => $result]);
            break;

        case 'aps_board':
            $result = $manufacturingService->apsBoard(
                (string)($_GET['date_from'] ?? $payload['date_from'] ?? ''),
                (string)($_GET['date_to'] ?? $payload['date_to'] ?? '')
            );
            out(['ok' => true, 'result' => $result]);
            break;

        case 'resource_utilization':
            $result = $manufacturingService->resourceUtilization(
                (string)($_GET['date_from'] ?? $payload['date_from'] ?? ''),
                (string)($_GET['date_to'] ?? $payload['date_to'] ?? '')
            );
            out(['ok' => true, 'result' => $result]);
            break;

        case 'dashboard_snapshot':
            $result = $manufacturingService->dashboardSnapshot(
                (int)($_GET['period_hours'] ?? $payload['period_hours'] ?? 24),
                (int)($_GET['util_days'] ?? $payload['util_days'] ?? 7)
            );
            out(['ok' => true, 'result' => $result]);
            break;

        case 'ingest_sensor':
            $result = $manufacturingService->ingestSensor($payload + $_GET);
            out(['ok' => true, 'result' => $result]);
            break;

        case 'save_job_sheet':
            $result = $manufacturingService->saveJobSheet($payload + $_GET);
            out(['ok' => true, 'result' => $result]);
            break;

        case 'trace_backward':
            $result = $manufacturingService->traceBackward((string)($_GET['lot_no'] ?? $payload['lot_no'] ?? ''));
            out(['ok' => true, 'result' => $result]);
            break;

        case 'trace_forward':
            $result = $manufacturingService->traceForward((string)($_GET['lot_no'] ?? $payload['lot_no'] ?? ''));
            out(['ok' => true, 'result' => $result]);
            break;

        case 'spc':
            $result = $manufacturingService->spc(
                (string)($_GET['item_code'] ?? $payload['item_code'] ?? ''),
                (string)($_GET['characteristic'] ?? $payload['characteristic'] ?? ''),
                (string)($_GET['date_from'] ?? $payload['date_from'] ?? ''),
                (string)($_GET['date_to'] ?? $payload['date_to'] ?? '')
            );
            out(['ok' => true, 'result' => $result]);
            break;

        case 'maintenance_due':
            $result = $manufacturingService->maintenanceDue((string)($_GET['as_of_date'] ?? $payload['as_of_date'] ?? ''));
            out(['ok' => true, 'result' => $result]);
            break;

        case 'maintenance_risk':
            $result = $manufacturingService->maintenanceRisk((int)($_GET['window_hours'] ?? $payload['window_hours'] ?? 72));
            out(['ok' => true, 'result' => $result]);
            break;

        case 'maintenance_generate_wo':
            $result = $manufacturingService->generateMaintenanceWorkOrders(
                (string)($_GET['as_of_date'] ?? $payload['as_of_date'] ?? ''),
                (int)($_GET['window_hours'] ?? $payload['window_hours'] ?? 72)
            );
            out(['ok' => true, 'result' => $result]);
            break;

        case 'inventory_reorder':
            $result = $manufacturingService->inventoryReorder(
                (string)($_GET['as_of_date'] ?? $payload['as_of_date'] ?? ''),
                (int)($_GET['horizon_days'] ?? $payload['horizon_days'] ?? 30)
            );
            out(['ok' => true, 'result' => $result]);
            break;

        case 'jit_plan':
            $result = $manufacturingService->jitPlan(
                (string)($_GET['as_of_date'] ?? $payload['as_of_date'] ?? ''),
                (int)($_GET['horizon_days'] ?? $payload['horizon_days'] ?? 14)
            );
            out(['ok' => true, 'result' => $result]);
            break;

        case 'create_requisitions':
            $result = $manufacturingService->createRequisitions(
                (string)($_GET['mode'] ?? $payload['mode'] ?? 'ROP'),
                (string)($_GET['as_of_date'] ?? $payload['as_of_date'] ?? ''),
                (int)($_GET['horizon_days'] ?? $payload['horizon_days'] ?? 30)
            );
            out(['ok' => true, 'result' => $result]);
            break;

        default:
            throw new RuntimeException('unknown action');
    }
} catch (Throwable $e) {
    out([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);
}