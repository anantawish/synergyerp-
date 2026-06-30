CREATE TABLE IF NOT EXISTS user_module_access (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_tid INT UNSIGNED NOT NULL,
    module_key VARCHAR(100) NOT NULL,
    can_view TINYINT(1) NOT NULL DEFAULT 1,
    can_add TINYINT(1) NOT NULL DEFAULT 1,
    can_edit TINYINT(1) NOT NULL DEFAULT 1,
    can_delete TINYINT(1) NOT NULL DEFAULT 1,
    can_report TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_module (user_tid, module_key),
    KEY idx_user_tid (user_tid),
    KEY idx_module_key (module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

