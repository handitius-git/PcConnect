CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','maintenance_admin','technician') NOT NULL DEFAULT 'technician',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mobile_api_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    device_name VARCHAR(160) NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mobile_token_user (user_id),
    CONSTRAINT fk_mobile_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE pcs (
    pc_id VARCHAR(60) PRIMARY KEY,
    security_code VARCHAR(12) NOT NULL,
    employee_nik VARCHAR(80) NULL,
    owner_name VARCHAR(160) NOT NULL,
    computer_name VARCHAR(160) NULL,
    asset_item_id BIGINT NULL,
    asset_bundle_id BIGINT NULL,
    maintenance_asset_id BIGINT NULL,
    location_label VARCHAR(180) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    location_radius_m INT NOT NULL DEFAULT 5,
    physical_condition TEXT NULL,
    general_specs LONGTEXT NULL,
    software LONGTEXT NULL,
    device_management LONGTEXT NULL,
    benchmark LONGTEXT NULL,
    startup_analysis LONGTEXT NULL,
    ai_recommendation TEXT NULL,
    last_analyzed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pcs_asset_item (asset_item_id),
    INDEX idx_pcs_asset_bundle (asset_bundle_id),
    INDEX idx_pcs_maintenance_asset (maintenance_asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE company_source_config (
    config_key VARCHAR(80) PRIMARY KEY,
    config_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_source_config (
    config_key VARCHAR(80) PRIMARY KEY,
    config_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employee_directory (
    nik VARCHAR(80) PRIMARY KEY,
    employee_name VARCHAR(200) NOT NULL,
    department VARCHAR(200) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    synced_at DATETIME NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_employee_name (employee_name),
    INDEX idx_employee_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE printers (
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
    maintenance_asset_id BIGINT NULL,
    asset_item_id BIGINT NULL,
    physical_condition TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_printers_maintenance_asset (maintenance_asset_id),
    INDEX idx_printers_asset_item (asset_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE asset_companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_code VARCHAR(40) NOT NULL UNIQUE,
    external_company_id VARCHAR(80) NULL,
    company_name VARCHAR(180) NOT NULL,
    legal_name VARCHAR(220) NULL,
    address TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_asset_company_external (external_company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE asset_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset_code VARCHAR(80) NOT NULL UNIQUE,
    company_id INT NULL,
    asset_mode VARCHAR(20) NOT NULL DEFAULT 'standalone',
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
    INDEX idx_asset_item_mode (asset_mode),
    INDEX idx_asset_item_type (asset_type),
    INDEX idx_asset_item_status (status),
    CONSTRAINT fk_asset_item_company FOREIGN KEY (company_id) REFERENCES asset_companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE asset_item_members (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    parent_asset_item_id BIGINT NOT NULL,
    child_asset_item_id BIGINT NOT NULL,
    role_name VARCHAR(80) NULL,
    attached_at DATE NOT NULL,
    detached_at DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_asset_item_member_parent (parent_asset_item_id),
    INDEX idx_asset_item_member_child (child_asset_item_id),
    CONSTRAINT fk_asset_item_member_parent FOREIGN KEY (parent_asset_item_id) REFERENCES asset_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_asset_item_member_child FOREIGN KEY (child_asset_item_id) REFERENCES asset_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE asset_bundles (
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
    INDEX idx_asset_bundle_type (bundle_type),
    CONSTRAINT fk_asset_bundle_company FOREIGN KEY (company_id) REFERENCES asset_companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE asset_bundle_members (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    bundle_id BIGINT NOT NULL,
    asset_item_id BIGINT NOT NULL,
    role_name VARCHAR(80) NULL,
    attached_at DATE NOT NULL,
    detached_at DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bundle_member_bundle (bundle_id),
    INDEX idx_bundle_member_item (asset_item_id),
    CONSTRAINT fk_bundle_member_bundle FOREIGN KEY (bundle_id) REFERENCES asset_bundles(id) ON DELETE CASCADE,
    CONSTRAINT fk_bundle_member_item FOREIGN KEY (asset_item_id) REFERENCES asset_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE asset_movements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset_item_id BIGINT NOT NULL,
    from_bundle_id BIGINT NULL,
    to_bundle_id BIGINT NULL,
    from_parent_asset_item_id BIGINT NULL,
    to_parent_asset_item_id BIGINT NULL,
    from_company_id INT NULL,
    to_company_id INT NULL,
    movement_date DATE NOT NULL,
    reason TEXT NULL,
    pic VARCHAR(160) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_movement_item (asset_item_id),
    INDEX idx_asset_movement_from_parent (from_parent_asset_item_id),
    INDEX idx_asset_movement_to_parent (to_parent_asset_item_id),
    CONSTRAINT fk_movement_item FOREIGN KEY (asset_item_id) REFERENCES asset_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE asset_repairs (
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
    INDEX idx_repair_bundle (bundle_id),
    CONSTRAINT fk_repair_item FOREIGN KEY (asset_item_id) REFERENCES asset_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_repair_bundle FOREIGN KEY (bundle_id) REFERENCES asset_bundles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE asset_repair_parts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    repair_id BIGINT NOT NULL,
    part_name VARCHAR(180) NOT NULL,
    part_brand VARCHAR(120) NULL,
    part_serial VARCHAR(160) NULL,
    qty DECIMAL(12,2) NOT NULL DEFAULT 1,
    unit_cost DECIMAL(18,2) NOT NULL DEFAULT 0,
    old_part_condition VARCHAR(160) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_repair_part_repair (repair_id),
    CONSTRAINT fk_repair_part_repair FOREIGN KEY (repair_id) REFERENCES asset_repairs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maintenance_assets (
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
    INDEX idx_maintenance_asset_status (status),
    CONSTRAINT fk_maintenance_asset_pc FOREIGN KEY (pc_id) REFERENCES pcs(pc_id) ON DELETE SET NULL,
    CONSTRAINT fk_maintenance_asset_printer FOREIGN KEY (printer_id) REFERENCES printers(prn_id) ON DELETE SET NULL,
    CONSTRAINT fk_maintenance_asset_company FOREIGN KEY (company_id) REFERENCES asset_companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maintenance_asset_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    maintenance_asset_id BIGINT NOT NULL,
    asset_item_id BIGINT NOT NULL,
    role_name VARCHAR(80) NULL,
    attached_at DATE NOT NULL,
    detached_at DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_maint_asset_item_asset (maintenance_asset_id),
    INDEX idx_maint_asset_item_item (asset_item_id),
    CONSTRAINT fk_maint_asset_item_asset FOREIGN KEY (maintenance_asset_id) REFERENCES maintenance_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_maint_asset_item_item FOREIGN KEY (asset_item_id) REFERENCES asset_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE analysis_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    pc_id VARCHAR(60) NOT NULL,
    payload LONGTEXT NOT NULL,
    hardware_score INT NOT NULL DEFAULT 0,
    software_score INT NOT NULL DEFAULT 0,
    device_score INT NOT NULL DEFAULT 0,
    benchmark_score INT NOT NULL DEFAULT 0,
    startup_score INT NOT NULL DEFAULT 0,
    recommendation TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_analysis_pc (pc_id),
    CONSTRAINT fk_analysis_pc FOREIGN KEY (pc_id) REFERENCES pcs(pc_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maintenance_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    estimated_minutes INT NOT NULL DEFAULT 5,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maintenance_schedules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    asset_type ENUM('pc','printer') NOT NULL DEFAULT 'pc',
    maintenance_asset_id BIGINT NULL,
    pc_id VARCHAR(60) NULL,
    printer_id VARCHAR(60) NULL,
    technician_id INT NULL,
    scheduled_date DATE NOT NULL,
    status ENUM('scheduled','validated','in_progress','completed','reopened') NOT NULL DEFAULT 'scheduled',
    notes TEXT NULL,
    arrival_at DATETIME NULL,
    arrival_ip VARCHAR(80) NULL,
    arrival_user_agent TEXT NULL,
    arrival_lat DECIMAL(10,7) NULL,
    arrival_lng DECIMAL(10,7) NULL,
    arrival_browser VARCHAR(180) NULL,
    photo_challenge_code VARCHAR(12) NULL,
    photo_challenge_generated_at DATETIME NULL,
    completed_at DATETIME NULL,
    locked_at DATETIME NULL,
    unlocked_at DATETIME NULL,
    unlocked_by INT NULL,
    unlock_reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_schedule_pc (pc_id),
    INDEX idx_schedule_printer (printer_id),
    INDEX idx_schedule_maintenance_asset (maintenance_asset_id),
    INDEX idx_schedule_tech (technician_id),
    CONSTRAINT fk_schedule_pc FOREIGN KEY (pc_id) REFERENCES pcs(pc_id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_tech FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_schedule_unlock_user FOREIGN KEY (unlocked_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE schedule_jobs (
    schedule_id BIGINT NOT NULL,
    job_id INT NOT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    done_at DATETIME NULL,
    note TEXT NULL,
    PRIMARY KEY (schedule_id, job_id),
    CONSTRAINT fk_schedule_jobs_schedule FOREIGN KEY (schedule_id) REFERENCES maintenance_schedules(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedule_jobs_job FOREIGN KEY (job_id) REFERENCES maintenance_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maintenance_reports (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    schedule_id BIGINT NOT NULL,
    technician_id INT NOT NULL,
    condition_rating ENUM('Excellent','Good','Fair','Need Repair','Need Replace') NULL,
    physical_condition TEXT NULL,
    additional_notes TEXT NULL,
    before_photos LONGTEXT NULL,
    process_photos LONGTEXT NULL,
    after_photos LONGTEXT NULL,
    photo_challenge_code VARCHAR(12) NULL,
    photo_audit_meta LONGTEXT NULL,
    signature_name VARCHAR(160) NULL,
    signature_path VARCHAR(255) NULL,
    report_hash CHAR(64) NULL,
    locked_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_schedule (schedule_id),
    INDEX idx_report_tech (technician_id),
    CONSTRAINT fk_report_schedule FOREIGN KEY (schedule_id) REFERENCES maintenance_schedules(id) ON DELETE CASCADE,
    CONSTRAINT fk_report_tech FOREIGN KEY (technician_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE maintenance_timeline (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    schedule_id BIGINT NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    event_note TEXT NULL,
    actor_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_timeline_schedule (schedule_id),
    CONSTRAINT fk_timeline_schedule FOREIGN KEY (schedule_id) REFERENCES maintenance_schedules(id) ON DELETE CASCADE,
    CONSTRAINT fk_timeline_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO users (name, username, password_hash, role) VALUES
('Administrator', 'admin', '$2y$10$ohXX2wLvip21SWVCAEzQXOsym9nYYZNG2BffWfsQFeJVBglYbR2iy', 'admin');

INSERT INTO maintenance_jobs (title, description) VALUES
('Bersihkan Fan', 'Bersihkan fan casing, CPU, dan area airflow.'),
('Bersihkan RAM', 'Lepas dan bersihkan area RAM bila diperlukan.'),
('Bersihkan Motherboard', 'Bersihkan debu motherboard dengan aman.'),
('Cek SMART SSD', 'Cek health storage dan kapasitas kosong.'),
('Update Windows', 'Pastikan patch Windows penting sudah berjalan.'),
('Update Antivirus', 'Cek status antivirus dan update definisi.'),
('Test Benchmark', 'Jalankan PcNalisa atau benchmark ringan.'),
('Rapikan Kabel', 'Rapikan kabel power, LAN, dan peripheral.'),
('Bersihkan Keyboard', 'Bersihkan keyboard user.'),
('Bersihkan Monitor', 'Bersihkan monitor dan cek tampilan.'),
('Test LAN', 'Cek koneksi LAN/Wi-Fi.'),
('Test Printer', 'Cek printer bila digunakan user.');
