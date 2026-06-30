-- Inventory location hierarchy migration (warehouse -> location -> shelf)
-- Rules: if object exists then keep data; if structure is not aligned then alter to target definition.

SET NAMES utf8mb4;

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_inventory_location_normalize$$
CREATE PROCEDURE sp_inventory_location_normalize()
BEGIN
    CREATE TABLE IF NOT EXISTS erp_warehouse (
        warehouse_code VARCHAR(40) NOT NULL,
        warehouse_name VARCHAR(150) NOT NULL,
        description VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (warehouse_code),
        KEY idx_warehouse_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

    IF EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'erp_stock_transfer'
    ) THEN
        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'erp_stock_transfer'
              AND column_name = 'from_location_code'
        ) THEN
            ALTER TABLE erp_stock_transfer ADD COLUMN from_location_code VARCHAR(60) NULL;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'erp_stock_transfer'
              AND column_name = 'from_shelf_code'
        ) THEN
            ALTER TABLE erp_stock_transfer ADD COLUMN from_shelf_code VARCHAR(60) NULL;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'erp_stock_transfer'
              AND column_name = 'to_location_code'
        ) THEN
            ALTER TABLE erp_stock_transfer ADD COLUMN to_location_code VARCHAR(60) NULL;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'erp_stock_transfer'
              AND column_name = 'to_shelf_code'
        ) THEN
            ALTER TABLE erp_stock_transfer ADD COLUMN to_shelf_code VARCHAR(60) NULL;
        END IF;

        UPDATE erp_stock_transfer
        SET
            from_warehouse = COALESCE(NULLIF(TRIM(from_warehouse), ''), 'MAIN_WH'),
            from_location_code = COALESCE(NULLIF(TRIM(from_location_code), ''), 'L01'),
            from_shelf_code = COALESCE(NULLIF(TRIM(from_shelf_code), ''), 'S01'),
            to_warehouse = COALESCE(NULLIF(TRIM(to_warehouse), ''), 'PRODUCTION_WH'),
            to_location_code = COALESCE(NULLIF(TRIM(to_location_code), ''), 'L01'),
            to_shelf_code = COALESCE(NULLIF(TRIM(to_shelf_code), ''), 'S01');

        ALTER TABLE erp_stock_transfer
            MODIFY COLUMN from_warehouse VARCHAR(80) NOT NULL,
            MODIFY COLUMN from_location_code VARCHAR(60) NOT NULL,
            MODIFY COLUMN from_shelf_code VARCHAR(60) NOT NULL,
            MODIFY COLUMN to_warehouse VARCHAR(80) NOT NULL,
            MODIFY COLUMN to_location_code VARCHAR(60) NOT NULL,
            MODIFY COLUMN to_shelf_code VARCHAR(60) NOT NULL;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'erp_stock_transfer'
              AND index_name = 'idx_transfer_from_slot'
        ) THEN
            ALTER TABLE erp_stock_transfer
                ADD KEY idx_transfer_from_slot (from_warehouse, from_location_code, from_shelf_code);
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'erp_stock_transfer'
              AND index_name = 'idx_transfer_to_slot'
        ) THEN
            ALTER TABLE erp_stock_transfer
                ADD KEY idx_transfer_to_slot (to_warehouse, to_location_code, to_shelf_code);
        END IF;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'stockcard'
    ) THEN
        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'stockcard'
              AND column_name = 'warehouse_code'
        ) THEN
            ALTER TABLE stockcard ADD COLUMN warehouse_code VARCHAR(40) NULL;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'stockcard'
              AND column_name = 'location_code'
        ) THEN
            ALTER TABLE stockcard ADD COLUMN location_code VARCHAR(60) NULL;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'stockcard'
              AND column_name = 'shelf_code'
        ) THEN
            ALTER TABLE stockcard ADD COLUMN shelf_code VARCHAR(60) NULL;
        END IF;

        UPDATE stockcard
        SET
            warehouse_code = COALESCE(NULLIF(TRIM(warehouse_code), ''), 'MAIN_WH'),
            location_code = COALESCE(NULLIF(TRIM(location_code), ''), 'L01'),
            shelf_code = COALESCE(NULLIF(TRIM(shelf_code), ''), 'S01');

        ALTER TABLE stockcard
            MODIFY COLUMN warehouse_code VARCHAR(40) NOT NULL,
            MODIFY COLUMN location_code VARCHAR(60) NOT NULL,
            MODIFY COLUMN shelf_code VARCHAR(60) NOT NULL;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'stockcard'
              AND index_name = 'idx_stockcard_slot'
        ) THEN
            ALTER TABLE stockcard
                ADD KEY idx_stockcard_slot (warehouse_code, location_code, shelf_code);
        END IF;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'mfg_lot'
    ) THEN
        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'mfg_lot'
              AND column_name = 'warehouse_code'
        ) THEN
            ALTER TABLE mfg_lot ADD COLUMN warehouse_code VARCHAR(40) NULL;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'mfg_lot'
              AND column_name = 'location_code'
        ) THEN
            ALTER TABLE mfg_lot ADD COLUMN location_code VARCHAR(60) NULL;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'mfg_lot'
              AND column_name = 'shelf_code'
        ) THEN
            ALTER TABLE mfg_lot ADD COLUMN shelf_code VARCHAR(60) NULL;
        END IF;

        UPDATE mfg_lot
        SET
            warehouse_code = COALESCE(NULLIF(TRIM(warehouse_code), ''), 'FG_WH'),
            location_code = COALESCE(NULLIF(TRIM(location_code), ''), 'L01'),
            shelf_code = COALESCE(NULLIF(TRIM(shelf_code), ''), 'S01');

        ALTER TABLE mfg_lot
            MODIFY COLUMN warehouse_code VARCHAR(40) NOT NULL,
            MODIFY COLUMN location_code VARCHAR(60) NOT NULL,
            MODIFY COLUMN shelf_code VARCHAR(60) NOT NULL;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'mfg_lot'
              AND index_name = 'idx_mfg_lot_slot'
        ) THEN
            ALTER TABLE mfg_lot
                ADD KEY idx_mfg_lot_slot (warehouse_code, location_code, shelf_code);
        END IF;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = 'mfg_lot_dispatch'
    ) THEN
        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'mfg_lot_dispatch'
              AND column_name = 'warehouse_code'
        ) THEN
            ALTER TABLE mfg_lot_dispatch ADD COLUMN warehouse_code VARCHAR(40) NULL;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'mfg_lot_dispatch'
              AND column_name = 'location_code'
        ) THEN
            ALTER TABLE mfg_lot_dispatch ADD COLUMN location_code VARCHAR(60) NULL;
        END IF;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'mfg_lot_dispatch'
              AND column_name = 'shelf_code'
        ) THEN
            ALTER TABLE mfg_lot_dispatch ADD COLUMN shelf_code VARCHAR(60) NULL;
        END IF;

        UPDATE mfg_lot_dispatch
        SET
            warehouse_code = COALESCE(NULLIF(TRIM(warehouse_code), ''), 'PACK_WH'),
            location_code = COALESCE(NULLIF(TRIM(location_code), ''), 'L01'),
            shelf_code = COALESCE(NULLIF(TRIM(shelf_code), ''), 'S01');

        ALTER TABLE mfg_lot_dispatch
            MODIFY COLUMN warehouse_code VARCHAR(40) NOT NULL,
            MODIFY COLUMN location_code VARCHAR(60) NOT NULL,
            MODIFY COLUMN shelf_code VARCHAR(60) NOT NULL;

        IF NOT EXISTS (
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'mfg_lot_dispatch'
              AND index_name = 'idx_mfg_dispatch_slot'
        ) THEN
            ALTER TABLE mfg_lot_dispatch
                ADD KEY idx_mfg_dispatch_slot (warehouse_code, location_code, shelf_code);
        END IF;
    END IF;
END$$

CALL sp_inventory_location_normalize()$$
DROP PROCEDURE IF EXISTS sp_inventory_location_normalize$$

DELIMITER ;
