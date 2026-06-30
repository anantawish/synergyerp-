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

    switch ($action) {
        case 'gl_trial_balance':
            $result = $businessService->glTrialBalance(
                (string)($_GET['date_from'] ?? ''),
                (string)($_GET['date_to'] ?? '')
            );
            out(['ok' => true, 'result' => $result]);
            break;

        case 'gl_ledger':
            $accountCode = (string)($_GET['account_code'] ?? '');
            $result = $businessService->glLedger(
                $accountCode,
                (string)($_GET['date_from'] ?? ''),
                (string)($_GET['date_to'] ?? '')
            );
            out(['ok' => true, 'result' => $result]);
            break;

        case 'gl_tax_summary':
            $result = $businessService->glTaxSummary((string)($_GET['month'] ?? ''));
            out(['ok' => true, 'result' => $result]);
            break;

        case 'gl_balance_sheet':
            $result = $businessService->glBalanceSheet((string)($_GET['as_of_date'] ?? ''));
            out(['ok' => true, 'result' => $result]);
            break;

        case 'hr_attendance_summary':
            $result = $businessService->hrAttendanceSummary((string)($_GET['month'] ?? ''));
            out(['ok' => true, 'result' => $result]);
            break;

        case 'hr_leave_summary':
            $result = $businessService->hrLeaveSummary((string)($_GET['month'] ?? ''));
            out(['ok' => true, 'result' => $result]);
            break;

        case 'hr_payroll_summary':
            $periodIdRaw = (string)($_GET['period_id'] ?? '');
            $periodId = $periodIdRaw !== '' ? (int)$periodIdRaw : null;
            $periodCode = (string)($_GET['period_code'] ?? '');
            $result = $businessService->hrPayrollSummary($periodId, $periodCode);
            out(['ok' => true, 'result' => $result]);
            break;

        case 'hr_calculate_payroll':
            $payload = requestPayload();
            $periodIdRaw = (string)($payload['period_id'] ?? $_POST['period_id'] ?? '');
            $periodCode = (string)($payload['period_code'] ?? $_POST['period_code'] ?? '');
            $periodId = $periodIdRaw !== '' ? (int)$periodIdRaw : null;
            $runBy = (string)($authService->user()['username'] ?? 'system');
            $result = $businessService->calculatePayroll($periodId, $periodCode, $runBy);
            out(['ok' => true, 'result' => $result]);
            break;

        case 'hr_payroll_periods':
            $rows = $businessService->listPayrollPeriods();
            out(['ok' => true, 'rows' => $rows]);
            break;

        case 'compliance_info':
            out([
                'ok' => true,
                'result' => [
                    'country' => 'TH',
                    'notes' => [
                        'OT multipliers are configurable via hr_policy (defaults: workday 1.5x, holiday 2.0x, holiday OT 3.0x).',
                        'Social security employee deduction defaults to 5% with cap 750 THB per month (configurable).',
                        'Thai personal income tax withholding uses progressive annual brackets and monthly average withholding.',
                        'This module is an operational implementation baseline and must be reviewed by your accountant/HR legal advisor before production filing.',
                    ],
                ],
            ]);
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
