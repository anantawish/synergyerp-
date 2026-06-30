<?php

namespace Stock2;

use PDO;
use RuntimeException;
use Throwable;

final class ErpService
{
    private PDO $pdo;
    private TableService $tableService;
    private LegacyProcessService $legacyProcessService;
    private ManufacturingService $manufacturingService;
    private BusinessService $businessService;

    public function __construct(
        PDO $pdo,
        TableService $tableService,
        LegacyProcessService $legacyProcessService,
        ManufacturingService $manufacturingService,
        BusinessService $businessService
    ) {
        $this->pdo = $pdo;
        $this->tableService = $tableService;
        $this->legacyProcessService = $legacyProcessService;
        $this->manufacturingService = $manufacturingService;
        $this->businessService = $businessService;
    }

    /** @param array<string, mixed> $payload
      * @return array<string, mixed>
      */
    public function createProject(array $payload, string $username): array
    {
        $projectCode = trim((string)($payload['project_code'] ?? ''));
        if ($projectCode === '') {
            $projectCode = 'PRJ-' . date('YmdHis');
        }

        $productCode = trim((string)($payload['product_code'] ?? ''));
        if ($productCode === '') {
            throw new RuntimeException('product_code is required');
        }

        $planQty = (float)($payload['plan_qty'] ?? 1);
        if ($planQty <= 0) {
            $planQty = 1;
        }

        $startDate = trim((string)($payload['start_date'] ?? date('Y-m-d')));
        $dueDate = trim((string)($payload['due_date'] ?? date('Y-m-d', strtotime('+14 days'))));

        $row = [
            'project_code' => $projectCode,
            'project_name' => trim((string)($payload['project_name'] ?? ('Project ' . $projectCode))),
            'customer_name' => trim((string)($payload['customer_name'] ?? '')),
            'product_code' => $productCode,
            'product_name' => trim((string)($payload['product_name'] ?? '')),
            'plan_qty' => $planQty,
            'uom' => trim((string)($payload['uom'] ?? 'PCS')),
            'start_date' => $startDate,
            'due_date' => $dueDate,
            'status' => trim((string)($payload['status'] ?? 'PLANNED')),
            'created_by' => $username,
        ];

        $saved = $this->tableService->saveRow('erp_project', $row);
        return [
            'project_id' => (int)$saved['id'],
            'project_code' => $projectCode,
            'result' => $saved,
        ];
    }

    /** @return array<string, mixed> */
    public function runProjectFlow(int $projectId, string $username): array
    {
        if ($projectId <= 0) {
            throw new RuntimeException('project_id is required');
        }

        $project = $this->tableService->fetchRow('erp_project', (string)$projectId);
        $runNo = 'FLOW-' . date('YmdHis') . '-' . mt_rand(100, 999);

        $runSave = $this->tableService->saveRow('erp_flow_run', [
            'run_no' => $runNo,
            'project_id' => $projectId,
            'project_code' => (string)($project['project_code'] ?? ''),
            'product_code' => (string)($project['product_code'] ?? ''),
            'qty' => (float)($project['plan_qty'] ?? 1),
            'status' => 'RUNNING',
            'started_by' => $username,
            'note' => 'ERP full flow started',
        ]);
        $runId = (int)$runSave['id'];

        $seq = 10;
        $stepIds = [];

        try {
            $this->tableService->saveRow('erp_project', [
                'id' => $projectId,
                'status' => 'RUNNING',
            ]);

            $po = $this->legacyProcessService->createMock('buy_order', $username);
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'PROC_PO', 'Purchase Order', 'buy_order', (string)($po['main_id'] ?? ''), (string)($po['source_value'] ?? ''), 'DONE', 'PO created');
            $seq += 10;

            $purchase = $this->legacyProcessService->createMock('buy_credit', $username);
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'PROC_RECEIVE', 'Purchase Receive', 'buy_credit', (string)($purchase['main_id'] ?? ''), (string)($purchase['source_value'] ?? ''), 'DONE', 'Goods received');
            $seq += 10;

            $mainToProdNo = 'TR-MP-' . date('YmdHis') . '-' . mt_rand(100, 999);
            $mainToProd = $this->tableService->saveRow('erp_stock_transfer', [
                'transfer_no' => $mainToProdNo,
                'transfer_date' => date('Y-m-d'),
                'project_code' => (string)($project['project_code'] ?? ''),
                'from_warehouse' => 'MAIN_WH',
                'from_location_code' => 'L01',
                'from_shelf_code' => 'S01',
                'to_warehouse' => 'PRODUCTION_WH',
                'to_location_code' => 'L01',
                'to_shelf_code' => 'S01',
                'item_code' => (string)($project['product_code'] ?? ''),
                'item_name' => (string)($project['product_name'] ?? ''),
                'lot_no' => '',
                'qty' => (float)($project['plan_qty'] ?? 1),
                'uom' => (string)($project['uom'] ?? 'PCS'),
                'status' => 'CONFIRMED',
                'note' => 'RM transfer for production',
                'created_by' => $username,
            ]);
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'WH_MAIN_TO_PROD', 'Main WH -> Production WH', 'transfer_stock', (string)($mainToProd['id'] ?? ''), $mainToProdNo, 'DONE', 'Material issued to production warehouse');
            $seq += 10;

            $apBill = $this->legacyProcessService->createMock('creditor_billing', $username);
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'AP_BILL', 'AP Billing', 'creditor_billing', (string)($apBill['main_id'] ?? ''), (string)($apBill['source_value'] ?? ''), 'DONE', 'Creditor bill posted');
            $seq += 10;

            $apPay = $this->legacyProcessService->createMock('creditor_paid', $username);
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'AP_PAY', 'AP Payment', 'creditor_paid', (string)($apPay['main_id'] ?? ''), (string)($apPay['source_value'] ?? ''), 'DONE', 'Creditor payment done');
            $seq += 10;

            $orderNo = 'MO-' . preg_replace('/[^A-Za-z0-9]/', '', (string)$project['project_code']) . '-' . date('His');
            $prodOrder = $this->tableService->saveRow('mfg_production_order', [
                'order_no' => $orderNo,
                'item_code' => (string)$project['product_code'],
                'order_qty' => (float)($project['plan_qty'] ?? 1),
                'uom' => (string)($project['uom'] ?? 'PCS'),
                'release_date' => (string)($project['start_date'] ?? date('Y-m-d')),
                'due_date' => (string)($project['due_date'] ?? date('Y-m-d', strtotime('+7 days'))),
                'priority' => 50,
                'status' => 'RELEASED',
                'notes' => 'ERP project flow',
                'created_by' => $username,
            ]);
            $prodOrderId = (int)$prodOrder['id'];
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'MFG_ORDER', 'Production Order', 'mfg_production_order', (string)$prodOrderId, $orderNo, 'DONE', 'Production order released');
            $seq += 10;

            $aps = $this->manufacturingService->generateApsAdvanced(
                date('Y-m-d', strtotime('-1 day')),
                date('Y-m-d', strtotime('+14 days')),
                true,
                'ERP flow auto plan',
                [
                    'advanced' => true,
                    'weight_late' => 20,
                    'weight_setup' => 3,
                    'weight_load' => 1,
                    'split_lot_default' => 0,
                    'allow_alternative' => 1,
                    'max_batches' => 100,
                ]
            );
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'MFG_APS', 'APS Scheduling', 'mfg_aps_schedule', '', '', 'DONE', 'Scheduled rows: ' . (string)($aps['scheduled_rows'] ?? 0));
            $seq += 10;

            $jobSheet = $this->tableService->saveRow('mfg_digital_job_sheet', [
                'order_id' => $prodOrderId,
                'op_no' => 10,
                'instruction_text' => 'Auto flow: produce and QC',
                'checklist_json' => json_encode(['prepare', 'run', 'qc'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'result_json' => json_encode(['pass' => true, 'note' => 'auto'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'operator_code' => $username,
                'status' => 'DONE',
                'start_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                'end_at' => date('Y-m-d H:i:s'),
            ]);
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'MFG_EXEC', 'Production Execution', 'mfg_job_sheet', (string)$jobSheet['id'], '', 'DONE', 'Digital job sheet done');
            $seq += 10;

            $lotNo = 'LOT-' . preg_replace('/[^A-Za-z0-9]/', '', (string)$project['project_code']) . '-' . date('His');
            $lot = $this->tableService->saveRow('mfg_lot', [
                'lot_no' => $lotNo,
                'item_code' => (string)$project['product_code'],
                'order_id' => $prodOrderId,
                'warehouse_code' => 'FG_WH',
                'location_code' => 'L01',
                'shelf_code' => 'S01',
                'qty' => (float)($project['plan_qty'] ?? 1),
                'uom' => (string)($project['uom'] ?? 'PCS'),
                'produced_at' => date('Y-m-d H:i:s'),
                'operator_code' => $username,
                'status' => 'OPEN',
            ]);
            $this->upsertInventorySnapshot((string)$project['product_code'], (float)($project['plan_qty'] ?? 1));
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'WH_STOCK_IN', 'FG Stock In', 'mfg_lot', (string)$lot['id'], $lotNo, 'DONE', 'FG received into inventory');
            $seq += 10;

            $fgToPackNo = 'TR-FP-' . date('YmdHis') . '-' . mt_rand(100, 999);
            $fgToPack = $this->tableService->saveRow('erp_stock_transfer', [
                'transfer_no' => $fgToPackNo,
                'transfer_date' => date('Y-m-d'),
                'project_code' => (string)($project['project_code'] ?? ''),
                'from_warehouse' => 'FG_WH',
                'from_location_code' => 'L01',
                'from_shelf_code' => 'S01',
                'to_warehouse' => 'PACK_WH',
                'to_location_code' => 'L01',
                'to_shelf_code' => 'S01',
                'item_code' => (string)($project['product_code'] ?? ''),
                'item_name' => (string)($project['product_name'] ?? ''),
                'lot_no' => $lotNo,
                'qty' => (float)($project['plan_qty'] ?? 1),
                'uom' => (string)($project['uom'] ?? 'PCS'),
                'status' => 'CONFIRMED',
                'note' => 'FG transfer to packing warehouse',
                'created_by' => $username,
            ]);
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'WH_FG_TO_PACK', 'FG WH -> Packing WH', 'transfer_stock', (string)($fgToPack['id'] ?? ''), $fgToPackNo, 'DONE', 'Finished goods moved to packing warehouse');
            $seq += 10;

            $shipment = $this->legacyProcessService->createMock('client_receive', $username);
            $stepIds[] = $this->saveFlowStep(
                $runId,
                $seq,
                'WH_SHIP_OUT',
                'Shipment / Delivery',
                'client_receive',
                (string)($shipment['main_id'] ?? ''),
                (string)($shipment['source_value'] ?? ''),
                'DONE',
                'Goods delivered to customer'
            );
            $seq += 10;

            $sale = $this->legacyProcessService->createMock('sale_cash', $username);
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'SALE_INVOICE', 'Sales Invoice / POS', 'sale_cash', (string)($sale['main_id'] ?? ''), (string)($sale['source_value'] ?? ''), 'DONE', 'Cash sale completed');
            $seq += 10;

            $amount = round(max(1, (float)($project['plan_qty'] ?? 1)) * 100, 2);
            $journalNo = 'JVF-' . date('YmdHis') . '-' . mt_rand(10, 99);
            $journal = $this->tableService->saveRow('gl_journal', [
                'journal_no' => $journalNo,
                'journal_date' => date('Y-m-d'),
                'description' => 'ERP flow auto posting ' . $runNo,
                'source_module' => 'ERP_FLOW',
                'source_ref' => $runNo,
                'created_by' => $username,
                'status' => 'POSTED',
            ]);
            $journalId = (int)$journal['id'];
            $this->tableService->saveRow('gl_journal_line', [
                'journal_id' => $journalId,
                'line_no' => 1,
                'account_code' => '1000',
                'description' => 'Cash from sale',
                'debit' => $amount,
                'credit' => 0,
            ]);
            $this->tableService->saveRow('gl_journal_line', [
                'journal_id' => $journalId,
                'line_no' => 2,
                'account_code' => '4000',
                'description' => 'Sales revenue',
                'debit' => 0,
                'credit' => $amount,
            ]);
            $stepIds[] = $this->saveFlowStep($runId, $seq, 'GL_POST', 'GL Posting', 'gl_journal', (string)$journalId, $journalNo, 'DONE', 'Revenue posting completed');
            $seq += 10;

            $balanceSheet = $this->businessService->glBalanceSheet(date('Y-m-d'));
            $stepIds[] = $this->saveFlowStep(
                $runId,
                $seq,
                'GL_BALANCE_SHEET',
                'Balance Sheet',
                'gl_balance_sheet_report',
                '',
                '',
                'DONE',
                'Assets ' . (string)($balanceSheet['total_assets'] ?? 0)
                . ' | L+E ' . (string)($balanceSheet['total_liabilities_equity'] ?? 0)
            );

            $this->tableService->saveRow('erp_flow_run', [
                'id' => $runId,
                'status' => 'DONE',
                'completed_at' => date('Y-m-d H:i:s'),
                'note' => 'ERP full flow completed',
            ]);
            $this->tableService->saveRow('erp_project', [
                'id' => $projectId,
                'status' => 'COMPLETED',
            ]);
        } catch (Throwable $e) {
            $this->saveFlowStep($runId, $seq, 'FLOW_ERROR', 'Flow Error', 'erp_flow', '', '', 'FAILED', $e->getMessage());
            $this->tableService->saveRow('erp_flow_run', [
                'id' => $runId,
                'status' => 'FAILED',
                'completed_at' => date('Y-m-d H:i:s'),
                'note' => $e->getMessage(),
            ]);
            $this->tableService->saveRow('erp_project', [
                'id' => $projectId,
                'status' => 'HOLD',
            ]);
            throw $e;
        }

        return $this->flowTimeline($runId);
    }

    /** @return array<string, mixed> */
    public function flowTimeline(int $runId): array
    {
        if ($runId <= 0) {
            throw new RuntimeException('run_id is required');
        }

        $run = $this->tableService->fetchRow('erp_flow_run', (string)$runId);
        $sql = 'SELECT * FROM erp_flow_step WHERE run_id = :run_id ORDER BY seq_no ASC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['run_id' => $runId]);
        $steps = $stmt->fetchAll();

        return [
            'run' => $run,
            'steps' => is_array($steps) ? $steps : [],
        ];
    }

    /** @return array<string, mixed> */
    public function dashboard(int $projectLimit = 20, int $runLimit = 20): array
    {
        $projectLimit = max(1, min(500, $projectLimit));
        $runLimit = max(1, min(500, $runLimit));

        $sqlProject = 'SELECT * FROM erp_project ORDER BY id DESC LIMIT ' . $projectLimit;
        $projects = $this->pdo->query($sqlProject)->fetchAll();

        $sqlRun = 'SELECT * FROM erp_flow_run ORDER BY id DESC LIMIT ' . $runLimit;
        $runs = $this->pdo->query($sqlRun)->fetchAll();

        $statusRows = $this->pdo->query('SELECT status, COUNT(*) AS cnt FROM erp_flow_run GROUP BY status')->fetchAll();
        $statusMap = [];
        foreach ($statusRows as $row) {
            $statusMap[(string)$row['status']] = (int)$row['cnt'];
        }

        return [
            'projects' => is_array($projects) ? $projects : [],
            'runs' => is_array($runs) ? $runs : [],
            'summary' => [
                'projects_total' => count(is_array($projects) ? $projects : []),
                'runs_total' => count(is_array($runs) ? $runs : []),
                'runs_done' => (int)($statusMap['DONE'] ?? 0),
                'runs_failed' => (int)($statusMap['FAILED'] ?? 0),
                'runs_running' => (int)($statusMap['RUNNING'] ?? 0),
            ],
        ];
    }

    private function saveFlowStep(
        int $runId,
        int $seqNo,
        string $stageCode,
        string $stageName,
        string $moduleKey,
        string $refId,
        string $refNo,
        string $status,
        string $note
    ): int {
        $save = $this->tableService->saveRow('erp_flow_step', [
            'run_id' => $runId,
            'seq_no' => $seqNo,
            'stage_code' => $stageCode,
            'stage_name' => $stageName,
            'module_key' => $moduleKey,
            'ref_table' => '',
            'ref_id' => $refId,
            'ref_no' => $refNo,
            'status' => $status,
            'note' => $note,
            'event_time' => date('Y-m-d H:i:s'),
        ]);

        return (int)$save['id'];
    }

    private function upsertInventorySnapshot(string $itemCode, float $qty): void
    {
        $qty = max(0.0, $qty);
        $sql = <<<'SQL'
INSERT INTO mfg_inventory_snapshot (item_code, on_hand_qty, reserved_qty, updated_at, source)
VALUES (:item_code, :on_hand_qty, 0, :updated_at, 'ERP_FLOW')
ON DUPLICATE KEY UPDATE
    on_hand_qty = on_hand_qty + VALUES(on_hand_qty),
    updated_at = VALUES(updated_at),
    source = 'ERP_FLOW'
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'item_code' => $itemCode,
            'on_hand_qty' => $qty,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
