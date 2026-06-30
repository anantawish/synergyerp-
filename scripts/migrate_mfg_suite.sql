-- Manufacturing Suite migration for stock2
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_item (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(60) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_type ENUM('FG','SFG','RM','PK','SP') NOT NULL DEFAULT 'RM',
    base_uom VARCHAR(20) NOT NULL DEFAULT 'PCS',
    product_ref_id VARCHAR(60) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_item_code (item_code),
    KEY idx_mfg_item_type (item_type),
    KEY idx_mfg_item_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_bom_header (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(60) NOT NULL,
    version_no VARCHAR(20) NOT NULL,
    blueprint_no VARCHAR(60) NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    status ENUM('DRAFT','APPROVED','OBSOLETE') NOT NULL DEFAULT 'DRAFT',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    note VARCHAR(255) NULL,
    created_by VARCHAR(60) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_bom_ver (item_code, version_no),
    KEY idx_mfg_bom_active (item_code, status, effective_from, effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_bom_line (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bom_id INT NOT NULL,
    parent_item_code VARCHAR(60) NOT NULL,
    component_item_code VARCHAR(60) NOT NULL,
    qty_per DECIMAL(18,6) NOT NULL,
    scrap_pct DECIMAL(6,3) NOT NULL DEFAULT 0,
    uom VARCHAR(20) NOT NULL DEFAULT 'PCS',
    is_optional TINYINT(1) NOT NULL DEFAULT 0,
    sort_no INT NOT NULL DEFAULT 10,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mfg_bom_line_bom (bom_id),
    KEY idx_mfg_bom_line_parent (parent_item_code),
    KEY idx_mfg_bom_line_component (component_item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_work_center (
    id INT AUTO_INCREMENT PRIMARY KEY,
    center_code VARCHAR(40) NOT NULL,
    center_name VARCHAR(120) NOT NULL,
    machine_code VARCHAR(60) NULL,
    capacity_hours_per_day DECIMAL(8,2) NOT NULL DEFAULT 8.00,
    status ENUM('AVAILABLE','DOWN','MAINTENANCE','RESERVED') NOT NULL DEFAULT 'AVAILABLE',
    priority_rank INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_center_code (center_code),
    KEY idx_mfg_center_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_work_center_alt (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alt_group VARCHAR(40) NOT NULL,
    primary_center_code VARCHAR(40) NOT NULL,
    alternative_center_code VARCHAR(40) NOT NULL,
    priority_rank INT NOT NULL DEFAULT 10,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_center_alt (alt_group, primary_center_code, alternative_center_code),
    KEY idx_mfg_center_alt_group (alt_group, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_routing_header (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(60) NOT NULL,
    version_no VARCHAR(20) NOT NULL,
    effective_from DATE NOT NULL,
    effective_to DATE NULL,
    status ENUM('DRAFT','APPROVED','OBSOLETE') NOT NULL DEFAULT 'DRAFT',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_routing_ver (item_code, version_no),
    KEY idx_mfg_routing_active (item_code, status, effective_from, effective_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_routing_step (
    id INT AUTO_INCREMENT PRIMARY KEY,
    routing_id INT NOT NULL,
    op_no INT NOT NULL,
    operation_name VARCHAR(150) NOT NULL,
    primary_center_code VARCHAR(40) NOT NULL,
    alt_group VARCHAR(40) NULL,
    setup_hours DECIMAL(10,4) NOT NULL DEFAULT 0,
    run_hours_per_unit DECIMAL(12,6) NOT NULL DEFAULT 0,
    queue_hours DECIMAL(10,4) NOT NULL DEFAULT 0,
    move_hours DECIMAL(10,4) NOT NULL DEFAULT 0,
    inspection_required TINYINT(1) NOT NULL DEFAULT 0,
    instruction_text TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_routing_step (routing_id, op_no),
    KEY idx_mfg_routing_center (primary_center_code),
    KEY idx_mfg_routing_alt_group (alt_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_production_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_no VARCHAR(60) NOT NULL,
    item_code VARCHAR(60) NOT NULL,
    order_qty DECIMAL(18,4) NOT NULL,
    uom VARCHAR(20) NOT NULL DEFAULT 'PCS',
    release_date DATE NOT NULL,
    due_date DATE NOT NULL,
    priority INT NOT NULL DEFAULT 50,
    status ENUM('PLANNED','RELEASED','IN_PROGRESS','COMPLETED','HOLD','CANCELLED') NOT NULL DEFAULT 'PLANNED',
    notes VARCHAR(255) NULL,
    created_by VARCHAR(60) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_order_no (order_no),
    KEY idx_mfg_order_status_due (status, due_date),
    KEY idx_mfg_order_item (item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_aps_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    op_no INT NOT NULL,
    operation_name VARCHAR(150) NOT NULL,
    work_center_code VARCHAR(40) NOT NULL,
    planned_start DATETIME NOT NULL,
    planned_end DATETIME NOT NULL,
    planned_hours DECIMAL(12,4) NOT NULL DEFAULT 0,
    sequence_no INT NOT NULL DEFAULT 1,
    status ENUM('PLANNED','RUNNING','DONE','HOLD') NOT NULL DEFAULT 'PLANNED',
    reschedule_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mfg_aps_order (order_id),
    KEY idx_mfg_aps_center_time (work_center_code, planned_start, planned_end),
    KEY idx_mfg_aps_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_work_center_calendar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    center_code VARCHAR(40) NOT NULL,
    work_date DATE NOT NULL,
    available_hours DECIMAL(8,2) NOT NULL DEFAULT 8.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_center_calendar (center_code, work_date),
    KEY idx_mfg_center_calendar_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS iot_device (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_code VARCHAR(60) NOT NULL,
    center_code VARCHAR(40) NULL,
    protocol VARCHAR(20) NOT NULL DEFAULT 'MQTT',
    status ENUM('ACTIVE','INACTIVE','MAINTENANCE') NOT NULL DEFAULT 'ACTIVE',
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_iot_device_code (device_code),
    KEY idx_iot_device_center (center_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS iot_sensor_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    device_code VARCHAR(60) NOT NULL,
    center_code VARCHAR(40) NULL,
    log_time DATETIME NOT NULL,
    metric_name VARCHAR(60) NOT NULL,
    metric_value DECIMAL(18,6) NOT NULL,
    metric_unit VARCHAR(20) NULL,
    quality_flag ENUM('GOOD','BAD','UNCERTAIN') NOT NULL DEFAULT 'GOOD',
    payload_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_iot_log_device_time (device_code, log_time),
    KEY idx_iot_log_metric_time (metric_name, log_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_digital_job_sheet (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    op_no INT NOT NULL,
    instruction_text TEXT NULL,
    checklist_json LONGTEXT NULL,
    result_json LONGTEXT NULL,
    operator_code VARCHAR(60) NULL,
    status ENUM('OPEN','IN_PROGRESS','DONE','HOLD') NOT NULL DEFAULT 'OPEN',
    start_at DATETIME NULL,
    end_at DATETIME NULL,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_job_sheet (order_id, op_no),
    KEY idx_job_sheet_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_lot (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lot_no VARCHAR(80) NOT NULL,
    item_code VARCHAR(60) NOT NULL,
    order_id INT NULL,
    warehouse_code VARCHAR(40) NOT NULL,
    location_code VARCHAR(60) NOT NULL,
    shelf_code VARCHAR(60) NOT NULL,
    qty DECIMAL(18,4) NOT NULL,
    uom VARCHAR(20) NOT NULL DEFAULT 'PCS',
    produced_at DATETIME NOT NULL,
    operator_code VARCHAR(60) NULL,
    status ENUM('OPEN','CLOSED','HOLD','SCRAP') NOT NULL DEFAULT 'OPEN',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_lot_no (lot_no),
    KEY idx_mfg_lot_item (item_code),
    KEY idx_mfg_lot_order (order_id),
    KEY idx_mfg_lot_slot (warehouse_code, location_code, shelf_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_lot_consumption (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produced_lot_no VARCHAR(80) NOT NULL,
    component_lot_no VARCHAR(80) NOT NULL,
    component_item_code VARCHAR(60) NOT NULL,
    qty DECIMAL(18,4) NOT NULL,
    uom VARCHAR(20) NOT NULL DEFAULT 'PCS',
    recorded_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lot_consume_produced (produced_lot_no),
    KEY idx_lot_consume_component (component_lot_no),
    KEY idx_lot_consume_item (component_item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_lot_dispatch (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lot_no VARCHAR(80) NOT NULL,
    dispatch_doc_no VARCHAR(80) NOT NULL,
    customer_code VARCHAR(60) NULL,
    warehouse_code VARCHAR(40) NOT NULL,
    location_code VARCHAR(60) NOT NULL,
    shelf_code VARCHAR(60) NOT NULL,
    dispatch_qty DECIMAL(18,4) NOT NULL,
    dispatch_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lot_dispatch_lot (lot_no),
    KEY idx_lot_dispatch_doc (dispatch_doc_no),
    KEY idx_mfg_dispatch_slot (warehouse_code, location_code, shelf_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qms_inspection_plan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(60) NOT NULL,
    op_no INT NOT NULL,
    characteristic VARCHAR(120) NOT NULL,
    target_value DECIMAL(18,6) NULL,
    lsl DECIMAL(18,6) NULL,
    usl DECIMAL(18,6) NULL,
    sample_size INT NOT NULL DEFAULT 1,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_qms_plan (item_code, op_no, characteristic),
    KEY idx_qms_plan_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qms_inspection_result (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NULL,
    lot_no VARCHAR(80) NOT NULL,
    item_code VARCHAR(60) NOT NULL,
    op_no INT NOT NULL,
    characteristic VARCHAR(120) NOT NULL,
    measured_value DECIMAL(18,6) NOT NULL,
    decision ENUM('PASS','FAIL','REWORK') NOT NULL DEFAULT 'PASS',
    inspector_code VARCHAR(60) NULL,
    inspected_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_qms_result_lot (lot_no),
    KEY idx_qms_result_item_char_time (item_code, characteristic, inspected_at),
    KEY idx_qms_result_decision (decision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_machine (
    id INT AUTO_INCREMENT PRIMARY KEY,
    machine_code VARCHAR(60) NOT NULL,
    machine_name VARCHAR(150) NOT NULL,
    center_code VARCHAR(40) NULL,
    model_no VARCHAR(60) NULL,
    install_date DATE NULL,
    status ENUM('ACTIVE','DOWN','MAINTENANCE','RETIRED') NOT NULL DEFAULT 'ACTIVE',
    runtime_hours_total DECIMAL(14,3) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_asset_machine_code (machine_code),
    KEY idx_asset_machine_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS machine_runtime_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    machine_code VARCHAR(60) NOT NULL,
    log_time DATETIME NOT NULL,
    runtime_hours_increment DECIMAL(10,4) NOT NULL DEFAULT 0,
    vibration DECIMAL(10,4) NULL,
    temp_c DECIMAL(10,4) NULL,
    current_amp DECIMAL(10,4) NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'MANUAL',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_runtime_machine_time (machine_code, log_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maintenance_plan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    machine_code VARCHAR(60) NOT NULL,
    plan_type ENUM('PREVENTIVE','PREDICTIVE') NOT NULL DEFAULT 'PREVENTIVE',
    interval_hours DECIMAL(12,3) NOT NULL DEFAULT 0,
    interval_days INT NOT NULL DEFAULT 0,
    last_maintenance_at DATETIME NULL,
    last_maintenance_runtime DECIMAL(14,3) NOT NULL DEFAULT 0,
    next_due_at DATETIME NULL,
    threshold_vibration DECIMAL(10,4) NULL,
    threshold_temp DECIMAL(10,4) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_maint_plan_machine (machine_code),
    KEY idx_maint_plan_due (next_due_at, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maintenance_work_order (
    id INT AUTO_INCREMENT PRIMARY KEY,
    wo_no VARCHAR(80) NOT NULL,
    machine_code VARCHAR(60) NOT NULL,
    issue_type ENUM('PREVENTIVE','PREDICTIVE','BREAKDOWN') NOT NULL,
    opened_at DATETIME NOT NULL,
    due_date DATETIME NULL,
    priority ENUM('LOW','MEDIUM','HIGH','CRITICAL') NOT NULL DEFAULT 'MEDIUM',
    status ENUM('OPEN','IN_PROGRESS','DONE','CANCELLED') NOT NULL DEFAULT 'OPEN',
    predicted_risk DECIMAL(10,4) NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_maint_wo_no (wo_no),
    KEY idx_maint_wo_machine_status (machine_code, status),
    KEY idx_maint_wo_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS supply_item_policy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(60) NOT NULL,
    supplier_code VARCHAR(60) NULL,
    lead_time_days INT NOT NULL DEFAULT 7,
    safety_stock DECIMAL(18,4) NOT NULL DEFAULT 0,
    reorder_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    min_order_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    max_order_qty DECIMAL(18,4) NULL,
    jit_enabled TINYINT(1) NOT NULL DEFAULT 0,
    reorder_method ENUM('ROP','JIT','MRP') NOT NULL DEFAULT 'ROP',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supply_policy_item (item_code),
    KEY idx_supply_policy_method (reorder_method)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS demand_forecast (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(60) NOT NULL,
    forecast_date DATE NOT NULL,
    forecast_qty DECIMAL(18,4) NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'MANUAL',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_demand_forecast (item_code, forecast_date, source),
    KEY idx_demand_forecast_date (forecast_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_requisition (
    id INT AUTO_INCREMENT PRIMARY KEY,
    req_no VARCHAR(80) NOT NULL,
    item_code VARCHAR(60) NOT NULL,
    qty DECIMAL(18,4) NOT NULL,
    uom VARCHAR(20) NOT NULL DEFAULT 'PCS',
    req_date DATE NOT NULL,
    need_date DATE NULL,
    status ENUM('OPEN','APPROVED','ORDERED','CLOSED','CANCELLED') NOT NULL DEFAULT 'OPEN',
    reason VARCHAR(255) NULL,
    source_order_no VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_purchase_req_no (req_no),
    KEY idx_purchase_req_item_status (item_code, status),
    KEY idx_purchase_req_need_date (need_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_material_reservation (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    item_code VARCHAR(60) NOT NULL,
    qty_reserved DECIMAL(18,4) NOT NULL DEFAULT 0,
    qty_issued DECIMAL(18,4) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mfg_reservation (order_id, item_code),
    KEY idx_mfg_reservation_item (item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mfg_inventory_snapshot (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_code VARCHAR(60) NOT NULL,
    on_hand_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    reserved_qty DECIMAL(18,4) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'MANUAL',
    UNIQUE KEY uq_mfg_inventory_snapshot (item_code),
    KEY idx_mfg_inventory_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO mfg_work_center (center_code, center_name, machine_code, capacity_hours_per_day, status, priority_rank)
VALUES
    ('WC-CUT', 'Cutting Center', 'MC-CUT-01', 8.00, 'AVAILABLE', 10),
    ('WC-ASSY', 'Assembly Center', 'MC-ASSY-01', 8.00, 'AVAILABLE', 20),
    ('WC-PACK', 'Packing Center', 'MC-PACK-01', 8.00, 'AVAILABLE', 30)
ON DUPLICATE KEY UPDATE
    center_name = VALUES(center_name),
    machine_code = VALUES(machine_code),
    capacity_hours_per_day = VALUES(capacity_hours_per_day),
    status = VALUES(status),
    priority_rank = VALUES(priority_rank);

INSERT INTO mfg_work_center_alt (alt_group, primary_center_code, alternative_center_code, priority_rank, active)
VALUES
    ('CUTTING', 'WC-CUT', 'WC-ASSY', 10, 1),
    ('ASSY', 'WC-ASSY', 'WC-CUT', 10, 1),
    ('PACK', 'WC-PACK', 'WC-ASSY', 10, 1)
ON DUPLICATE KEY UPDATE
    priority_rank = VALUES(priority_rank),
    active = VALUES(active);

INSERT INTO asset_machine (machine_code, machine_name, center_code, model_no, status, runtime_hours_total)
VALUES
    ('MC-CUT-01', 'Cutting Machine 01', 'WC-CUT', 'CUT-A1', 'ACTIVE', 0),
    ('MC-ASSY-01', 'Assembly Line 01', 'WC-ASSY', 'ASSY-B1', 'ACTIVE', 0),
    ('MC-PACK-01', 'Packing Machine 01', 'WC-PACK', 'PACK-C1', 'ACTIVE', 0)
ON DUPLICATE KEY UPDATE
    machine_name = VALUES(machine_name),
    center_code = VALUES(center_code),
    model_no = VALUES(model_no),
    status = VALUES(status);

INSERT INTO maintenance_plan (
    machine_code,
    plan_type,
    interval_hours,
    interval_days,
    last_maintenance_runtime,
    threshold_vibration,
    threshold_temp,
    active,
    note
)
VALUES
    ('MC-CUT-01', 'PREVENTIVE', 120, 30, 0, 6.5, 80, 1, 'Default PM plan'),
    ('MC-ASSY-01', 'PREVENTIVE', 140, 30, 0, 6.0, 78, 1, 'Default PM plan'),
    ('MC-PACK-01', 'PREDICTIVE', 0, 0, 0, 5.0, 70, 1, 'Predictive monitoring')
ON DUPLICATE KEY UPDATE
    interval_hours = VALUES(interval_hours),
    interval_days = VALUES(interval_days),
    threshold_vibration = VALUES(threshold_vibration),
    threshold_temp = VALUES(threshold_temp),
    active = VALUES(active),
    note = VALUES(note);
