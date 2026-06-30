CREATE TABLE IF NOT EXISTS erp_project (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_code VARCHAR(40) NOT NULL,
    project_name VARCHAR(255) NOT NULL,
    customer_name VARCHAR(255) NULL,
    product_code VARCHAR(60) NOT NULL DEFAULT '',
    product_name VARCHAR(255) NULL,
    plan_qty DECIMAL(18,4) NOT NULL DEFAULT 1.0000,
    uom VARCHAR(20) NOT NULL DEFAULT 'PCS',
    start_date DATE NULL,
    due_date DATE NULL,
    status ENUM('PLANNED','RUNNING','COMPLETED','HOLD','CANCELLED') NOT NULL DEFAULT 'PLANNED',
    created_by VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_project_code (project_code),
    KEY idx_project_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS erp_flow_run (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_no VARCHAR(50) NOT NULL,
    project_id INT UNSIGNED NULL,
    project_code VARCHAR(40) NOT NULL DEFAULT '',
    product_code VARCHAR(60) NOT NULL DEFAULT '',
    qty DECIMAL(18,4) NOT NULL DEFAULT 1.0000,
    status ENUM('RUNNING','DONE','FAILED') NOT NULL DEFAULT 'RUNNING',
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    started_by VARCHAR(80) NULL,
    note VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_run_no (run_no),
    KEY idx_flow_run_status (status),
    KEY idx_flow_run_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS erp_flow_step (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id INT UNSIGNED NOT NULL,
    seq_no INT NOT NULL DEFAULT 1,
    stage_code VARCHAR(60) NOT NULL,
    stage_name VARCHAR(150) NOT NULL,
    module_key VARCHAR(80) NULL,
    ref_table VARCHAR(80) NULL,
    ref_id VARCHAR(80) NULL,
    ref_no VARCHAR(80) NULL,
    status ENUM('DONE','FAILED','SKIP') NOT NULL DEFAULT 'DONE',
    note VARCHAR(255) NULL,
    event_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_flow_step_run (run_id),
    KEY idx_flow_step_stage (stage_code),
    CONSTRAINT fk_flow_step_run FOREIGN KEY (run_id) REFERENCES erp_flow_run(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS erp_department (
    department_code VARCHAR(40) NOT NULL,
    department_name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (department_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS erp_user_department (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_tid INT UNSIGNED NOT NULL,
    department_code VARCHAR(40) NOT NULL,
    assigned_by VARCHAR(80) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_department (user_tid),
    KEY idx_department_code (department_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS erp_screen_capture (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    capture_no VARCHAR(50) NOT NULL,
    module_key VARCHAR(80) NULL,
    screen_name VARCHAR(150) NULL,
    process_stage VARCHAR(120) NULL,
    department_code VARCHAR(40) NULL,
    project_code VARCHAR(40) NULL,
    run_no VARCHAR(50) NULL,
    doc_ref VARCHAR(80) NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    image_width INT UNSIGNED NULL,
    image_height INT UNSIGNED NULL,
    note TEXT NULL,
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    captured_by VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_capture_no (capture_no),
    KEY idx_capture_module (module_key),
    KEY idx_capture_stage (process_stage),
    KEY idx_capture_project (project_code),
    KEY idx_capture_run (run_no),
    KEY idx_capture_doc (doc_ref),
    KEY idx_capture_captured_at (captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS erp_warehouse (
    warehouse_code VARCHAR(40) NOT NULL,
    warehouse_name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (warehouse_code),
    KEY idx_warehouse_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS erp_warehouse_location (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    warehouse_code VARCHAR(40) NOT NULL,
    location_code VARCHAR(60) NOT NULL,
    location_name VARCHAR(150) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_warehouse_location (warehouse_code, location_code),
    KEY idx_location_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS erp_warehouse_shelf (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    warehouse_code VARCHAR(40) NOT NULL,
    location_code VARCHAR(60) NOT NULL,
    shelf_code VARCHAR(60) NOT NULL,
    shelf_name VARCHAR(150) NOT NULL,
    sort_no INT NOT NULL DEFAULT 10,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_warehouse_shelf (warehouse_code, location_code, shelf_code),
    KEY idx_shelf_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

CREATE TABLE IF NOT EXISTS erp_stock_transfer (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transfer_no VARCHAR(50) NULL,
    transfer_date DATE NULL,
    project_code VARCHAR(40) NULL,
    from_warehouse VARCHAR(80) NOT NULL,
    from_location_code VARCHAR(60) NOT NULL,
    from_shelf_code VARCHAR(60) NOT NULL,
    to_warehouse VARCHAR(80) NOT NULL,
    to_location_code VARCHAR(60) NOT NULL,
    to_shelf_code VARCHAR(60) NOT NULL,
    item_code VARCHAR(80) NULL,
    item_name VARCHAR(255) NULL,
    lot_no VARCHAR(80) NULL,
    qty DECIMAL(18,4) NOT NULL DEFAULT 0.0000,
    uom VARCHAR(20) NOT NULL DEFAULT 'PCS',
    status ENUM('DRAFT','CONFIRMED','CANCELLED') NOT NULL DEFAULT 'CONFIRMED',
    note VARCHAR(255) NULL,
    created_by VARCHAR(80) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_transfer_no (transfer_no),
    KEY idx_transfer_project (project_code),
    KEY idx_transfer_item (item_code),
    KEY idx_transfer_date (transfer_date),
    KEY idx_transfer_route (from_warehouse, to_warehouse),
    KEY idx_transfer_from_slot (from_warehouse, from_location_code, from_shelf_code),
    KEY idx_transfer_to_slot (to_warehouse, to_location_code, to_shelf_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

INSERT INTO erp_department (department_code, department_name, description, is_active)
VALUES
('ADMIN', 'System Administrator', 'Full control across ERP', 1),
('PURCHASE', 'Purchasing', 'Procurement and supplier cycle', 1),
('HR', 'Human Resource', 'Employee and payroll', 1),
('SALE', 'Sales', 'Sales and customer cycle', 1),
('ACCOUNT', 'Accounting', 'GL, tax and financial reports', 1),
('POS', 'Point of Sale', 'Front store retail operations', 1),
('WAREHOUSE', 'Warehouse', 'Stock in/out and inventory control', 1),
('PRODUCTION', 'Production', 'Manufacturing planning and execution', 1),
('AUDIT', 'Audit / QA', 'Process verification and evidence control', 1)
ON DUPLICATE KEY UPDATE
    department_name = VALUES(department_name),
    description = VALUES(description),
    is_active = VALUES(is_active);

INSERT INTO erp_warehouse (warehouse_code, warehouse_name, description, is_active)
VALUES
('MAIN_WH', 'Main Warehouse', 'Main raw material warehouse', 1),
('PRODUCTION_WH', 'Production Warehouse', 'Production staging warehouse', 1),
('FG_WH', 'Finished Goods Warehouse', 'Finished goods warehouse', 1),
('PACK_WH', 'Packing Warehouse', 'Packing and shipment warehouse', 1)
ON DUPLICATE KEY UPDATE
    warehouse_name = VALUES(warehouse_name),
    description = VALUES(description),
    is_active = VALUES(is_active);

INSERT INTO erp_warehouse_location (warehouse_code, location_code, location_name, description, is_active)
VALUES
('MAIN_WH', 'L01', 'Main Zone A', 'Default location', 1),
('PRODUCTION_WH', 'L01', 'Production Zone A', 'Default location', 1),
('FG_WH', 'L01', 'Finished Goods Zone A', 'Default location', 1),
('PACK_WH', 'L01', 'Packing Zone A', 'Default location', 1)
ON DUPLICATE KEY UPDATE
    location_name = VALUES(location_name),
    description = VALUES(description),
    is_active = VALUES(is_active);

INSERT INTO erp_warehouse_shelf (warehouse_code, location_code, shelf_code, shelf_name, sort_no, description, is_active)
VALUES
('MAIN_WH', 'L01', 'S01', 'Shelf S01', 10, 'Default shelf', 1),
('PRODUCTION_WH', 'L01', 'S01', 'Shelf S01', 10, 'Default shelf', 1),
('FG_WH', 'L01', 'S01', 'Shelf S01', 10, 'Default shelf', 1),
('PACK_WH', 'L01', 'S01', 'Shelf S01', 10, 'Default shelf', 1)
ON DUPLICATE KEY UPDATE
    shelf_name = VALUES(shelf_name),
    sort_no = VALUES(sort_no),
    description = VALUES(description),
    is_active = VALUES(is_active);
