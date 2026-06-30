-- Manufacturing Phase 2 migration: APS heuristic + shift + setup matrix + dashboard support
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_setup_matrix (
    id INT AUTO_INCREMENT PRIMARY KEY,
    center_code VARCHAR(40) NOT NULL,
    from_item_code VARCHAR(60) NOT NULL DEFAULT '*',
    to_item_code VARCHAR(60) NOT NULL DEFAULT '*',
    setup_hours DECIMAL(10,4) NOT NULL DEFAULT 0,
    changeover_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_setup_matrix (center_code, from_item_code, to_item_code),
    KEY idx_mfg_setup_lookup (center_code, to_item_code, from_item_code, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_work_center_shift (
    id INT AUTO_INCREMENT PRIMARY KEY,
    center_code VARCHAR(40) NOT NULL,
    day_of_week TINYINT NULL,
    shift_no INT NOT NULL DEFAULT 1,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    break_minutes INT NOT NULL DEFAULT 0,
    effective_from DATE NULL,
    effective_to DATE NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_shift (center_code, day_of_week, shift_no, start_time, end_time),
    KEY idx_mfg_shift_lookup (center_code, day_of_week, active, effective_from, effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE mfg_production_order
    ADD COLUMN IF NOT EXISTS lot_size DECIMAL(18,4) NULL AFTER order_qty,
    ADD COLUMN IF NOT EXISTS split_allowed TINYINT(1) NOT NULL DEFAULT 1 AFTER lot_size;

ALTER TABLE mfg_aps_schedule
    ADD COLUMN IF NOT EXISTS batch_no INT NOT NULL DEFAULT 1 AFTER op_no,
    ADD COLUMN IF NOT EXISTS batch_qty DECIMAL(18,4) NULL AFTER batch_no,
    ADD COLUMN IF NOT EXISTS setup_hours_applied DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER planned_hours,
    ADD COLUMN IF NOT EXISTS setup_from_item_code VARCHAR(60) NULL AFTER setup_hours_applied,
    ADD COLUMN IF NOT EXISTS setup_to_item_code VARCHAR(60) NULL AFTER setup_from_item_code,
    ADD COLUMN IF NOT EXISTS tardiness_hours DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER reschedule_reason,
    ADD COLUMN IF NOT EXISTS penalty_cost DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER tardiness_hours,
    ADD COLUMN IF NOT EXISTS heuristic_tag VARCHAR(40) NULL AFTER penalty_cost;

SET @idx1 := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'mfg_aps_schedule'
      AND index_name = 'idx_mfg_aps_penalty'
);
SET @sql1 := IF(@idx1 = 0,
    'ALTER TABLE mfg_aps_schedule ADD INDEX idx_mfg_aps_penalty (penalty_cost)',
    'SELECT 1'
);
PREPARE stmt1 FROM @sql1;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

SET @idx2 := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'mfg_aps_schedule'
      AND index_name = 'idx_mfg_aps_batch'
);
SET @sql2 := IF(@idx2 = 0,
    'ALTER TABLE mfg_aps_schedule ADD INDEX idx_mfg_aps_batch (order_id, batch_no, op_no)',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

INSERT IGNORE INTO mfg_work_center_shift (center_code, day_of_week, shift_no, start_time, end_time, break_minutes, active)
SELECT wc.center_code, d.day_of_week, 1, '08:00:00', '17:00:00', 60, 1
FROM mfg_work_center wc
CROSS JOIN (
    SELECT 1 AS day_of_week
    UNION ALL SELECT 2
    UNION ALL SELECT 3
    UNION ALL SELECT 4
    UNION ALL SELECT 5
    UNION ALL SELECT 6
) d;

UPDATE mfg_work_center_shift
SET break_minutes = 60,
    active = 1
WHERE shift_no = 1
  AND start_time = '08:00:00'
  AND end_time = '17:00:00';

INSERT IGNORE INTO mfg_setup_matrix (center_code, from_item_code, to_item_code, setup_hours, changeover_cost, active)
SELECT center_code, '*', '*', 0.2500, 0.0000, 1
FROM mfg_work_center;

UPDATE mfg_setup_matrix
SET setup_hours = 0.2500,
    changeover_cost = 0.0000,
    active = 1
WHERE from_item_code = '*'
  AND to_item_code = '*';