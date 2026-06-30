-- GL + Thai Tax + HR schema migration for stock2
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS gl_account (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_code VARCHAR(30) NOT NULL,
    account_name VARCHAR(255) NOT NULL,
    account_type ENUM('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE') NOT NULL,
    parent_code VARCHAR(30) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gl_account_code (account_code),
    KEY idx_gl_account_type (account_type),
    KEY idx_gl_account_parent (parent_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gl_journal (
    id INT AUTO_INCREMENT PRIMARY KEY,
    journal_no VARCHAR(40) NOT NULL,
    journal_date DATE NOT NULL,
    description VARCHAR(255) NULL,
    source_module VARCHAR(80) NULL,
    source_ref VARCHAR(80) NULL,
    created_by VARCHAR(80) NULL,
    status ENUM('DRAFT','POSTED','VOID') NOT NULL DEFAULT 'POSTED',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gl_journal_no (journal_no),
    KEY idx_gl_journal_date (journal_date),
    KEY idx_gl_journal_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gl_journal_line (
    id INT AUTO_INCREMENT PRIMARY KEY,
    journal_id INT NOT NULL,
    line_no INT NOT NULL DEFAULT 1,
    account_code VARCHAR(30) NOT NULL,
    description VARCHAR(255) NULL,
    debit DECIMAL(18,2) NOT NULL DEFAULT 0,
    credit DECIMAL(18,2) NOT NULL DEFAULT 0,
    tax_type VARCHAR(40) NULL,
    vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    withholding_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    department_code VARCHAR(40) NULL,
    project_code VARCHAR(40) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gl_journal_line_journal (journal_id),
    KEY idx_gl_journal_line_account (account_code),
    KEY idx_gl_journal_line_tax (tax_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gl_vat_transaction (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_no VARCHAR(40) NOT NULL,
    doc_date DATE NOT NULL,
    trans_type ENUM('SALE','PURCHASE') NOT NULL,
    partner_name VARCHAR(255) NOT NULL,
    tax_id VARCHAR(20) NULL,
    branch_code VARCHAR(10) NULL,
    base_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    vat_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gl_vat_date (doc_date),
    KEY idx_gl_vat_type (trans_type),
    KEY idx_gl_vat_taxid (tax_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gl_withholding_transaction (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cert_no VARCHAR(40) NOT NULL,
    cert_date DATE NOT NULL,
    payee_name VARCHAR(255) NOT NULL,
    payee_tax_id VARCHAR(20) NULL,
    income_type VARCHAR(80) NULL,
    gross_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    withholding_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    withholding_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    pnd_form ENUM('PND3','PND53','OTHER') NOT NULL DEFAULT 'PND53',
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_gl_wht_date (cert_date),
    KEY idx_gl_wht_form (pnd_form),
    KEY idx_gl_wht_taxid (payee_tax_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_employee (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(30) NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    department VARCHAR(100) NULL,
    position_name VARCHAR(100) NULL,
    start_date DATE NOT NULL,
    base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    hourly_rate DECIMAL(10,2) NULL,
    work_days_per_month INT NOT NULL DEFAULT 26,
    work_hours_per_day DECIMAL(5,2) NOT NULL DEFAULT 8.00,
    social_security_enabled TINYINT(1) NOT NULL DEFAULT 1,
    tax_allowance_per_year DECIMAL(12,2) NOT NULL DEFAULT 60000,
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_employee_code (employee_code),
    KEY idx_hr_employee_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(30) NOT NULL,
    work_date DATE NOT NULL,
    check_in TIME NULL,
    check_out TIME NULL,
    status ENUM('PRESENT','ABSENT','LATE','LEAVE','HOLIDAY','OFF') NOT NULL DEFAULT 'PRESENT',
    late_minutes INT NOT NULL DEFAULT 0,
    ot_hours DECIMAL(6,2) NOT NULL DEFAULT 0,
    ot_type ENUM('WORKDAY','HOLIDAY','HOLIDAY_OT') NOT NULL DEFAULT 'WORKDAY',
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_attendance_emp_date (employee_code, work_date),
    KEY idx_hr_attendance_date (work_date),
    KEY idx_hr_attendance_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_leave_type (
    id INT AUTO_INCREMENT PRIMARY KEY,
    leave_code VARCHAR(30) NOT NULL,
    leave_name VARCHAR(100) NOT NULL,
    paid_ratio DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    annual_quota DECIMAL(6,2) NOT NULL DEFAULT 0,
    affects_bonus TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_leave_type_code (leave_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_leave_request (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(30) NOT NULL,
    leave_code VARCHAR(30) NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    total_days DECIMAL(6,2) NOT NULL DEFAULT 0,
    approval_status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
    paid_ratio DECIMAL(5,2) NULL,
    reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_hr_leave_emp (employee_code),
    KEY idx_hr_leave_code (leave_code),
    KEY idx_hr_leave_status (approval_status),
    KEY idx_hr_leave_date (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_payroll_period (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_code VARCHAR(20) NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    pay_date DATE NOT NULL,
    status ENUM('OPEN','CALCULATED','CLOSED') NOT NULL DEFAULT 'OPEN',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_payroll_period_code (period_code),
    KEY idx_hr_payroll_period_dates (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_payroll_line (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payroll_period_id INT NOT NULL,
    employee_code VARCHAR(30) NOT NULL,
    work_days DECIMAL(6,2) NOT NULL DEFAULT 0,
    absent_days DECIMAL(6,2) NOT NULL DEFAULT 0,
    leave_paid_days DECIMAL(6,2) NOT NULL DEFAULT 0,
    leave_unpaid_days DECIMAL(6,2) NOT NULL DEFAULT 0,
    late_minutes INT NOT NULL DEFAULT 0,
    ot_hours_workday DECIMAL(6,2) NOT NULL DEFAULT 0,
    ot_hours_holiday DECIMAL(6,2) NOT NULL DEFAULT 0,
    ot_hours_holiday_ot DECIMAL(6,2) NOT NULL DEFAULT 0,
    gross_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
    ot_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    late_deduct DECIMAL(12,2) NOT NULL DEFAULT 0,
    absent_deduct DECIMAL(12,2) NOT NULL DEFAULT 0,
    leave_deduct DECIMAL(12,2) NOT NULL DEFAULT 0,
    social_security DECIMAL(12,2) NOT NULL DEFAULT 0,
    taxable_income DECIMAL(12,2) NOT NULL DEFAULT 0,
    withholding_tax DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_pay DECIMAL(12,2) NOT NULL DEFAULT 0,
    calculation_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hr_payroll_line_period_emp (payroll_period_id, employee_code),
    KEY idx_hr_payroll_line_period (payroll_period_id),
    KEY idx_hr_payroll_line_emp (employee_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_policy (
    policy_key VARCHAR(100) PRIMARY KEY,
    policy_value VARCHAR(255) NOT NULL,
    description VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO gl_account (account_code, account_name, account_type, parent_code, is_active)
VALUES
    ('1000', 'Cash on Hand', 'ASSET', NULL, 1),
    ('1100', 'Accounts Receivable', 'ASSET', NULL, 1),
    ('1200', 'Inventory', 'ASSET', NULL, 1),
    ('1190', 'Input VAT', 'ASSET', NULL, 1),
    ('2000', 'Accounts Payable', 'LIABILITY', NULL, 1),
    ('2100', 'Output VAT', 'LIABILITY', NULL, 1),
    ('3000', 'Owner Equity', 'EQUITY', NULL, 1),
    ('4000', 'Sales Revenue', 'REVENUE', NULL, 1),
    ('5000', 'Cost of Goods Sold', 'EXPENSE', NULL, 1),
    ('6100', 'Salary Expense', 'EXPENSE', NULL, 1),
    ('6200', 'Overtime Expense', 'EXPENSE', NULL, 1),
    ('2300', 'Withholding Tax Payable', 'LIABILITY', NULL, 1)
ON DUPLICATE KEY UPDATE
    account_name = VALUES(account_name),
    account_type = VALUES(account_type),
    is_active = VALUES(is_active);

INSERT INTO hr_leave_type (leave_code, leave_name, paid_ratio, annual_quota, affects_bonus)
VALUES
    ('SICK', 'Sick Leave', 1.00, 30, 0),
    ('VACATION', 'Annual Leave', 1.00, 10, 0),
    ('PERSONAL', 'Personal Leave', 1.00, 3, 0),
    ('MATERNITY', 'Maternity Leave', 1.00, 98, 0),
    ('UNPAID', 'Unpaid Leave', 0.00, 365, 1)
ON DUPLICATE KEY UPDATE
    leave_name = VALUES(leave_name),
    paid_ratio = VALUES(paid_ratio),
    annual_quota = VALUES(annual_quota),
    affects_bonus = VALUES(affects_bonus);

INSERT INTO hr_policy (policy_key, policy_value, description)
VALUES
    ('ot_multiplier_workday', '1.5', 'OT multiplier for normal working day'),
    ('ot_multiplier_holiday', '2.0', 'Multiplier for working on holiday within normal hours'),
    ('ot_multiplier_holiday_ot', '3.0', 'Multiplier for overtime on holiday'),
    ('social_security_employee_rate', '5.0', 'Employee social security contribution rate (%)'),
    ('social_security_employee_cap', '750', 'Employee social security monthly cap (THB)'),
    ('lateness_deduct_per_minute', '0', '0 means use hourly_rate / 60'),
    ('absent_deduct_day_multiplier', '1.0', 'Deduct multiplier per absent day based on daily wage')
ON DUPLICATE KEY UPDATE
    policy_value = VALUES(policy_value),
    description = VALUES(description);
