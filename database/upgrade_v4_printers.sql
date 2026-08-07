CREATE TABLE IF NOT EXISTS printers (
    prn_id VARCHAR(60) PRIMARY KEY,
    security_code VARCHAR(12) NOT NULL,
    printer_name VARCHAR(160) NOT NULL,
    location VARCHAR(160) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    location_radius_m INT NOT NULL DEFAULT 5,
    serial_number VARCHAR(160) NULL,
    ip_printer VARCHAR(80) NULL,
    model_printer VARCHAR(160) NULL,
    physical_condition TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE maintenance_schedules
    ADD COLUMN asset_type ENUM('pc','printer') NOT NULL DEFAULT 'pc' AFTER id,
    ADD COLUMN printer_id VARCHAR(60) NULL AFTER pc_id;

SET @schedule_pc_fk := (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'maintenance_schedules'
      AND COLUMN_NAME = 'pc_id'
      AND REFERENCED_TABLE_NAME = 'pcs'
    LIMIT 1
);
SET @drop_schedule_pc_fk_sql := IF(
    @schedule_pc_fk IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE maintenance_schedules DROP FOREIGN KEY `', REPLACE(@schedule_pc_fk, '`', '``'), '`')
);
PREPARE drop_schedule_pc_fk_stmt FROM @drop_schedule_pc_fk_sql;
EXECUTE drop_schedule_pc_fk_stmt;
DEALLOCATE PREPARE drop_schedule_pc_fk_stmt;

ALTER TABLE maintenance_schedules
    MODIFY pc_id VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL;

ALTER TABLE maintenance_schedules
    ADD CONSTRAINT fk_schedule_pc FOREIGN KEY (pc_id) REFERENCES pcs(pc_id) ON DELETE CASCADE;

ALTER TABLE printers
    CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE maintenance_schedules
    MODIFY printer_id VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL;

CREATE INDEX idx_schedule_printer ON maintenance_schedules (printer_id);
