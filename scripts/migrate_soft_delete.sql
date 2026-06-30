CREATE TABLE IF NOT EXISTS app_soft_delete (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_name VARCHAR(120) NOT NULL,
    pk_column VARCHAR(120) NOT NULL,
    pk_value VARCHAR(255) NOT NULL,
    row_data LONGTEXT NULL,
    deleted_by VARCHAR(120) NULL,
    delete_reason VARCHAR(255) NULL,
    deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_table_pk (table_name, pk_value),
    KEY idx_table_deleted_at (table_name, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

