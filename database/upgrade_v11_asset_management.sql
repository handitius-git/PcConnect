CREATE TABLE IF NOT EXISTS asset_companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_code VARCHAR(40) NOT NULL UNIQUE,
    company_name VARCHAR(180) NOT NULL,
    legal_name VARCHAR(220) NULL,
    address TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset_code VARCHAR(80) NOT NULL UNIQUE,
    company_id INT NULL,
    asset_type VARCHAR(60) NOT NULL,
    asset_name VARCHAR(180) NOT NULL,
    brand VARCHAR(120) NULL,
    model VARCHAR(160) NULL,
    serial_number VARCHAR(160) NULL,
    manufacture_year INT NULL,
    warranty_until DATE NULL,
    installed_at DATE NULL,
    purchase_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    current_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    location_label VARCHAR(180) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_asset_item_company (company_id),
    INDEX idx_asset_item_type (asset_type),
    INDEX idx_asset_item_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_bundles (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    maintenance_asset_code VARCHAR(80) NOT NULL UNIQUE,
    company_id INT NULL,
    bundle_type VARCHAR(80) NOT NULL,
    bundle_name VARCHAR(180) NOT NULL,
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
    INDEX idx_asset_bundle_company (company_id),
    INDEX idx_asset_bundle_type (bundle_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_bundle_members (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bundle_id BIGINT NOT NULL,
    asset_item_id BIGINT NOT NULL,
    role_name VARCHAR(80) NULL,
    attached_at DATE NOT NULL,
    detached_at DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bundle_member_bundle (bundle_id),
    INDEX idx_bundle_member_item (asset_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_movements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset_item_id BIGINT NOT NULL,
    from_bundle_id BIGINT NULL,
    to_bundle_id BIGINT NULL,
    from_company_id INT NULL,
    to_company_id INT NULL,
    movement_date DATE NOT NULL,
    reason TEXT NULL,
    pic VARCHAR(160) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_movement_item (asset_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_repairs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset_item_id BIGINT NOT NULL,
    bundle_id BIGINT NULL,
    repair_date DATE NOT NULL,
    repair_location VARCHAR(180) NULL,
    repair_vendor VARCHAR(180) NULL,
    problem_description TEXT NULL,
    repair_action TEXT NULL,
    spare_part_replaced TEXT NULL,
    repair_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    warranty_claim TINYINT(1) NOT NULL DEFAULT 0,
    technician_or_pic VARCHAR(160) NULL,
    attachment_path VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_repair_item (asset_item_id),
    INDEX idx_repair_bundle (bundle_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS asset_repair_parts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    repair_id BIGINT NOT NULL,
    part_name VARCHAR(180) NOT NULL,
    part_brand VARCHAR(120) NULL,
    part_serial VARCHAR(160) NULL,
    qty DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    old_part_condition VARCHAR(160) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_repair_part_repair (repair_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
