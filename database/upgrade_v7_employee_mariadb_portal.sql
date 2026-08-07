CREATE TABLE IF NOT EXISTS employee_directory (
    nik VARCHAR(80) PRIMARY KEY,
    employee_name VARCHAR(200) NOT NULL,
    department VARCHAR(200) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_employee_name (employee_name),
    INDEX idx_employee_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS employee_source_config (
    config_key VARCHAR(80) PRIMARY KEY,
    config_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
