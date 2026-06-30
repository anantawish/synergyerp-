<?php

namespace Stock2;

use DateInterval;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class ManufacturingService
{
    private PDO $pdo;
    private SchemaService $schema;

    /** @var array<string, string>|null */
    private ?array $productCols = null;

    public function __construct(PDO $pdo, SchemaService $schema)
    {
        $this->pdo = $pdo;
        $this->schema = $schema;
    }

    /** @return array<string, mixed> */
    public function bomExplosion(string $itemCode, float $orderQty = 1.0, string $asOfDate = ''): array
    {
        $itemCode = trim($itemCode);
        if ($itemCode === '') {
            throw new RuntimeException('item_code is required');
        }

        if ($orderQty <= 0) {
            $orderQty = 1.0;
        }

        $asOf = $this->normalizeDate($asOfDate);
        $bomHeader = $this->activeBomHeader($itemCode, $asOf);
        if (!$bomHeader) {
            throw new RuntimeException('active BOM not found');
        }

        $sql = <<<'SQL'
WITH RECURSIVE bt AS (
  SELECT
    l.parent_item_code,
    l.component_item_code,
    l.qty_per,
    l.scrap_pct,
    1 AS depth,
    CAST(l.qty_per * (1 + (l.scrap_pct / 100)) AS DECIMAL(22,6)) AS factor,
    CONCAT(l.parent_item_code, '>', l.component_item_code) AS path
  FROM mfg_bom_line l
  WHERE l.bom_id = :bom_id

  UNION ALL

  SELECT
    l2.parent_item_code,
    l2.component_item_code,
    l2.qty_per,
    l2.scrap_pct,
    bt.depth + 1 AS depth,
    CAST(bt.factor * l2.qty_per * (1 + (l2.scrap_pct / 100)) AS DECIMAL(22,6)) AS factor,
    CONCAT(bt.path, '>', l2.component_item_code)
  FROM bt
  INNER JOIN mfg_bom_header h2
    ON h2.item_code = bt.component_item_code
   AND h2.status = 'APPROVED'
   AND h2.effective_from <= :as_of
   AND (h2.effective_to IS NULL OR h2.effective_to >= :as_of)
  INNER JOIN mfg_bom_line l2 ON l2.bom_id = h2.id
  WHERE bt.depth < 10
    AND LOCATE(CONCAT('>', l2.component_item_code, '>'), CONCAT('>', bt.path, '>')) = 0
)
SELECT
  component_item_code,
  MIN(depth) AS depth,
  SUM(factor) * :order_qty AS required_qty
FROM bt
GROUP BY component_item_code
ORDER BY depth, component_item_code
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'bom_id' => (int)$bomHeader['id'],
            'as_of' => $asOf,
            'order_qty' => $orderQty,
        ]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = [
                'component_item_code' => (string)$row['component_item_code'],
                'depth' => (int)$row['depth'],
                'required_qty' => round((float)$row['required_qty'], 4),
            ];
        }

        return [
            'item_code' => $itemCode,
            'order_qty' => round($orderQty, 4),
            'as_of' => $asOf,
            'bom_header' => $bomHeader,
            'components' => $rows,
            'count' => count($rows),
        ];
    }

    /** @return array<string, mixed> */
    public function routingPlan(string $itemCode, float $qty = 1.0, string $asOfDate = ''): array
    {
        $itemCode = trim($itemCode);
        if ($itemCode === '') {
            throw new RuntimeException('item_code is required');
        }

        if ($qty <= 0) {
            $qty = 1.0;
        }

        $asOf = $this->normalizeDate($asOfDate);
        $header = $this->activeRoutingHeader($itemCode, $asOf);
        if (!$header) {
            throw new RuntimeException('active routing not found');
        }

        $stmt = $this->pdo->prepare('SELECT * FROM mfg_routing_step WHERE routing_id = :id ORDER BY op_no ASC, id ASC');
        $stmt->execute(['id' => (int)$header['id']]);

        $steps = [];
        $total = 0.0;

        foreach ($stmt->fetchAll() as $row) {
            $chosen = $this->selectCenter((string)$row['primary_center_code'], (string)($row['alt_group'] ?? ''));
            $hours = (float)$row['setup_hours']
                + ((float)$row['run_hours_per_unit'] * $qty)
                + (float)$row['queue_hours']
                + (float)$row['move_hours'];

            $steps[] = [
                'op_no' => (int)$row['op_no'],
                'operation_name' => (string)$row['operation_name'],
                'primary_center_code' => (string)$row['primary_center_code'],
                'selected_center_code' => $chosen['center_code'],
                'selected_center_status' => $chosen['status'],
                'is_alternative' => $chosen['is_alternative'],
                'planned_hours' => round($hours, 4),
            ];
            $total += $hours;
        }

        return [
            'item_code' => $itemCode,
            'qty' => round($qty, 4),
            'as_of' => $asOf,
            'routing_header' => $header,
            'steps' => $steps,
            'total_hours' => round($total, 4),
        ];
    }

    /** @return array<string, mixed> */
    public function apsBoard(string $dateFrom = '', string $dateTo = ''): array
    {
        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo, 30);

        $stmt = $this->pdo->prepare(
            "SELECT
               s.id,
               s.order_id,
               o.order_no,
               o.item_code,
               o.order_qty,
               o.due_date,
               o.priority,
               s.op_no,
               s.operation_name,
               s.work_center_code,
               s.planned_start,
               s.planned_end,
               s.planned_hours,
               s.setup_hours_applied,
               s.batch_no,
               s.batch_qty,
               s.setup_from_item_code,
               s.setup_to_item_code,
               s.sequence_no,
               s.status,
               s.reschedule_reason,
               s.tardiness_hours,
               s.penalty_cost,
               s.heuristic_tag
             FROM mfg_aps_schedule s
             INNER JOIN mfg_production_order o ON o.id = s.order_id
             WHERE DATE(s.planned_start) BETWEEN :from_date AND :to_date
             ORDER BY s.planned_start ASC, s.work_center_code ASC, s.batch_no ASC, s.sequence_no ASC"
        );
        $stmt->execute([
            'from_date' => $from,
            'to_date' => $to,
        ]);

        $rows = $stmt->fetchAll();

        return [
            'from_date' => $from,
            'to_date' => $to,
            'rows' => $rows,
            'count' => count($rows),
        ];
    }

    /** @return array<string, mixed> */
    public function generateAps(string $dateFrom = '', string $dateTo = '', bool $reschedule = false, string $reason = '', array $options = []): array
    {
        $useAdvanced = (int)($options['advanced'] ?? 0) === 1
            || (string)($options['advanced'] ?? '') === '1'
            || (string)($options['advanced'] ?? '') === 'true';

        if ($useAdvanced) {
            return $this->generateApsAdvanced($dateFrom, $dateTo, $reschedule, $reason, $options);
        }

        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo, 30);

        $orderStmt = $this->pdo->prepare(
            "SELECT *
             FROM mfg_production_order
             WHERE status IN ('PLANNED', 'RELEASED', 'IN_PROGRESS')
               AND due_date BETWEEN :from_date AND :to_date
             ORDER BY priority ASC, due_date ASC, id ASC"
        );
        $orderStmt->execute([
            'from_date' => $from,
            'to_date' => $to,
        ]);
        $orders = $orderStmt->fetchAll();

        if (empty($orders)) {
            return [
                'from_date' => $from,
                'to_date' => $to,
                'orders' => 0,
                'scheduled_rows' => 0,
                'unscheduled' => [],
                'algorithm' => 'classic',
            ];
        }

        $orderIds = array_map(static fn(array $r): int => (int)$r['id'], $orders);
        if ($reschedule) {
            $in = implode(',', array_map('intval', $orderIds));
            $this->pdo->exec('DELETE FROM mfg_aps_schedule WHERE order_id IN (' . $in . ')');
        }

        $used = $this->loadUsedHours($from, $to);

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO mfg_aps_schedule (
               order_id,
               op_no,
               batch_no,
               batch_qty,
               operation_name,
               work_center_code,
               planned_start,
               planned_end,
               planned_hours,
               setup_hours_applied,
               setup_from_item_code,
               setup_to_item_code,
               sequence_no,
               status,
               reschedule_reason,
               tardiness_hours,
               penalty_cost,
               heuristic_tag
             ) VALUES (
               :order_id,
               :op_no,
               :batch_no,
               :batch_qty,
               :operation_name,
               :work_center_code,
               :planned_start,
               :planned_end,
               :planned_hours,
               :setup_hours_applied,
               :setup_from_item_code,
               :setup_to_item_code,
               :sequence_no,
               :status,
               :reschedule_reason,
               :tardiness_hours,
               :penalty_cost,
               :heuristic_tag
             )'
        );

        $scheduledRows = 0;
        $unscheduled = [];
        $penaltyTotal = 0.0;

        foreach ($orders as $order) {
            $orderId = (int)$order['id'];
            $itemCode = (string)$order['item_code'];
            $qty = (float)$order['order_qty'];

            try {
                $routing = $this->routingPlan($itemCode, $qty, (string)$order['release_date']);
            } catch (RuntimeException $e) {
                $unscheduled[] = ['order_no' => (string)$order['order_no'], 'reason' => $e->getMessage()];
                continue;
            }

            $cursor = new DateTimeImmutable((string)$order['release_date'] . ' 08:00:00');
            $sequence = 1;
            $failed = false;
            $dueAt = new DateTimeImmutable((string)$order['due_date'] . ' 23:59:59');

            foreach ($routing['steps'] as $step) {
                $center = (string)$step['selected_center_code'];
                $hours = (float)$step['planned_hours'];
                if ($hours <= 0) {
                    continue;
                }

                $slot = $this->findNextSlot($center, $cursor, $hours, $used, $to);
                if (!$slot) {
                    $unscheduled[] = ['order_no' => (string)$order['order_no'], 'reason' => 'capacity full for ' . $center];
                    $failed = true;
                    break;
                }

                $slotEnd = new DateTimeImmutable($slot['end']);
                $tardiness = max(($slotEnd->getTimestamp() - $dueAt->getTimestamp()) / 3600, 0);
                $penalty = $tardiness * 10;

                $insertStmt->execute([
                    'order_id' => $orderId,
                    'op_no' => (int)$step['op_no'],
                    'batch_no' => 1,
                    'batch_qty' => round($qty, 4),
                    'operation_name' => (string)$step['operation_name'],
                    'work_center_code' => $center,
                    'planned_start' => $slot['start'],
                    'planned_end' => $slot['end'],
                    'planned_hours' => round($hours, 4),
                    'setup_hours_applied' => 0,
                    'setup_from_item_code' => null,
                    'setup_to_item_code' => $itemCode,
                    'sequence_no' => $sequence,
                    'status' => 'PLANNED',
                    'reschedule_reason' => $reschedule ? $reason : null,
                    'tardiness_hours' => round($tardiness, 4),
                    'penalty_cost' => round($penalty, 4),
                    'heuristic_tag' => 'classic',
                ]);

                $used[$this->centerDayKey($center, $slot['work_date'])] = ($used[$this->centerDayKey($center, $slot['work_date'])] ?? 0) + $hours;
                $cursor = (new DateTimeImmutable($slot['end']))->add(new DateInterval('PT5M'));
                $sequence++;
                $scheduledRows++;
                $penaltyTotal += $penalty;
            }

            if (!$failed) {
                $up = $this->pdo->prepare('UPDATE mfg_production_order SET status = :status WHERE id = :id');
                $up->execute(['status' => 'RELEASED', 'id' => $orderId]);
            }
        }

        return [
            'from_date' => $from,
            'to_date' => $to,
            'orders' => count($orders),
            'scheduled_rows' => $scheduledRows,
            'unscheduled' => $unscheduled,
            'reschedule' => $reschedule,
            'penalty_total' => round($penaltyTotal, 4),
            'algorithm' => 'classic',
        ];
    }

    /** @return array<string, mixed> */
    public function resourceUtilization(string $dateFrom = '', string $dateTo = ''): array
    {
        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo, 30);

        $stmt = $this->pdo->prepare(
            "SELECT work_center_code, DATE(planned_start) AS work_date, SUM(planned_hours) AS planned_hours
             FROM mfg_aps_schedule
             WHERE DATE(planned_start) BETWEEN :from_date AND :to_date
             GROUP BY work_center_code, DATE(planned_start)
             ORDER BY work_center_code, work_date"
        );
        $stmt->execute(['from_date' => $from, 'to_date' => $to]);

        $rows = [];
        $plannedTotal = 0.0;
        $capacityTotal = 0.0;

        foreach ($stmt->fetchAll() as $row) {
            $center = (string)$row['work_center_code'];
            $workDate = (string)$row['work_date'];
            $planned = (float)$row['planned_hours'];
            $capacity = $this->capacityHours($center, $workDate);
            $idle = max($capacity - $planned, 0);

            $rows[] = [
                'work_center_code' => $center,
                'work_date' => $workDate,
                'planned_hours' => round($planned, 4),
                'capacity_hours' => round($capacity, 4),
                'idle_hours' => round($idle, 4),
                'utilization_pct' => $capacity > 0 ? round(($planned / $capacity) * 100, 2) : 0,
            ];

            $plannedTotal += $planned;
            $capacityTotal += $capacity;
        }

        return [
            'from_date' => $from,
            'to_date' => $to,
            'rows' => $rows,
            'overall_utilization_pct' => $capacityTotal > 0 ? round(($plannedTotal / $capacityTotal) * 100, 2) : 0,
        ];
    }

    /** @param array<string, mixed> $payload
      * @return array<string, mixed>
      */
    public function ingestSensor(array $payload): array
    {
        $device = trim((string)($payload['device_code'] ?? ''));
        $metric = trim((string)($payload['metric_name'] ?? ''));
        if ($device === '' || $metric === '') {
            throw new RuntimeException('device_code and metric_name are required');
        }

        $metricValue = (float)($payload['metric_value'] ?? 0);
        $center = trim((string)($payload['center_code'] ?? ''));
        $time = trim((string)($payload['log_time'] ?? ''));
        if ($time === '') {
            $time = date('Y-m-d H:i:s');
        }

        $this->pdo->beginTransaction();
        try {
            $devStmt = $this->pdo->prepare(
                "INSERT INTO iot_device (device_code, center_code, protocol, status, last_seen_at)
                 VALUES (:device_code, :center_code, :protocol, :status, :last_seen_at)
                 ON DUPLICATE KEY UPDATE
                    center_code = VALUES(center_code),
                    last_seen_at = VALUES(last_seen_at),
                    status = 'ACTIVE'"
            );
            $devStmt->execute([
                'device_code' => $device,
                'center_code' => $center !== '' ? $center : null,
                'protocol' => (string)($payload['protocol'] ?? 'MQTT'),
                'status' => 'ACTIVE',
                'last_seen_at' => $time,
            ]);

            $logStmt = $this->pdo->prepare(
                'INSERT INTO iot_sensor_log (
                  device_code,
                  center_code,
                  log_time,
                  metric_name,
                  metric_value,
                  metric_unit,
                  quality_flag,
                  payload_json
                ) VALUES (
                  :device_code,
                  :center_code,
                  :log_time,
                  :metric_name,
                  :metric_value,
                  :metric_unit,
                  :quality_flag,
                  :payload_json
                )'
            );
            $logStmt->execute([
                'device_code' => $device,
                'center_code' => $center !== '' ? $center : null,
                'log_time' => $time,
                'metric_name' => $metric,
                'metric_value' => $metricValue,
                'metric_unit' => (string)($payload['metric_unit'] ?? ''),
                'quality_flag' => strtoupper((string)($payload['quality_flag'] ?? 'GOOD')),
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $machineCode = trim((string)($payload['machine_code'] ?? ''));
            $runtimeInc = (float)($payload['runtime_hours_increment'] ?? 0);
            $vibration = isset($payload['vibration']) ? (float)$payload['vibration'] : null;
            $temp = isset($payload['temp_c']) ? (float)$payload['temp_c'] : null;
            $amp = isset($payload['current_amp']) ? (float)$payload['current_amp'] : null;

            if ($metric === 'runtime_hours') {
                $runtimeInc = $metricValue;
            }
            if ($metric === 'vibration' && $vibration === null) {
                $vibration = $metricValue;
            }
            if (($metric === 'temp' || $metric === 'temp_c') && $temp === null) {
                $temp = $metricValue;
            }

            if ($machineCode !== '' && ($runtimeInc > 0 || $vibration !== null || $temp !== null || $amp !== null)) {
                $rtStmt = $this->pdo->prepare(
                    'INSERT INTO machine_runtime_log (
                      machine_code,
                      log_time,
                      runtime_hours_increment,
                      vibration,
                      temp_c,
                      current_amp,
                      source
                    ) VALUES (
                      :machine_code,
                      :log_time,
                      :runtime_hours_increment,
                      :vibration,
                      :temp_c,
                      :current_amp,
                      :source
                    )'
                );
                $rtStmt->execute([
                    'machine_code' => $machineCode,
                    'log_time' => $time,
                    'runtime_hours_increment' => round($runtimeInc, 4),
                    'vibration' => $vibration,
                    'temp_c' => $temp,
                    'current_amp' => $amp,
                    'source' => 'IOT',
                ]);

                if ($runtimeInc > 0) {
                    $upStmt = $this->pdo->prepare(
                        'UPDATE asset_machine
                         SET runtime_hours_total = runtime_hours_total + :inc
                         WHERE machine_code = :machine_code'
                    );
                    $upStmt->execute([
                        'inc' => round($runtimeInc, 4),
                        'machine_code' => $machineCode,
                    ]);
                }
            }

            $this->pdo->commit();
            return [
                'device_code' => $device,
                'metric_name' => $metric,
                'metric_value' => $metricValue,
                'log_time' => $time,
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $payload
      * @return array<string, mixed>
      */
    public function saveJobSheet(array $payload): array
    {
        $orderId = (int)($payload['order_id'] ?? 0);
        $opNo = (int)($payload['op_no'] ?? 0);
        if ($orderId <= 0 || $opNo <= 0) {
            throw new RuntimeException('order_id and op_no are required');
        }

        $status = strtoupper((string)($payload['status'] ?? 'OPEN'));
        if (!in_array($status, ['OPEN', 'IN_PROGRESS', 'DONE', 'HOLD'], true)) {
            $status = 'OPEN';
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO mfg_digital_job_sheet (
                order_id,
                op_no,
                instruction_text,
                checklist_json,
                result_json,
                operator_code,
                status,
                start_at,
                end_at
            ) VALUES (
                :order_id,
                :op_no,
                :instruction_text,
                :checklist_json,
                :result_json,
                :operator_code,
                :status,
                :start_at,
                :end_at
            )
            ON DUPLICATE KEY UPDATE
                instruction_text = VALUES(instruction_text),
                checklist_json = VALUES(checklist_json),
                result_json = VALUES(result_json),
                operator_code = VALUES(operator_code),
                status = VALUES(status),
                start_at = VALUES(start_at),
                end_at = VALUES(end_at),
                updated_at = NOW()"
        );

        $stmt->execute([
            'order_id' => $orderId,
            'op_no' => $opNo,
            'instruction_text' => (string)($payload['instruction_text'] ?? ''),
            'checklist_json' => $this->jsonString($payload['checklist'] ?? $payload['checklist_json'] ?? null),
            'result_json' => $this->jsonString($payload['result'] ?? $payload['result_json'] ?? null),
            'operator_code' => (string)($payload['operator_code'] ?? ''),
            'status' => $status,
            'start_at' => (string)($payload['start_at'] ?? '') !== '' ? (string)$payload['start_at'] : null,
            'end_at' => (string)($payload['end_at'] ?? '') !== '' ? (string)$payload['end_at'] : null,
        ]);

        return [
            'order_id' => $orderId,
            'op_no' => $opNo,
            'status' => $status,
            'saved' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function traceBackward(string $lotNo): array
    {
        $lotNo = trim($lotNo);
        if ($lotNo === '') {
            throw new RuntimeException('lot_no is required');
        }

        $root = $this->lotRow($lotNo);
        if (!$root) {
            throw new RuntimeException('lot not found');
        }

        $sql = <<<'SQL'
WITH RECURSIVE t AS (
    SELECT 1 AS depth, produced_lot_no, component_lot_no, component_item_code, qty, uom
    FROM mfg_lot_consumption
    WHERE produced_lot_no = :lot_no
    UNION ALL
    SELECT t.depth + 1, c.produced_lot_no, c.component_lot_no, c.component_item_code, c.qty, c.uom
    FROM t
    JOIN mfg_lot_consumption c ON c.produced_lot_no = t.component_lot_no
    WHERE t.depth < 8
)
SELECT *
FROM t
ORDER BY depth, produced_lot_no, component_lot_no
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['lot_no' => $lotNo]);
        $rows = $stmt->fetchAll();

        return [
            'mode' => 'backward',
            'root_lot' => $root,
            'links' => $rows,
            'count' => count($rows),
        ];
    }

    /** @return array<string, mixed> */
    public function traceForward(string $lotNo): array
    {
        $lotNo = trim($lotNo);
        if ($lotNo === '') {
            throw new RuntimeException('lot_no is required');
        }

        $root = $this->lotRow($lotNo);
        if (!$root) {
            throw new RuntimeException('lot not found');
        }

        $sql = <<<'SQL'
WITH RECURSIVE t AS (
    SELECT 1 AS depth, component_lot_no, produced_lot_no, component_item_code, qty, uom
    FROM mfg_lot_consumption
    WHERE component_lot_no = :lot_no
    UNION ALL
    SELECT t.depth + 1, c.component_lot_no, c.produced_lot_no, c.component_item_code, c.qty, c.uom
    FROM t
    JOIN mfg_lot_consumption c ON c.component_lot_no = t.produced_lot_no
    WHERE t.depth < 8
)
SELECT *
FROM t
ORDER BY depth, component_lot_no, produced_lot_no
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['lot_no' => $lotNo]);
        $rows = $stmt->fetchAll();

        return [
            'mode' => 'forward',
            'root_lot' => $root,
            'links' => $rows,
            'count' => count($rows),
        ];
    }

    /** @return array<string, mixed> */
    public function spc(string $itemCode = '', string $characteristic = '', string $dateFrom = '', string $dateTo = ''): array
    {
        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo, 30);

        $itemCode = trim($itemCode);
        if ($itemCode === '') {
            $itemCode = (string)($this->pdo->query('SELECT item_code FROM qms_inspection_result ORDER BY inspected_at DESC LIMIT 1')->fetchColumn() ?? '');
        }
        if ($itemCode === '') {
            return ['from_date' => $from, 'to_date' => $to, 'stats' => ['sample_size' => 0], 'points' => []];
        }

        $characteristic = trim($characteristic);
        if ($characteristic === '') {
            $q = $this->pdo->prepare('SELECT characteristic FROM qms_inspection_result WHERE item_code = :item_code ORDER BY inspected_at DESC LIMIT 1');
            $q->execute(['item_code' => $itemCode]);
            $characteristic = (string)($q->fetchColumn() ?? '');
        }
        if ($characteristic === '') {
            throw new RuntimeException('characteristic not found');
        }

        $st = $this->pdo->prepare(
            'SELECT COUNT(*) AS sample_size, AVG(measured_value) AS mean_value, STDDEV_POP(measured_value) AS sigma, MIN(measured_value) AS min_value, MAX(measured_value) AS max_value
             FROM qms_inspection_result
             WHERE item_code = :item_code
               AND characteristic = :characteristic
               AND DATE(inspected_at) BETWEEN :from_date AND :to_date'
        );
        $st->execute(['item_code' => $itemCode, 'characteristic' => $characteristic, 'from_date' => $from, 'to_date' => $to]);
        $stats = $st->fetch();

        if (!is_array($stats) || (int)$stats['sample_size'] === 0) {
            return ['item_code' => $itemCode, 'characteristic' => $characteristic, 'from_date' => $from, 'to_date' => $to, 'stats' => ['sample_size' => 0], 'points' => []];
        }

        $mean = (float)$stats['mean_value'];
        $sigma = (float)$stats['sigma'];
        $ucl = $mean + (3 * $sigma);
        $lcl = $mean - (3 * $sigma);

        $ps = $this->pdo->prepare(
            'SELECT lot_no, measured_value, decision, inspected_at
             FROM qms_inspection_result
             WHERE item_code = :item_code
               AND characteristic = :characteristic
               AND DATE(inspected_at) BETWEEN :from_date AND :to_date
             ORDER BY inspected_at ASC
             LIMIT 500'
        );
        $ps->execute(['item_code' => $itemCode, 'characteristic' => $characteristic, 'from_date' => $from, 'to_date' => $to]);
        $points = $ps->fetchAll();

        $violations = 0;
        foreach ($points as &$p) {
            $v = (float)$p['measured_value'];
            $out = ($sigma > 0) && ($v > $ucl || $v < $lcl);
            if ($out) {
                $violations++;
            }
            $p['out_of_control'] = $out;
        }
        unset($p);

        return [
            'item_code' => $itemCode,
            'characteristic' => $characteristic,
            'from_date' => $from,
            'to_date' => $to,
            'stats' => [
                'sample_size' => (int)$stats['sample_size'],
                'mean' => round($mean, 6),
                'sigma' => round($sigma, 6),
                'ucl' => round($ucl, 6),
                'lcl' => round($lcl, 6),
                'min' => round((float)$stats['min_value'], 6),
                'max' => round((float)$stats['max_value'], 6),
                'out_of_control_points' => $violations,
            ],
            'points' => $points,
        ];
    }

    /** @return array<string, mixed> */
    public function maintenanceDue(string $asOfDate = ''): array
    {
        $asOf = $this->normalizeDateTime($asOfDate);

        $stmt = $this->pdo->query(
            "SELECT p.*, a.machine_name, a.status AS machine_status, a.runtime_hours_total
             FROM maintenance_plan p
             LEFT JOIN asset_machine a ON a.machine_code = p.machine_code
             WHERE p.active = 1
             ORDER BY p.machine_code, p.id"
        );

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $runtimeNow = (float)($row['runtime_hours_total'] ?? 0);
            $runtimeLast = (float)($row['last_maintenance_runtime'] ?? 0);
            $runtimeSince = max($runtimeNow - $runtimeLast, 0);

            $dueByHours = ((float)$row['interval_hours'] > 0) && ($runtimeSince >= (float)$row['interval_hours']);
            $dueByDate = ((string)($row['next_due_at'] ?? '') !== '') && ((string)$row['next_due_at'] <= $asOf);
            if (!$dueByDate && (int)$row['interval_days'] > 0 && (string)($row['last_maintenance_at'] ?? '') !== '') {
                $next = (new DateTimeImmutable((string)$row['last_maintenance_at']))->add(new DateInterval('P' . (int)$row['interval_days'] . 'D'))->format('Y-m-d H:i:s');
                $dueByDate = $next <= $asOf;
            }

            if (!($dueByHours || $dueByDate)) {
                continue;
            }

            $rows[] = [
                'plan_id' => (int)$row['id'],
                'machine_code' => (string)$row['machine_code'],
                'machine_name' => (string)($row['machine_name'] ?? ''),
                'machine_status' => (string)($row['machine_status'] ?? ''),
                'plan_type' => (string)$row['plan_type'],
                'runtime_since_maintenance' => round($runtimeSince, 3),
                'interval_hours' => round((float)$row['interval_hours'], 3),
                'interval_days' => (int)$row['interval_days'],
                'next_due_at' => (string)($row['next_due_at'] ?? ''),
                'due_reason' => ($dueByHours ? 'runtime_due' : '') . ($dueByDate ? ',calendar_due' : ''),
            ];
        }

        return ['as_of' => $asOf, 'rows' => $rows, 'count' => count($rows)];
    }

    /** @return array<string, mixed> */
    public function maintenanceRisk(int $windowHours = 72): array
    {
        if ($windowHours <= 0) {
            $windowHours = 72;
        }

        $from = (new DateTimeImmutable('now'))->sub(new DateInterval('PT' . $windowHours . 'H'))->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            "SELECT
                p.id AS plan_id,
                p.machine_code,
                p.threshold_vibration,
                p.threshold_temp,
                a.machine_name,
                a.status AS machine_status,
                COALESCE(AVG(r.vibration), 0) AS avg_vibration,
                COALESCE(AVG(r.temp_c), 0) AS avg_temp,
                COUNT(r.id) AS sample_count
             FROM maintenance_plan p
             LEFT JOIN asset_machine a ON a.machine_code = p.machine_code
             LEFT JOIN machine_runtime_log r ON r.machine_code = p.machine_code AND r.log_time >= :from_time
             WHERE p.active = 1
             GROUP BY p.id, p.machine_code, p.threshold_vibration, p.threshold_temp, a.machine_name, a.status
             ORDER BY p.machine_code"
        );
        $stmt->execute(['from_time' => $from]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $thV = (float)($row['threshold_vibration'] ?? 0);
            $thT = (float)($row['threshold_temp'] ?? 0);
            $avgV = (float)$row['avg_vibration'];
            $avgT = (float)$row['avg_temp'];
            $scoreV = $thV > 0 ? ($avgV / $thV) : 0;
            $scoreT = $thT > 0 ? ($avgT / $thT) : 0;
            $score = max($scoreV, $scoreT);

            $level = 'LOW';
            if ($score >= 1.2) {
                $level = 'CRITICAL';
            } elseif ($score >= 1.0) {
                $level = 'HIGH';
            } elseif ($score >= 0.8) {
                $level = 'MEDIUM';
            }

            $rows[] = [
                'plan_id' => (int)$row['plan_id'],
                'machine_code' => (string)$row['machine_code'],
                'machine_name' => (string)($row['machine_name'] ?? ''),
                'machine_status' => (string)($row['machine_status'] ?? ''),
                'sample_count' => (int)$row['sample_count'],
                'avg_vibration' => round($avgV, 4),
                'avg_temp' => round($avgT, 4),
                'threshold_vibration' => round($thV, 4),
                'threshold_temp' => round($thT, 4),
                'risk_score' => round($score, 4),
                'risk_level' => $level,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => $b['risk_score'] <=> $a['risk_score']);

        return [
            'window_hours' => $windowHours,
            'from_time' => $from,
            'rows' => $rows,
            'high_risk_count' => count(array_filter($rows, static fn(array $r): bool => in_array($r['risk_level'], ['HIGH', 'CRITICAL'], true))),
        ];
    }

    /** @return array<string, mixed> */
    public function inventoryReorder(string $asOfDate = '', int $horizonDays = 30): array
    {
        if ($horizonDays <= 0) {
            $horizonDays = 30;
        }

        $asOf = $this->normalizeDate($asOfDate);
        $end = (new DateTimeImmutable($asOf))->add(new DateInterval('P' . $horizonDays . 'D'))->format('Y-m-d');

        $policies = $this->pdo->query('SELECT * FROM supply_item_policy ORDER BY item_code')->fetchAll();
        $rows = [];

        foreach ($policies as $p) {
            $item = (string)$p['item_code'];
            $lead = max((int)$p['lead_time_days'], 0);
            $safety = (float)$p['safety_stock'];
            $demand = $this->forecastDemand($item, $asOf, $end);
            $avgDaily = $horizonDays > 0 ? ($demand / $horizonDays) : 0;
            $onHand = $this->onHand($item);
            $reserved = $this->reserved($item);
            $projected = $onHand - $reserved - $demand;
            $rop = $safety + ($avgDaily * $lead);

            $recommend = 0.0;
            $jit = (int)$p['jit_enabled'] === 1 || (string)$p['reorder_method'] === 'JIT';
            if ($jit) {
                $recommend = max(($demand + $safety) - max($onHand - $reserved, 0), 0);
            } elseif ($projected < $rop) {
                $recommend = (float)$p['reorder_qty'] > 0 ? (float)$p['reorder_qty'] : ($rop - $projected);
            }

            if ($recommend > 0 && (float)$p['min_order_qty'] > 0 && $recommend < (float)$p['min_order_qty']) {
                $recommend = (float)$p['min_order_qty'];
            }
            if ($recommend > 0 && (float)$p['max_order_qty'] > 0 && $recommend > (float)$p['max_order_qty']) {
                $recommend = (float)$p['max_order_qty'];
            }

            $rows[] = [
                'item_code' => $item,
                'supplier_code' => (string)($p['supplier_code'] ?? ''),
                'method' => (string)$p['reorder_method'],
                'jit_enabled' => $jit,
                'on_hand_qty' => round($onHand, 4),
                'reserved_qty' => round($reserved, 4),
                'forecast_demand' => round($demand, 4),
                'projected_balance' => round($projected, 4),
                'reorder_point' => round($rop, 4),
                'recommended_qty' => round(max($recommend, 0), 4),
                'status' => $recommend > 0 ? 'REORDER' : 'OK',
            ];
        }

        return [
            'as_of' => $asOf,
            'horizon_days' => $horizonDays,
            'rows' => $rows,
            'reorder_count' => count(array_filter($rows, static fn(array $r): bool => $r['recommended_qty'] > 0)),
        ];
    }

    /** @return array<string, mixed> */
    public function jitPlan(string $asOfDate = '', int $horizonDays = 14): array
    {
        if ($horizonDays <= 0) {
            $horizonDays = 14;
        }

        $asOf = $this->normalizeDate($asOfDate);
        $to = (new DateTimeImmutable($asOf))->add(new DateInterval('P' . $horizonDays . 'D'))->format('Y-m-d');

        $stmt = $this->pdo->prepare(
            "SELECT order_no, item_code, order_qty, release_date
             FROM mfg_production_order
             WHERE status IN ('PLANNED', 'RELEASED', 'IN_PROGRESS')
               AND due_date BETWEEN :from_date AND :to_date
             ORDER BY due_date ASC, priority ASC, id ASC"
        );
        $stmt->execute(['from_date' => $asOf, 'to_date' => $to]);

        $req = [];
        $warnings = [];
        foreach ($stmt->fetchAll() as $order) {
            try {
                $bom = $this->bomExplosion((string)$order['item_code'], (float)$order['order_qty'], (string)$order['release_date']);
                foreach ($bom['components'] as $c) {
                    $code = (string)$c['component_item_code'];
                    $req[$code] = ($req[$code] ?? 0) + (float)$c['required_qty'];
                }
            } catch (RuntimeException $e) {
                $warnings[] = ['order_no' => (string)$order['order_no'], 'warning' => $e->getMessage()];
            }
        }

        $rows = [];
        foreach ($req as $code => $required) {
            $available = max($this->onHand($code) - $this->reserved($code), 0);
            $shortage = max($required - $available, 0);
            $rows[] = [
                'item_code' => $code,
                'required_qty' => round($required, 4),
                'available_qty' => round($available, 4),
                'shortage_qty' => round($shortage, 4),
                'status' => $shortage > 0 ? 'SHORTAGE' : 'READY',
            ];
        }

        usort($rows, static fn(array $a, array $b): int => $b['shortage_qty'] <=> $a['shortage_qty']);

        return [
            'as_of' => $asOf,
            'horizon_days' => $horizonDays,
            'rows' => $rows,
            'warnings' => $warnings,
            'shortage_count' => count(array_filter($rows, static fn(array $r): bool => $r['shortage_qty'] > 0)),
        ];
    }

    /** @return array<string, mixed> */
    public function createRequisitions(string $mode = 'ROP', string $asOfDate = '', int $horizonDays = 30): array
    {
        $mode = strtoupper(trim($mode));
        if (!in_array($mode, ['ROP', 'JIT'], true)) {
            $mode = 'ROP';
        }

        $plan = $mode === 'JIT'
            ? $this->jitPlan($asOfDate, max(1, $horizonDays))
            : $this->inventoryReorder($asOfDate, max(1, $horizonDays));

        $check = $this->pdo->prepare("SELECT id FROM purchase_requisition WHERE item_code = :item_code AND status = 'OPEN' LIMIT 1");
        $ins = $this->pdo->prepare(
            'INSERT INTO purchase_requisition (req_no, item_code, qty, uom, req_date, need_date, status, reason, source_order_no)
             VALUES (:req_no, :item_code, :qty, :uom, :req_date, :need_date, :status, :reason, :source_order_no)'
        );

        $created = [];
        foreach (($plan['rows'] ?? []) as $row) {
            $qty = $mode === 'JIT' ? (float)($row['shortage_qty'] ?? 0) : (float)($row['recommended_qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $item = (string)$row['item_code'];
            $check->execute(['item_code' => $item]);
            if ($check->fetchColumn() !== false) {
                continue;
            }

            $reqNo = 'PR' . date('YmdHis') . sprintf('%03d', random_int(1, 999));
            $need = (new DateTimeImmutable($asOfDate !== '' ? $asOfDate : date('Y-m-d')))->add(new DateInterval('P' . max($horizonDays, 1) . 'D'))->format('Y-m-d');
            $ins->execute([
                'req_no' => $reqNo,
                'item_code' => $item,
                'qty' => round($qty, 4),
                'uom' => 'PCS',
                'req_date' => date('Y-m-d'),
                'need_date' => $need,
                'status' => 'OPEN',
                'reason' => $mode . ' auto-plan',
                'source_order_no' => null,
            ]);

            $created[] = ['req_no' => $reqNo, 'item_code' => $item, 'qty' => round($qty, 4), 'mode' => $mode];
        }

        return ['mode' => $mode, 'created_count' => count($created), 'created' => $created];
    }

    /** @return array<string, mixed> */
    public function generateApsAdvanced(string $dateFrom = '', string $dateTo = '', bool $reschedule = false, string $reason = '', array $options = []): array
    {
        [$from, $to] = $this->normalizeDateRange($dateFrom, $dateTo, 30);

        $weightLate = max((float)($options['weight_late'] ?? 20.0), 0.0);
        $weightSetup = max((float)($options['weight_setup'] ?? 3.0), 0.0);
        $weightLoad = max((float)($options['weight_load'] ?? 1.0), 0.0);
        $defaultLotSize = max((float)($options['split_lot_default'] ?? 0), 0.0);
        $allowAlternative = !((string)($options['allow_alternative'] ?? '1') === '0');
        $maxBatches = max((int)($options['max_batches'] ?? 100), 1);

        $orderStmt = $this->pdo->prepare(
            "SELECT *
             FROM mfg_production_order
             WHERE status IN ('PLANNED', 'RELEASED', 'IN_PROGRESS')
               AND due_date BETWEEN :from_date AND :to_date
             ORDER BY priority ASC, due_date ASC, id ASC"
        );
        $orderStmt->execute([
            'from_date' => $from,
            'to_date' => $to,
        ]);
        $orders = $orderStmt->fetchAll();

        if (empty($orders)) {
            return [
                'from_date' => $from,
                'to_date' => $to,
                'orders' => 0,
                'scheduled_rows' => 0,
                'scheduled_batches' => 0,
                'unscheduled' => [],
                'tardy_orders' => 0,
                'penalty_total' => 0,
                'algorithm' => 'advanced_v2',
            ];
        }

        $orderIds = array_map(static fn(array $r): int => (int)$r['id'], $orders);
        if ($reschedule && !empty($orderIds)) {
            $in = implode(',', array_map('intval', $orderIds));
            $this->pdo->exec('DELETE FROM mfg_aps_schedule WHERE order_id IN (' . $in . ')');
        }

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO mfg_aps_schedule (
               order_id,
               op_no,
               batch_no,
               batch_qty,
               operation_name,
               work_center_code,
               planned_start,
               planned_end,
               planned_hours,
               setup_hours_applied,
               setup_from_item_code,
               setup_to_item_code,
               sequence_no,
               status,
               reschedule_reason,
               tardiness_hours,
               penalty_cost,
               heuristic_tag
             ) VALUES (
               :order_id,
               :op_no,
               :batch_no,
               :batch_qty,
               :operation_name,
               :work_center_code,
               :planned_start,
               :planned_end,
               :planned_hours,
               :setup_hours_applied,
               :setup_from_item_code,
               :setup_to_item_code,
               :sequence_no,
               :status,
               :reschedule_reason,
               :tardiness_hours,
               :penalty_cost,
               :heuristic_tag
             )'
        );

        $centerState = $this->loadCenterState($from, $to);
        $scheduledRows = 0;
        $scheduledBatches = 0;
        $penaltyTotal = 0.0;
        $unscheduled = [];
        $tardyOrders = [];

        foreach ($orders as $order) {
            $orderId = (int)$order['id'];
            $itemCode = (string)$order['item_code'];
            $orderQty = (float)$order['order_qty'];
            $orderNo = (string)$order['order_no'];

            $routingHeader = $this->activeRoutingHeader($itemCode, (string)$order['release_date']);
            if (!$routingHeader) {
                $unscheduled[] = ['order_no' => $orderNo, 'reason' => 'active routing not found'];
                continue;
            }

            $stepStmt = $this->pdo->prepare('SELECT * FROM mfg_routing_step WHERE routing_id = :routing_id ORDER BY op_no ASC, id ASC');
            $stepStmt->execute(['routing_id' => (int)$routingHeader['id']]);
            $steps = $stepStmt->fetchAll();
            if (empty($steps)) {
                $unscheduled[] = ['order_no' => $orderNo, 'reason' => 'routing steps not found'];
                continue;
            }

            $lotSize = max((float)($order['lot_size'] ?? 0), 0.0);
            if ((int)($order['split_allowed'] ?? 1) !== 1) {
                $lotSize = 0.0;
            }
            if ($lotSize <= 0 && $defaultLotSize > 0) {
                $lotSize = $defaultLotSize;
            }

            $batches = $this->orderBatches($orderQty, $lotSize, $maxBatches);
            if (empty($batches)) {
                $unscheduled[] = ['order_no' => $orderNo, 'reason' => 'invalid batch definition'];
                continue;
            }

            $releaseStart = new DateTimeImmutable((string)$order['release_date'] . ' 00:00:00');
            $dueAt = new DateTimeImmutable((string)$order['due_date'] . ' 23:59:59');
            $orderTardyHours = 0.0;
            $sequence = 1;
            $batchNo = 1;
            $orderFailed = false;

            foreach ($batches as $batchQty) {
                $cursor = $releaseStart;
                $batchFailed = false;

                foreach ($steps as $step) {
                    $primaryCenter = (string)$step['primary_center_code'];
                    $altGroup = (string)($step['alt_group'] ?? '');
                    $candidateCenters = $this->centerCandidates($primaryCenter, $altGroup, $allowAlternative);
                    if (empty($candidateCenters)) {
                        $batchFailed = true;
                        $unscheduled[] = ['order_no' => $orderNo, 'reason' => 'no candidate center for op ' . (string)$step['op_no']];
                        break;
                    }

                    $best = null;
                    foreach ($candidateCenters as $candidate) {
                        $centerCode = (string)$candidate['center_code'];
                        $centerNext = $centerState['next_at'][$centerCode] ?? null;

                        $candidateStart = $cursor;
                        if ($centerNext instanceof DateTimeImmutable && $centerNext > $candidateStart) {
                            $candidateStart = $centerNext;
                        }

                        $alignedStart = $this->alignToShiftStart($centerCode, $candidateStart, $to);
                        if (!$alignedStart) {
                            continue;
                        }

                        $lastItem = (string)($centerState['last_item'][$centerCode] ?? '');
                        $setupExtra = $this->setupExtraHours($centerCode, $lastItem, $itemCode);
                        $baseSetup = (float)($step['setup_hours'] ?? 0);
                        $runHours = (float)($step['run_hours_per_unit'] ?? 0) * (float)$batchQty;
                        $queueHours = (float)($step['queue_hours'] ?? 0);
                        $moveHours = (float)($step['move_hours'] ?? 0);
                        $processHours = max($baseSetup + $setupExtra + $runHours + $queueHours + $moveHours, 0.0001);

                        $endAt = $this->addHoursWithinShifts($centerCode, $alignedStart, $processHours, $to);
                        if (!$endAt) {
                            continue;
                        }

                        $tardiness = max(($endAt->getTimestamp() - $dueAt->getTimestamp()) / 3600, 0);
                        $workDate = $alignedStart->format('Y-m-d');
                        $dayKey = $this->centerDayKey($centerCode, $workDate);
                        $dayLoad = (float)($centerState['load_day'][$dayKey] ?? 0) + $processHours;
                        $dayCapacity = max($this->capacityHours($centerCode, $workDate), 0.0001);
                        $loadOverflow = max($dayLoad - $dayCapacity, 0);

                        $penalty = ($tardiness * $weightLate)
                            + (($baseSetup + $setupExtra) * $weightSetup)
                            + ($loadOverflow * $weightLoad)
                            + ((int)($candidate['is_alternative'] ?? 0) === 1 ? 0.25 : 0);

                        $candidateRow = [
                            'center_code' => $centerCode,
                            'operation_name' => (string)$step['operation_name'],
                            'op_no' => (int)$step['op_no'],
                            'batch_no' => $batchNo,
                            'batch_qty' => (float)$batchQty,
                            'start' => $alignedStart,
                            'end' => $endAt,
                            'work_date' => $workDate,
                            'process_hours' => $processHours,
                            'setup_hours_applied' => $setupExtra,
                            'setup_from_item_code' => $lastItem !== '' ? $lastItem : null,
                            'setup_to_item_code' => $itemCode,
                            'tardiness_hours' => $tardiness,
                            'penalty_cost' => $penalty,
                        ];

                        if ($best === null || (float)$candidateRow['penalty_cost'] < (float)$best['penalty_cost']) {
                            $best = $candidateRow;
                        }
                    }

                    if ($best === null) {
                        $batchFailed = true;
                        $unscheduled[] = ['order_no' => $orderNo, 'reason' => 'no feasible slot for op ' . (string)$step['op_no'] . ' batch ' . $batchNo];
                        break;
                    }

                    $insertStmt->execute([
                        'order_id' => $orderId,
                        'op_no' => $best['op_no'],
                        'batch_no' => $best['batch_no'],
                        'batch_qty' => round((float)$best['batch_qty'], 4),
                        'operation_name' => $best['operation_name'],
                        'work_center_code' => $best['center_code'],
                        'planned_start' => $best['start']->format('Y-m-d H:i:s'),
                        'planned_end' => $best['end']->format('Y-m-d H:i:s'),
                        'planned_hours' => round((float)$best['process_hours'], 4),
                        'setup_hours_applied' => round((float)$best['setup_hours_applied'], 4),
                        'setup_from_item_code' => $best['setup_from_item_code'],
                        'setup_to_item_code' => $best['setup_to_item_code'],
                        'sequence_no' => $sequence,
                        'status' => 'PLANNED',
                        'reschedule_reason' => $reschedule ? $reason : null,
                        'tardiness_hours' => round((float)$best['tardiness_hours'], 4),
                        'penalty_cost' => round((float)$best['penalty_cost'], 4),
                        'heuristic_tag' => 'advanced_v2',
                    ]);

                    $centerCode = (string)$best['center_code'];
                    $centerState['next_at'][$centerCode] = $best['end']->add(new DateInterval('PT5M'));
                    $centerState['last_item'][$centerCode] = $itemCode;
                    $dayKey = $this->centerDayKey($centerCode, (string)$best['work_date']);
                    $centerState['load_day'][$dayKey] = (float)($centerState['load_day'][$dayKey] ?? 0) + (float)$best['process_hours'];

                    $cursor = $best['end']->add(new DateInterval('PT5M'));
                    $sequence++;
                    $scheduledRows++;
                    $penaltyTotal += (float)$best['penalty_cost'];
                    $orderTardyHours += (float)$best['tardiness_hours'];
                }

                if ($batchFailed) {
                    $orderFailed = true;
                    break;
                }

                $scheduledBatches++;
                $batchNo++;
            }

            if (!$orderFailed) {
                $up = $this->pdo->prepare('UPDATE mfg_production_order SET status = :status WHERE id = :id');
                $up->execute(['status' => 'RELEASED', 'id' => $orderId]);
            }

            if ($orderTardyHours > 0) {
                $tardyOrders[] = [
                    'order_no' => $orderNo,
                    'tardiness_hours' => round($orderTardyHours, 4),
                ];
            }
        }

        return [
            'from_date' => $from,
            'to_date' => $to,
            'orders' => count($orders),
            'scheduled_rows' => $scheduledRows,
            'scheduled_batches' => $scheduledBatches,
            'unscheduled' => $unscheduled,
            'tardy_orders' => count($tardyOrders),
            'tardy_order_rows' => $tardyOrders,
            'penalty_total' => round($penaltyTotal, 4),
            'weights' => [
                'late' => $weightLate,
                'setup' => $weightSetup,
                'load' => $weightLoad,
            ],
            'reschedule' => $reschedule,
            'algorithm' => 'advanced_v2',
        ];
    }

    /** @return array<string, mixed> */
    public function dashboardSnapshot(int $periodHours = 24, int $utilDays = 7): array
    {
        $periodHours = max(min($periodHours, 240), 1);
        $utilDays = max(min($utilDays, 60), 1);

        $toDate = date('Y-m-d');
        $fromDate = (new DateTimeImmutable($toDate))->sub(new DateInterval('P' . ($utilDays - 1) . 'D'))->format('Y-m-d');

        $statusStmt = $this->pdo->query(
            "SELECT status, COUNT(*) AS count_rows
             FROM mfg_production_order
             GROUP BY status"
        );
        $orderStatus = [];
        foreach ($statusStmt->fetchAll() as $row) {
            $orderStatus[] = [
                'status' => (string)$row['status'],
                'count' => (int)$row['count_rows'],
            ];
        }

        $liveStmt = $this->pdo->prepare(
            "SELECT s.work_center_code, o.order_no, o.item_code, s.op_no, s.operation_name, s.planned_start, s.planned_end
             FROM mfg_aps_schedule s
             INNER JOIN mfg_production_order o ON o.id = s.order_id
             WHERE NOW() BETWEEN s.planned_start AND s.planned_end
             ORDER BY s.work_center_code, s.planned_start"
        );
        $liveStmt->execute();
        $liveRows = $liveStmt->fetchAll();

        $util = $this->resourceUtilization($fromDate, $toDate);
        $utilByCenter = [];
        foreach ($util['rows'] ?? [] as $row) {
            $center = (string)$row['work_center_code'];
            if (!isset($utilByCenter[$center])) {
                $utilByCenter[$center] = [
                    'work_center_code' => $center,
                    'planned_hours' => 0.0,
                    'capacity_hours' => 0.0,
                ];
            }
            $utilByCenter[$center]['planned_hours'] += (float)$row['planned_hours'];
            $utilByCenter[$center]['capacity_hours'] += (float)$row['capacity_hours'];
        }

        $utilRows = [];
        foreach ($utilByCenter as $row) {
            $capacity = max((float)$row['capacity_hours'], 0.0001);
            $utilRows[] = [
                'work_center_code' => (string)$row['work_center_code'],
                'planned_hours' => round((float)$row['planned_hours'], 4),
                'capacity_hours' => round((float)$row['capacity_hours'], 4),
                'utilization_pct' => round(((float)$row['planned_hours'] / $capacity) * 100, 2),
            ];
        }

        usort($utilRows, static fn(array $a, array $b): int => strcmp($a['work_center_code'], $b['work_center_code']));

        $sensorFrom = (new DateTimeImmutable('now'))->sub(new DateInterval('PT' . $periodHours . 'H'))->format('Y-m-d H:i:s');
        $sensorStmt = $this->pdo->prepare(
            "SELECT machine_code,
                    COUNT(*) AS sample_count,
                    COALESCE(SUM(runtime_hours_increment), 0) AS runtime_hours,
                    COALESCE(AVG(vibration), 0) AS avg_vibration,
                    COALESCE(AVG(temp_c), 0) AS avg_temp
             FROM machine_runtime_log
             WHERE log_time >= :from_time
             GROUP BY machine_code
             ORDER BY machine_code"
        );
        $sensorStmt->execute(['from_time' => $sensorFrom]);
        $sensorRows = $sensorStmt->fetchAll();

        $dueSoonStmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM mfg_production_order
             WHERE status IN ('PLANNED', 'RELEASED', 'IN_PROGRESS')
               AND due_date <= :due_date"
        );
        $dueSoonStmt->execute(['due_date' => (new DateTimeImmutable($toDate))->add(new DateInterval('P7D'))->format('Y-m-d')]);

        $maintDue = $this->maintenanceDue();
        $maintRisk = $this->maintenanceRisk($periodHours);
        $reorder = $this->inventoryReorder($toDate, 14);

        $topShortages = array_values(array_filter($reorder['rows'] ?? [], static fn(array $r): bool => (float)$r['recommended_qty'] > 0));
        usort($topShortages, static fn(array $a, array $b): int => ((float)$b['recommended_qty'] <=> (float)$a['recommended_qty']));
        $topShortages = array_slice($topShortages, 0, 10);

        return [
            'as_of' => date('Y-m-d H:i:s'),
            'period_hours' => $periodHours,
            'util_days' => $utilDays,
            'summary' => [
                'open_orders' => array_sum(array_map(static fn(array $r): int => in_array($r['status'], ['PLANNED', 'RELEASED', 'IN_PROGRESS'], true) ? (int)$r['count'] : 0, $orderStatus)),
                'due_7d' => (int)($dueSoonStmt->fetchColumn() ?: 0),
                'live_operations' => count($liveRows),
                'maintenance_due' => (int)($maintDue['count'] ?? 0),
                'maintenance_high_risk' => (int)($maintRisk['high_risk_count'] ?? 0),
                'reorder_count' => (int)($reorder['reorder_count'] ?? 0),
            ],
            'order_status' => $orderStatus,
            'live_operations' => $liveRows,
            'utilization' => $utilRows,
            'sensor_health' => $sensorRows,
            'top_shortages' => $topShortages,
        ];
    }

    /**
     * @return float[]
     */
    private function orderBatches(float $orderQty, float $lotSize, int $maxBatches = 100): array
    {
        $orderQty = max($orderQty, 0);
        if ($orderQty <= 0) {
            return [];
        }

        if ($lotSize <= 0 || $lotSize >= $orderQty) {
            return [round($orderQty, 4)];
        }

        $batches = [];
        $remaining = $orderQty;
        while ($remaining > 0.000001 && count($batches) < $maxBatches) {
            $qty = min($lotSize, $remaining);
            $batches[] = round($qty, 4);
            $remaining -= $qty;
        }

        if ($remaining > 0.000001 && !empty($batches)) {
            $last = count($batches) - 1;
            $batches[$last] = round($batches[$last] + $remaining, 4);
        }

        return $batches;
    }

    /** @return array<int, array<string, mixed>> */
    private function centerCandidates(string $primaryCenterCode, string $altGroup = '', bool $allowAlternative = true): array
    {
        $rows = [];
        $seen = [];

        $primaryStmt = $this->pdo->prepare('SELECT center_code, status, priority_rank FROM mfg_work_center WHERE center_code = :center_code LIMIT 1');
        $primaryStmt->execute(['center_code' => $primaryCenterCode]);
        $primary = $primaryStmt->fetch();
        if (is_array($primary)) {
            $rows[] = [
                'center_code' => (string)$primary['center_code'],
                'status' => (string)$primary['status'],
                'priority_rank' => (int)$primary['priority_rank'],
                'is_alternative' => 0,
            ];
            $seen[(string)$primary['center_code']] = true;
        }

        if ($allowAlternative) {
            $sql = "SELECT a.alternative_center_code AS center_code,
                           w.status,
                           LEAST(a.priority_rank, w.priority_rank) AS priority_rank,
                           1 AS is_alternative
                    FROM mfg_work_center_alt a
                    INNER JOIN mfg_work_center w ON w.center_code = a.alternative_center_code
                    WHERE a.active = 1
                      AND a.primary_center_code = :primary_center_code";
            $params = ['primary_center_code' => $primaryCenterCode];
            if ($altGroup !== '') {
                $sql .= ' AND a.alt_group = :alt_group';
                $params['alt_group'] = $altGroup;
            }
            $sql .= ' ORDER BY a.priority_rank ASC, w.priority_rank ASC, a.id ASC';

            $altStmt = $this->pdo->prepare($sql);
            $altStmt->execute($params);
            foreach ($altStmt->fetchAll() as $row) {
                $code = (string)$row['center_code'];
                if (isset($seen[$code])) {
                    continue;
                }
                $rows[] = [
                    'center_code' => $code,
                    'status' => (string)$row['status'],
                    'priority_rank' => (int)$row['priority_rank'],
                    'is_alternative' => 1,
                ];
                $seen[$code] = true;
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $aAvail = ($a['status'] ?? '') === 'AVAILABLE' ? 0 : 1;
            $bAvail = ($b['status'] ?? '') === 'AVAILABLE' ? 0 : 1;
            if ($aAvail !== $bAvail) {
                return $aAvail <=> $bAvail;
            }
            if (($a['is_alternative'] ?? 0) !== ($b['is_alternative'] ?? 0)) {
                return (int)$a['is_alternative'] <=> (int)$b['is_alternative'];
            }
            return (int)($a['priority_rank'] ?? 999) <=> (int)($b['priority_rank'] ?? 999);
        });

        return $rows;
    }

    /**
     * @return array{next_at: array<string, DateTimeImmutable>, last_item: array<string, string>, load_day: array<string, float>}
     */
    private function loadCenterState(string $fromDate, string $toDate): array
    {
        $state = [
            'next_at' => [],
            'last_item' => [],
            'load_day' => [],
        ];

        $lastStmt = $this->pdo->prepare(
            "SELECT s.work_center_code,
                    MAX(s.planned_end) AS last_end,
                    SUBSTRING_INDEX(GROUP_CONCAT(o.item_code ORDER BY s.planned_end DESC SEPARATOR ','), ',', 1) AS last_item
             FROM mfg_aps_schedule s
             INNER JOIN mfg_production_order o ON o.id = s.order_id
             WHERE DATE(s.planned_end) BETWEEN :from_date AND :to_date
             GROUP BY s.work_center_code"
        );
        $lastStmt->execute([
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
        foreach ($lastStmt->fetchAll() as $row) {
            $center = (string)$row['work_center_code'];
            $end = (string)($row['last_end'] ?? '');
            if ($center === '' || $end === '') {
                continue;
            }
            $state['next_at'][$center] = new DateTimeImmutable($end);
            $state['last_item'][$center] = (string)($row['last_item'] ?? '');
        }

        $loadStmt = $this->pdo->prepare(
            "SELECT work_center_code, DATE(planned_start) AS work_date, SUM(planned_hours) AS used_hours
             FROM mfg_aps_schedule
             WHERE DATE(planned_start) BETWEEN :from_date AND :to_date
             GROUP BY work_center_code, DATE(planned_start)"
        );
        $loadStmt->execute([
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
        foreach ($loadStmt->fetchAll() as $row) {
            $key = $this->centerDayKey((string)$row['work_center_code'], (string)$row['work_date']);
            $state['load_day'][$key] = (float)$row['used_hours'];
        }

        return $state;
    }

    private function setupExtraHours(string $centerCode, string $fromItemCode, string $toItemCode): float
    {
        $toItemCode = trim($toItemCode);
        if ($toItemCode === '') {
            return 0.0;
        }

        $from = trim($fromItemCode);
        if ($from === '') {
            $from = '*';
        }

        $stmt = $this->pdo->prepare(
            "SELECT setup_hours
             FROM mfg_setup_matrix
             WHERE active = 1
               AND center_code = :center_code
               AND (from_item_code = :from_item OR from_item_code = '*')
               AND (to_item_code = :to_item OR to_item_code = '*')
             ORDER BY
               (from_item_code = :from_item_exact) DESC,
               (to_item_code = :to_item_exact) DESC,
               id ASC
             LIMIT 1"
        );
        $stmt->execute([
            'center_code' => $centerCode,
            'from_item' => $from,
            'to_item' => $toItemCode,
            'from_item_exact' => $from,
            'to_item_exact' => $toItemCode,
        ]);

        $val = $stmt->fetchColumn();
        return $val !== false ? max((float)$val, 0.0) : 0.0;
    }

    private function alignToShiftStart(string $centerCode, DateTimeImmutable $candidate, string $limitDate): ?DateTimeImmutable
    {
        $limit = new DateTimeImmutable($limitDate . ' 23:59:59');
        $current = $candidate;

        for ($i = 0; $i < 400; $i++) {
            if ($current > $limit) {
                return null;
            }

            $windows = $this->shiftWindows($centerCode, $current->format('Y-m-d'));
            foreach ($windows as $window) {
                /** @var DateTimeImmutable $start */
                $start = $window['start'];
                /** @var DateTimeImmutable $end */
                $end = $window['end'];

                if ($current <= $end) {
                    return $current < $start ? $start : $current;
                }
            }

            $current = (new DateTimeImmutable($current->format('Y-m-d') . ' 00:00:00'))->add(new DateInterval('P1D'));
        }

        return null;
    }

    private function addHoursWithinShifts(string $centerCode, DateTimeImmutable $startAt, float $hours, string $limitDate): ?DateTimeImmutable
    {
        $remainingMinutes = max((int)ceil($hours * 60), 1);
        $current = $startAt;
        $limit = new DateTimeImmutable($limitDate . ' 23:59:59');

        for ($guard = 0; $guard < 5000; $guard++) {
            if ($current > $limit) {
                return null;
            }

            $aligned = $this->alignToShiftStart($centerCode, $current, $limitDate);
            if (!$aligned) {
                return null;
            }

            $activeWindow = null;
            $windows = $this->shiftWindows($centerCode, $aligned->format('Y-m-d'));
            foreach ($windows as $window) {
                /** @var DateTimeImmutable $start */
                $start = $window['start'];
                /** @var DateTimeImmutable $end */
                $end = $window['end'];
                if ($aligned >= $start && $aligned < $end) {
                    $activeWindow = $window;
                    break;
                }
            }

            if ($activeWindow === null) {
                $current = $aligned->add(new DateInterval('PT1M'));
                continue;
            }

            /** @var DateTimeImmutable $windowEnd */
            $windowEnd = $activeWindow['end'];
            $availableMinutes = (int)floor(($windowEnd->getTimestamp() - $aligned->getTimestamp()) / 60);
            if ($availableMinutes <= 0) {
                $current = $windowEnd->add(new DateInterval('PT1M'));
                continue;
            }

            if ($availableMinutes >= $remainingMinutes) {
                return $aligned->add(new DateInterval('PT' . $remainingMinutes . 'M'));
            }

            $remainingMinutes -= $availableMinutes;
            $current = $windowEnd->add(new DateInterval('PT1M'));
        }

        return null;
    }

    /**
     * @return array<int, array{start: DateTimeImmutable, end: DateTimeImmutable}>
     */
    private function shiftWindows(string $centerCode, string $workDate): array
    {
        static $cache = [];

        $cacheKey = $centerCode . '|' . $workDate;
        if (isset($cache[$cacheKey]) && is_array($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $weekday = (int)(new DateTimeImmutable($workDate . ' 00:00:00'))->format('N');
        $stmt = $this->pdo->prepare(
            "SELECT start_time, end_time, break_minutes
             FROM mfg_work_center_shift
             WHERE center_code = :center_code
               AND active = 1
               AND (day_of_week IS NULL OR day_of_week = :day_of_week)
               AND (effective_from IS NULL OR effective_from <= :work_date)
               AND (effective_to IS NULL OR effective_to >= :work_date)
             ORDER BY shift_no ASC, start_time ASC"
        );
        $stmt->execute([
            'center_code' => $centerCode,
            'day_of_week' => $weekday,
            'work_date' => $workDate,
        ]);

        $windows = [];
        foreach ($stmt->fetchAll() as $row) {
            $start = new DateTimeImmutable($workDate . ' ' . (string)$row['start_time']);
            $end = new DateTimeImmutable($workDate . ' ' . (string)$row['end_time']);
            if ($end <= $start) {
                $end = $end->add(new DateInterval('P1D'));
            }

            $breakMinutes = max((int)($row['break_minutes'] ?? 0), 0);
            if ($breakMinutes > 0) {
                $end = $end->sub(new DateInterval('PT' . $breakMinutes . 'M'));
            }

            if ($end > $start) {
                $windows[] = ['start' => $start, 'end' => $end];
            }
        }

        if (empty($windows)) {
            $capacity = $this->capacityHours($centerCode, $workDate);
            if ($capacity > 0) {
                $start = new DateTimeImmutable($workDate . ' 08:00:00');
                $minutes = max((int)floor($capacity * 60), 1);
                $end = $start->add(new DateInterval('PT' . $minutes . 'M'));
                $windows[] = ['start' => $start, 'end' => $end];
            }
        }

        $cache[$cacheKey] = $windows;
        return $windows;
    }

    /** @return array<string, mixed> */
    public function generateMaintenanceWorkOrders(string $asOfDate = '', int $windowHours = 72): array
    {
        $asOf = $this->normalizeDateTime($asOfDate);
        $due = $this->maintenanceDue($asOf);
        $risk = $this->maintenanceRisk($windowHours);

        $candidates = [];
        foreach ($due['rows'] ?? [] as $row) {
            $machine = (string)($row['machine_code'] ?? '');
            if ($machine === '') {
                continue;
            }

            $candidates[$machine] = [
                'machine_code' => $machine,
                'issue_type' => (string)($row['plan_type'] ?? 'PREVENTIVE'),
                'priority' => 'HIGH',
                'predicted_risk' => null,
                'note' => 'Auto WO from maintenance due: ' . (string)($row['due_reason'] ?? ''),
            ];
        }

        foreach ($risk['rows'] ?? [] as $row) {
            $machine = (string)($row['machine_code'] ?? '');
            if ($machine === '') {
                continue;
            }

            $level = (string)($row['risk_level'] ?? 'LOW');
            if (!in_array($level, ['HIGH', 'CRITICAL'], true)) {
                continue;
            }

            $candidates[$machine] = [
                'machine_code' => $machine,
                'issue_type' => 'PREDICTIVE',
                'priority' => $level === 'CRITICAL' ? 'CRITICAL' : 'HIGH',
                'predicted_risk' => (float)($row['risk_score'] ?? 0),
                'note' => 'Auto WO from predictive risk level: ' . $level,
            ];
        }

        if (empty($candidates)) {
            return ['as_of' => $asOf, 'created_count' => 0, 'created' => []];
        }

        $openStmt = $this->pdo->prepare(
            "SELECT id FROM maintenance_work_order
             WHERE machine_code = :machine_code
               AND issue_type = :issue_type
               AND status IN ('OPEN', 'IN_PROGRESS')
             LIMIT 1"
        );

        $insStmt = $this->pdo->prepare(
            'INSERT INTO maintenance_work_order (
                wo_no,
                machine_code,
                issue_type,
                opened_at,
                due_date,
                priority,
                status,
                predicted_risk,
                note
            ) VALUES (
                :wo_no,
                :machine_code,
                :issue_type,
                :opened_at,
                :due_date,
                :priority,
                :status,
                :predicted_risk,
                :note
            )'
        );

        $created = [];
        foreach ($candidates as $machine => $row) {
            $openStmt->execute([
                'machine_code' => $machine,
                'issue_type' => $row['issue_type'],
            ]);

            if ($openStmt->fetchColumn() !== false) {
                continue;
            }

            $woNo = 'MWO' . date('YmdHis') . sprintf('%03d', random_int(1, 999));
            $dueDate = (new DateTimeImmutable($asOf))->add(new DateInterval('P3D'))->format('Y-m-d H:i:s');
            $insStmt->execute([
                'wo_no' => $woNo,
                'machine_code' => $machine,
                'issue_type' => $row['issue_type'],
                'opened_at' => $asOf,
                'due_date' => $dueDate,
                'priority' => $row['priority'],
                'status' => 'OPEN',
                'predicted_risk' => $row['predicted_risk'],
                'note' => $row['note'],
            ]);

            $created[] = [
                'wo_no' => $woNo,
                'machine_code' => $machine,
                'issue_type' => $row['issue_type'],
                'priority' => $row['priority'],
                'predicted_risk' => $row['predicted_risk'],
            ];
        }

        return [
            'as_of' => $asOf,
            'created_count' => count($created),
            'created' => $created,
        ];
    }

    /** @return array<string, mixed>|null */
    private function activeBomHeader(string $itemCode, string $asOf): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM mfg_bom_header
             WHERE item_code = :item_code
               AND status = 'APPROVED'
               AND effective_from <= :as_of
               AND (effective_to IS NULL OR effective_to >= :as_of)
             ORDER BY is_default DESC, effective_from DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([
            'item_code' => $itemCode,
            'as_of' => $asOf,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function activeRoutingHeader(string $itemCode, string $asOf): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM mfg_routing_header
             WHERE item_code = :item_code
               AND status = 'APPROVED'
               AND effective_from <= :as_of
               AND (effective_to IS NULL OR effective_to >= :as_of)
             ORDER BY is_default DESC, effective_from DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([
            'item_code' => $itemCode,
            'as_of' => $asOf,
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function selectCenter(string $primaryCenterCode, string $altGroup = ''): array
    {
        $primaryCenterCode = trim($primaryCenterCode);
        $altGroup = trim($altGroup);

        if ($primaryCenterCode === '') {
            throw new RuntimeException('primary_center_code is required');
        }

        $centerStmt = $this->pdo->prepare('SELECT center_code, status FROM mfg_work_center WHERE center_code = :center_code LIMIT 1');
        $centerStmt->execute(['center_code' => $primaryCenterCode]);
        $primary = $centerStmt->fetch();
        if (is_array($primary) && (string)$primary['status'] === 'AVAILABLE') {
            return [
                'center_code' => (string)$primary['center_code'],
                'status' => (string)$primary['status'],
                'is_alternative' => false,
            ];
        }

        $altSql = "SELECT
                      a.alternative_center_code AS center_code,
                      w.status,
                      a.priority_rank,
                      w.priority_rank AS center_priority
                   FROM mfg_work_center_alt a
                   INNER JOIN mfg_work_center w ON w.center_code = a.alternative_center_code
                   WHERE a.active = 1
                     AND a.primary_center_code = :primary_center_code";
        $params = ['primary_center_code' => $primaryCenterCode];
        if ($altGroup !== '') {
            $altSql .= ' AND a.alt_group = :alt_group';
            $params['alt_group'] = $altGroup;
        }
        $altSql .= ' ORDER BY (w.status = \'AVAILABLE\') DESC, a.priority_rank ASC, w.priority_rank ASC, a.id ASC';

        $altStmt = $this->pdo->prepare($altSql);
        $altStmt->execute($params);
        $alt = $altStmt->fetch();
        if (is_array($alt)) {
            return [
                'center_code' => (string)$alt['center_code'],
                'status' => (string)$alt['status'],
                'is_alternative' => true,
            ];
        }

        return [
            'center_code' => $primaryCenterCode,
            'status' => is_array($primary) ? (string)$primary['status'] : 'UNKNOWN',
            'is_alternative' => false,
        ];
    }

    /**
     * @return array<string, float>
     */
    private function loadUsedHours(string $fromDate, string $toDate): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT work_center_code, DATE(planned_start) AS work_date, SUM(planned_hours) AS used_hours
             FROM mfg_aps_schedule
             WHERE DATE(planned_start) BETWEEN :from_date AND :to_date
             GROUP BY work_center_code, DATE(planned_start)"
        );
        $stmt->execute([
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);

        $used = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = $this->centerDayKey((string)$row['work_center_code'], (string)$row['work_date']);
            $used[$key] = (float)$row['used_hours'];
        }

        return $used;
    }

    /**
     * @param array<string, float> $used
     * @return array<string, string>|null
     */
    private function findNextSlot(string $centerCode, DateTimeImmutable $cursor, float $hours, array $used, string $limitDate): ?array
    {
        if ($hours <= 0) {
            return null;
        }

        $day = new DateTimeImmutable($cursor->format('Y-m-d') . ' 00:00:00');
        $limit = new DateTimeImmutable($limitDate . ' 23:59:59');

        while ($day <= $limit) {
            $workDate = $day->format('Y-m-d');
            $capacity = $this->capacityHours($centerCode, $workDate);
            if ($capacity <= 0) {
                $day = $day->add(new DateInterval('P1D'));
                continue;
            }

            $key = $this->centerDayKey($centerCode, $workDate);
            $usedHours = (float)($used[$key] ?? 0);
            $remaining = $capacity - $usedHours;
            if ($remaining <= 0 || $remaining < $hours) {
                $day = $day->add(new DateInterval('P1D'));
                continue;
            }

            $dayStart = new DateTimeImmutable($workDate . ' 08:00:00');
            $dayClose = $dayStart->add(new DateInterval('PT' . max((int)floor($capacity * 60), 1) . 'M'));
            $nextStart = $dayStart->add(new DateInterval('PT' . max((int)floor($usedHours * 60), 0) . 'M'));
            if ($nextStart < $cursor) {
                $nextStart = $cursor;
            }

            $end = $nextStart->add(new DateInterval('PT' . max((int)floor($hours * 60), 1) . 'M'));
            if ($end > $dayClose) {
                $day = $day->add(new DateInterval('P1D'));
                continue;
            }

            return [
                'work_date' => $workDate,
                'start' => $nextStart->format('Y-m-d H:i:s'),
                'end' => $end->format('Y-m-d H:i:s'),
            ];
        }

        return null;
    }

    private function capacityHours(string $centerCode, string $workDate): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT available_hours
             FROM mfg_work_center_calendar
             WHERE center_code = :center_code AND work_date = :work_date
             LIMIT 1'
        );
        $stmt->execute([
            'center_code' => $centerCode,
            'work_date' => $workDate,
        ]);
        $calendarValue = $stmt->fetchColumn();
        if ($calendarValue !== false) {
            return max((float)$calendarValue, 0);
        }

        $stmt = $this->pdo->prepare('SELECT capacity_hours_per_day FROM mfg_work_center WHERE center_code = :center_code LIMIT 1');
        $stmt->execute(['center_code' => $centerCode]);
        $base = $stmt->fetchColumn();
        if ($base !== false) {
            return max((float)$base, 0);
        }

        return 8.0;
    }

    private function centerDayKey(string $centerCode, string $workDate): string
    {
        return $centerCode . '|' . $workDate;
    }

    /** @return array<string, mixed>|null */
    private function lotRow(string $lotNo): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mfg_lot WHERE lot_no = :lot_no LIMIT 1');
        $stmt->execute(['lot_no' => $lotNo]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    private function forecastDemand(string $itemCode, string $fromDate, string $toDate): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(forecast_qty), 0)
             FROM demand_forecast
             WHERE item_code = :item_code
               AND forecast_date BETWEEN :from_date AND :to_date'
        );
        $stmt->execute([
            'item_code' => $itemCode,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
        $forecast = (float)($stmt->fetchColumn() ?: 0);
        if ($forecast > 0) {
            return $forecast;
        }

        $orderStmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(order_qty), 0)
             FROM mfg_production_order
             WHERE item_code = :item_code
               AND due_date BETWEEN :from_date AND :to_date
               AND status IN ('PLANNED', 'RELEASED', 'IN_PROGRESS')"
        );
        $orderStmt->execute([
            'item_code' => $itemCode,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);

        return (float)($orderStmt->fetchColumn() ?: 0);
    }

    private function reserved(string $itemCode): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(GREATEST(qty_reserved - qty_issued, 0)), 0)
             FROM mfg_material_reservation
             WHERE item_code = :item_code'
        );
        $stmt->execute(['item_code' => $itemCode]);
        $reserved = (float)($stmt->fetchColumn() ?: 0);

        $snapStmt = $this->pdo->prepare(
            'SELECT reserved_qty
             FROM mfg_inventory_snapshot
             WHERE item_code = :item_code
             LIMIT 1'
        );
        $snapStmt->execute(['item_code' => $itemCode]);
        $snapshotReserved = (float)($snapStmt->fetchColumn() ?: 0);

        return max($reserved, $snapshotReserved, 0);
    }

    private function onHand(string $itemCode): float
    {
        $stmt = $this->pdo->prepare(
            'SELECT on_hand_qty
             FROM mfg_inventory_snapshot
             WHERE item_code = :item_code
             LIMIT 1'
        );
        $stmt->execute(['item_code' => $itemCode]);
        $snapshot = $stmt->fetchColumn();
        if ($snapshot !== false) {
            return max((float)$snapshot, 0);
        }

        $cols = $this->productColumns();
        if ($cols['code'] !== '' && $cols['id'] !== '') {
            $sql = 'SELECT sc.TRANS_ALL_BALANCE
                    FROM stockcard sc
                    INNER JOIN product p ON p.' . $this->schema->quoteIdentifier($cols['id']) . ' = sc.product_id
                    WHERE p.' . $this->schema->quoteIdentifier($cols['code']) . ' = :item_code
                    ORDER BY sc.TRANS_DATE DESC, sc.id DESC
                    LIMIT 1';
            $stockStmt = $this->pdo->prepare($sql);
            $stockStmt->execute(['item_code' => $itemCode]);
            $stockBalance = $stockStmt->fetchColumn();
            if ($stockBalance !== false) {
                return max((float)$stockBalance, 0);
            }
        }

        if ($cols['code'] !== '' && $cols['buy'] !== '' && $cols['sale'] !== '') {
            $sql = 'SELECT
                        COALESCE(' . $this->schema->quoteIdentifier($cols['buy']) . ', 0) AS buy_qty,
                        COALESCE(' . $this->schema->quoteIdentifier($cols['sale']) . ', 0) AS sale_qty
                    FROM product
                    WHERE ' . $this->schema->quoteIdentifier($cols['code']) . ' = :item_code
                    LIMIT 1';
            $productStmt = $this->pdo->prepare($sql);
            $productStmt->execute(['item_code' => $itemCode]);
            $row = $productStmt->fetch();
            if (is_array($row)) {
                return max((float)$row['buy_qty'] - (float)$row['sale_qty'], 0);
            }
        }

        return 0.0;
    }

    /**
     * @return array{code:string,id:string,buy:string,sale:string}
     */
    private function productColumns(): array
    {
        if ($this->productCols !== null) {
            return $this->productCols;
        }

        $result = [
            'code' => '',
            'id' => '',
            'buy' => '',
            'sale' => '',
        ];

        try {
            $columns = $this->schema->listColumns('product');
            $names = array_map(static fn(array $c): string => strtolower((string)($c['column_name'] ?? '')), $columns);

            $result['code'] = $this->pickColumn($names, ['product_code', 'product_id', 'code']);
            $result['id'] = $this->pickColumn($names, ['id']);
            $result['buy'] = $this->pickColumn($names, ['buyitem', 'buy_qty', 'in_qty']);
            $result['sale'] = $this->pickColumn($names, ['saleitem', 'sale_qty', 'out_qty']);
        } catch (\Throwable $_e) {
            $result = [
                'code' => '',
                'id' => '',
                'buy' => '',
                'sale' => '',
            ];
        }

        $this->productCols = $result;
        return $result;
    }

    /**
     * @param string[] $columnNames
     * @param string[] $candidates
     */
    private function pickColumn(array $columnNames, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (in_array(strtolower($candidate), $columnNames, true)) {
                return $candidate;
            }
        }

        return '';
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return date('Y-m-d');
        }

        $dt = date_create($date);
        if ($dt === false) {
            throw new RuntimeException('invalid date');
        }

        return $dt->format('Y-m-d');
    }

    private function normalizeDateTime(string $dateTime): string
    {
        $dateTime = trim($dateTime);
        if ($dateTime === '') {
            return date('Y-m-d H:i:s');
        }

        $dt = date_create($dateTime);
        if ($dt === false) {
            throw new RuntimeException('invalid datetime');
        }

        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * @return array{0:string,1:string}
     */
    private function normalizeDateRange(string $dateFrom, string $dateTo, int $defaultDays = 30): array
    {
        $defaultDays = max($defaultDays, 1);

        if (trim($dateFrom) === '' && trim($dateTo) === '') {
            $to = date('Y-m-d');
            $from = (new DateTimeImmutable($to))->sub(new DateInterval('P' . ($defaultDays - 1) . 'D'))->format('Y-m-d');
            return [$from, $to];
        }

        $from = trim($dateFrom) !== '' ? $this->normalizeDate($dateFrom) : $this->normalizeDate($dateTo);
        $to = trim($dateTo) !== '' ? $this->normalizeDate($dateTo) : $this->normalizeDate($dateFrom);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /** @param mixed $value */
    private function jsonString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
            return $value === '' ? null : $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return null;
        }

        return $encoded;
    }
}
