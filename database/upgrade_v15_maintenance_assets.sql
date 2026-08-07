CREATE TABLE IF NOT EXISTS maintenance_assets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    maintenance_asset_code VARCHAR(80) NOT NULL UNIQUE,
    security_code VARCHAR(12) NOT NULL,
    maintenance_type VARCHAR(60) NOT NULL DEFAULT 'pc_set',
    name VARCHAR(180) NOT NULL,
    pc_id VARCHAR(60) NULL,
    printer_id VARCHAR(60) NULL,
    company_id INT NULL,
    employee_nik VARCHAR(80) NULL,
    owner_name VARCHAR(180) NULL,
    location_label VARCHAR(180) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    location_radius_m INT NOT NULL DEFAULT 5,
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_maintenance_asset_pc (pc_id),
    INDEX idx_maintenance_asset_printer (printer_id),
    INDEX idx_maintenance_asset_type (maintenance_type),
    INDEX idx_maintenance_asset_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS maintenance_asset_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    maintenance_asset_id BIGINT NOT NULL,
    asset_item_id BIGINT NOT NULL,
    role_name VARCHAR(80) NULL,
    attached_at DATE NOT NULL,
    detached_at DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maint_asset_item_asset (maintenance_asset_id),
    INDEX idx_maint_asset_item_item (asset_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE pcs ADD COLUMN IF NOT EXISTS maintenance_asset_id BIGINT NULL AFTER asset_bundle_id;
ALTER TABLE printers ADD COLUMN IF NOT EXISTS maintenance_asset_id BIGINT NULL AFTER model_printer;
ALTER TABLE printers ADD COLUMN IF NOT EXISTS asset_item_id BIGINT NULL AFTER maintenance_asset_id;
ALTER TABLE maintenance_schedules ADD COLUMN IF NOT EXISTS maintenance_asset_id BIGINT NULL AFTER asset_type;

CREATE INDEX IF NOT EXISTS idx_pcs_maintenance_asset ON pcs (maintenance_asset_id);
CREATE INDEX IF NOT EXISTS idx_printers_maintenance_asset ON printers (maintenance_asset_id);
CREATE INDEX IF NOT EXISTS idx_printers_asset_item ON printers (asset_item_id);
CREATE INDEX IF NOT EXISTS idx_schedule_maintenance_asset ON maintenance_schedules (maintenance_asset_id);

INSERT INTO maintenance_assets (
    maintenance_asset_code,
    security_code,
    maintenance_type,
    name,
    pc_id,
    employee_nik,
    owner_name,
    location_label,
    latitude,
    longitude,
    location_radius_m,
    notes
)
SELECT
    CONCAT('MNT-', p.pc_id),
    p.security_code,
    'pc_set',
    TRIM(BOTH ' -' FROM CONCAT(COALESCE(p.owner_name, ''), ' - ', COALESCE(p.computer_name, ''))),
    p.pc_id,
    p.employee_nik,
    p.owner_name,
    p.location_label,
    p.latitude,
    p.longitude,
    p.location_radius_m,
    'Migrasi otomatis dari data PC lama.'
FROM pcs p
LEFT JOIN maintenance_assets ma ON ma.pc_id = p.pc_id
WHERE ma.id IS NULL;

UPDATE pcs p
JOIN maintenance_assets ma ON ma.pc_id = p.pc_id
SET p.maintenance_asset_id = ma.id
WHERE p.maintenance_asset_id IS NULL;

INSERT INTO maintenance_assets (
    maintenance_asset_code,
    security_code,
    maintenance_type,
    name,
    printer_id,
    location_label,
    latitude,
    longitude,
    location_radius_m,
    notes
)
SELECT
    CONCAT('MNT-', pr.prn_id),
    pr.security_code,
    'printer',
    pr.printer_name,
    pr.prn_id,
    pr.location,
    pr.latitude,
    pr.longitude,
    pr.location_radius_m,
    'Migrasi otomatis dari data printer lama.'
FROM printers pr
LEFT JOIN maintenance_assets ma ON ma.printer_id = pr.prn_id
WHERE ma.id IS NULL;

UPDATE printers pr
JOIN maintenance_assets ma ON ma.printer_id = pr.prn_id
SET pr.maintenance_asset_id = ma.id
WHERE pr.maintenance_asset_id IS NULL;

UPDATE maintenance_schedules s
JOIN pcs p ON p.pc_id = s.pc_id
SET s.maintenance_asset_id = p.maintenance_asset_id
WHERE s.maintenance_asset_id IS NULL AND s.pc_id IS NOT NULL;

UPDATE maintenance_schedules s
JOIN printers pr ON pr.prn_id = s.printer_id
SET s.maintenance_asset_id = pr.maintenance_asset_id
WHERE s.maintenance_asset_id IS NULL AND s.printer_id IS NOT NULL;
