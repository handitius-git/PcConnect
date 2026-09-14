-- Asset Master: Group, Tipe, Status, dan Identifier dinamis.
-- Aplikasi akan membuat tabel/kolom ini otomatis; file ini untuk import manual bila diperlukan.
CREATE TABLE IF NOT EXISTS asset_groups (id INT AUTO_INCREMENT PRIMARY KEY, group_code VARCHAR(20) NOT NULL UNIQUE, group_name VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS asset_types (id INT AUTO_INCREMENT PRIMARY KEY, asset_group_id INT NOT NULL, type_code VARCHAR(20) NOT NULL, type_name VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uq_asset_type_group_code(asset_group_id,type_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS asset_statuses (id INT AUTO_INCREMENT PRIMARY KEY, status_code VARCHAR(30) NOT NULL UNIQUE, status_name VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS asset_type_identifiers (id BIGINT AUTO_INCREMENT PRIMARY KEY, asset_type_id INT NOT NULL, identifier_code VARCHAR(40) NOT NULL, identifier_name VARCHAR(120) NOT NULL, data_type VARCHAR(20) NOT NULL DEFAULT 'text', is_required TINYINT(1) NOT NULL DEFAULT 0, is_unique TINYINT(1) NOT NULL DEFAULT 0, is_searchable TINYINT(1) NOT NULL DEFAULT 1, display_order INT NOT NULL DEFAULT 1, UNIQUE KEY uq_type_identifier_code(asset_type_id,identifier_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS asset_identifiers (id BIGINT AUTO_INCREMENT PRIMARY KEY, asset_item_id BIGINT NOT NULL, asset_type_identifier_id BIGINT NOT NULL, identifier_value VARCHAR(255) NOT NULL, UNIQUE KEY uq_asset_identifier(asset_item_id,asset_type_identifier_id), INDEX idx_identifier_value(identifier_value)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE asset_items ADD COLUMN asset_group_id INT NULL;
ALTER TABLE asset_items ADD COLUMN asset_type_id INT NULL;
ALTER TABLE asset_items ADD COLUMN asset_status_id INT NULL;
