<?php

declare(strict_types=1);

function db_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        $cache[$table] = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        $cache[$table] = false;
    }
    return $cache[$table];
}

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

function db_foreign_key_name(PDO $pdo, string $table, string $column, string $referencedTable): string
{
    try {
        $stmt = $pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column, $referencedTable]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

function db_column_nullable(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $stmt->execute([$table, $column]);
        return strtoupper((string)$stmt->fetchColumn()) === 'YES';
    } catch (Throwable $e) {
        return false;
    }
}

function ensure_base_schema_from_sql(PDO $pdo): void
{
    if (!db_table_exists($pdo, 'pcs') || !db_table_exists($pdo, 'users')) {
        $schemaPath = dirname(__DIR__, 2) . '/database/schema.sql';
        if (is_file($schemaPath)) {
            try {
                $sql = file_get_contents($schemaPath);
                if ($sql !== false && trim($sql) !== '') {
                    $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($sql));
                    foreach ($statements as $statement) {
                        $statement = trim((string)$statement);
                        if ($statement === '' || str_starts_with($statement, '--')) {
                            continue;
                        }
                        try {
                            $pdo->exec($statement);
                        } catch (Throwable $ignored) {
                        }
                    }
                }
            } catch (Throwable $ignored) {
            }
        }
    }

    ensure_maintenance_work_schema($pdo);
    ensure_brand_and_master_item_schema($pdo);
}

function deduplicate_maintenance_jobs(PDO $pdo): int
{
    if (!db_table_exists($pdo, 'maintenance_jobs')) {
        return 0;
    }
    $totalCleaned = 0;
    try {
        $dupes = $pdo->query('SELECT TRIM(title) AS clean_title, COUNT(*) AS cnt FROM maintenance_jobs GROUP BY TRIM(title) HAVING cnt > 1')->fetchAll();
        foreach ($dupes as $dupe) {
            $title = (string)$dupe['clean_title'];
            $rows = $pdo->query('SELECT id, is_active, asset_group_id FROM maintenance_jobs WHERE TRIM(title) = ' . $pdo->quote($title) . ' ORDER BY id ASC')->fetchAll();
            if (count($rows) <= 1) {
                continue;
            }
            $primaryId = (int)$rows[0]['id'];
            for ($i = 1; $i < count($rows); $i++) {
                $dupeId = (int)$rows[$i]['id'];
                if (db_table_exists($pdo, 'schedule_jobs')) {
                    $pdo->exec("DELETE FROM schedule_jobs WHERE job_id = {$dupeId} AND schedule_id IN (SELECT schedule_id FROM (SELECT schedule_id FROM schedule_jobs WHERE job_id = {$primaryId}) AS tmp)");
                    $pdo->exec("UPDATE schedule_jobs SET job_id = {$primaryId} WHERE job_id = {$dupeId}");
                }
                $pdo->exec("DELETE FROM maintenance_jobs WHERE id = {$dupeId}");
                $totalCleaned++;
            }
        }
    } catch (Throwable $ignored) {
    }
    return $totalCleaned;
}

function ensure_maintenance_work_schema(PDO $pdo): void
{
    ensure_asset_master_schema($pdo);
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(160) NOT NULL,
            description TEXT NULL,
            asset_group_id INT NULL,
            maintenance_category_id INT NULL,
            estimated_minutes INT NOT NULL DEFAULT 5,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_jobs_category (maintenance_category_id),
            INDEX idx_jobs_asset_group (asset_group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!db_column_exists($pdo, 'maintenance_jobs', 'asset_group_id')) {
            $pdo->exec('ALTER TABLE maintenance_jobs ADD COLUMN asset_group_id INT NULL AFTER description');
            try {
                $pdo->exec('ALTER TABLE maintenance_jobs ADD INDEX idx_jobs_asset_group (asset_group_id)');
            } catch (Throwable $ignored) {}
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_schedules (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            asset_type VARCHAR(60) NOT NULL DEFAULT 'pc',
            maintenance_asset_id BIGINT NULL,
            asset_group_id INT NULL,
            maintenance_category_id INT NULL,
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
            INDEX idx_schedules_asset_group (asset_group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!db_column_exists($pdo, 'maintenance_schedules', 'asset_group_id')) {
            $pdo->exec('ALTER TABLE maintenance_schedules ADD COLUMN asset_group_id INT NULL AFTER maintenance_asset_id');
            try {
                $pdo->exec('ALTER TABLE maintenance_schedules ADD INDEX idx_schedules_asset_group (asset_group_id)');
            } catch (Throwable $ignored) {}
        }
        try {
            $pdo->exec("ALTER TABLE maintenance_schedules MODIFY asset_type VARCHAR(60) NOT NULL DEFAULT 'pc'");
        } catch (Throwable $ignored) {}

        // Migrasi one-time: Pasangkan job yang belum memiliki group ke grup IT
        try {
            $itGroupId = (int)$pdo->query("SELECT id FROM asset_groups WHERE group_code='IT' LIMIT 1")->fetchColumn();
            if ($itGroupId > 0) {
                $pdo->exec("UPDATE maintenance_jobs SET asset_group_id = {$itGroupId} WHERE asset_group_id IS NULL");
            }
        } catch (Throwable $ignored) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS schedule_jobs (
            schedule_id BIGINT NOT NULL,
            job_id INT NOT NULL,
            is_done TINYINT(1) NOT NULL DEFAULT 0,
            done_at DATETIME NULL,
            note TEXT NULL,
            PRIMARY KEY (schedule_id, job_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_reports (
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
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_timeline (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            schedule_id BIGINT NOT NULL,
            event_type VARCHAR(60) NOT NULL,
            event_note TEXT NULL,
            actor_user_id INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignored) {
    }

    try {
        if (db_table_exists($pdo, 'maintenance_reports')) {
            foreach ([
                ['process_photos', 'LONGTEXT NULL AFTER before_photos'],
                ['photo_challenge_code', 'VARCHAR(12) NULL AFTER after_photos'],
                ['photo_audit_meta', 'LONGTEXT NULL AFTER photo_challenge_code'],
                ['signature_name', 'VARCHAR(160) NULL AFTER photo_audit_meta'],
                ['signature_path', 'VARCHAR(255) NULL AFTER signature_name'],
                ['report_hash', 'CHAR(64) NULL AFTER signature_path'],
                ['locked_at', 'DATETIME NULL AFTER report_hash'],
            ] as $column) {
                if (!db_column_exists($pdo, 'maintenance_reports', $column[0])) {
                    $pdo->exec('ALTER TABLE maintenance_reports ADD COLUMN ' . $column[0] . ' ' . $column[1]);
                }
            }
        }
        if (db_table_exists($pdo, 'maintenance_schedules')) {
            foreach ([
                ['photo_challenge_code', 'VARCHAR(12) NULL AFTER arrival_browser'],
                ['photo_challenge_generated_at', 'DATETIME NULL AFTER photo_challenge_code'],
                ['before_photos', 'LONGTEXT NULL AFTER photo_challenge_generated_at'],
                ['before_photo_at', 'DATETIME NULL AFTER before_photos'],
                ['photo_audit_meta', 'LONGTEXT NULL AFTER before_photo_at'],
            ] as $column) {
                if (!db_column_exists($pdo, 'maintenance_schedules', $column[0])) {
                    $pdo->exec('ALTER TABLE maintenance_schedules ADD COLUMN ' . $column[0] . ' ' . $column[1]);
                }
            }
        }
    } catch (Throwable $ignored) {
    }

    deduplicate_maintenance_jobs($pdo);
    ensure_preventive_job_desk_schema($pdo);
}

function ensure_preventive_job_desk_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS preventive_job_desks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_desk_code VARCHAR(40) NULL,
            job_desk_name VARCHAR(160) NOT NULL UNIQUE,
            asset_group_id INT NULL,
            asset_type_id INT NULL,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pjd_group (asset_group_id),
            INDEX idx_pjd_type (asset_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        try {
            $pdo->exec("ALTER TABLE maintenance_assets MODIFY COLUMN job_desk_name VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
        } catch (Throwable $ignored) {}
        try {
            $pdo->exec("ALTER TABLE maintenance_jobs MODIFY COLUMN job_desk_name VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
        } catch (Throwable $ignored) {}

        // Pastikan tabel master disinkronkan hanya jika ada job_desk_name valid
        $pdo->exec("INSERT IGNORE INTO preventive_job_desks (job_desk_name, asset_group_id, asset_type_id) 
            SELECT DISTINCT job_desk_name, asset_group_id, asset_type_id 
            FROM maintenance_jobs 
            WHERE job_desk_name IS NOT NULL AND job_desk_name != ''");
    } catch (Throwable $ignored) {
    }
}

function ensure_printer_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS printers (
            prn_id VARCHAR(60) PRIMARY KEY,
            security_code VARCHAR(12) NOT NULL,
            printer_name VARCHAR(160) NOT NULL,
            location VARCHAR(160) NULL,
            serial_number VARCHAR(160) NULL,
            ip_printer VARCHAR(60) NULL,
            model_printer VARCHAR(160) NULL,
            maintenance_asset_id BIGINT NULL,
            asset_item_id BIGINT NULL,
            physical_condition VARCHAR(160) NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            location_radius_m INT NOT NULL DEFAULT 5,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_printers_name (printer_name),
            INDEX idx_printers_maint_asset (maintenance_asset_id),
            INDEX idx_printers_asset_item (asset_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (db_table_exists($pdo, 'printers')) {
            if (!db_column_exists($pdo, 'printers', 'owner_name')) {
                $pdo->exec('ALTER TABLE printers ADD COLUMN owner_name VARCHAR(160) NULL AFTER location');
            }
            if (!db_column_exists($pdo, 'printers', 'employee_nik')) {
                $pdo->exec('ALTER TABLE printers ADD COLUMN employee_nik VARCHAR(80) NULL AFTER owner_name');
            }
        }

        if (db_table_exists($pdo, 'maintenance_schedules') && !db_column_exists($pdo, 'maintenance_schedules', 'printer_id')) {
            $pdo->exec('ALTER TABLE maintenance_schedules ADD COLUMN printer_id VARCHAR(60) NULL AFTER pc_id');
        }
        if (db_table_exists($pdo, 'maintenance_schedules') && !db_column_exists($pdo, 'maintenance_schedules', 'asset_type')) {
            $pdo->exec("ALTER TABLE maintenance_schedules ADD COLUMN asset_type ENUM('pc','printer') NOT NULL DEFAULT 'pc' AFTER id");
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_pc_location_schema(PDO $pdo): void
{
    try {
        if (db_table_exists($pdo, 'pcs')) {
            if (!db_column_exists($pdo, 'pcs', 'location_label')) {
                $pdo->exec('ALTER TABLE pcs ADD COLUMN location_label VARCHAR(180) NULL AFTER owner_name');
            }
            if (!db_column_exists($pdo, 'pcs', 'latitude')) {
                $pdo->exec('ALTER TABLE pcs ADD COLUMN latitude DECIMAL(10,7) NULL AFTER location_label');
            }
            if (!db_column_exists($pdo, 'pcs', 'longitude')) {
                $pdo->exec('ALTER TABLE pcs ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude');
            }
            if (!db_column_exists($pdo, 'pcs', 'location_radius_m')) {
                $pdo->exec('ALTER TABLE pcs ADD COLUMN location_radius_m INT NOT NULL DEFAULT 5 AFTER longitude');
            }
            try { $pdo->exec('CREATE INDEX idx_pcs_location_label ON pcs (location_label)'); } catch (Throwable $ignored) {}
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_employee_source_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_source_config (
            config_key VARCHAR(80) PRIMARY KEY,
            config_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_directory (
            nik VARCHAR(80) PRIMARY KEY,
            name VARCHAR(180) NOT NULL,
            department VARCHAR(180) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_emp_name (name),
            INDEX idx_emp_dept (department)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!db_column_exists($pdo, 'employee_directory', 'name') && db_column_exists($pdo, 'employee_directory', 'employee_name')) {
            $pdo->exec("ALTER TABLE employee_directory ADD COLUMN name VARCHAR(180) NOT NULL DEFAULT '' AFTER nik");
            $pdo->exec("UPDATE employee_directory SET name = employee_name WHERE name = '' OR name IS NULL");
        }
        if (!db_column_exists($pdo, 'employee_directory', 'employee_name') && db_column_exists($pdo, 'employee_directory', 'name')) {
            $pdo->exec("ALTER TABLE employee_directory ADD COLUMN employee_name VARCHAR(180) NOT NULL DEFAULT '' AFTER nik");
            $pdo->exec("UPDATE employee_directory SET employee_name = name WHERE employee_name = '' OR employee_name IS NULL");
        }
        if (db_table_exists($pdo, 'asset_items') && !db_column_exists($pdo, 'asset_items', 'custodian_name')) {
            $pdo->exec("ALTER TABLE asset_items ADD COLUMN custodian_name VARCHAR(160) NULL AFTER model");
        }
        if (db_table_exists($pdo, 'asset_items') && !db_column_exists($pdo, 'asset_items', 'custodian_nik')) {
            $pdo->exec("ALTER TABLE asset_items ADD COLUMN custodian_nik VARCHAR(80) NULL AFTER custodian_name");
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_company_source_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS company_source_config (
            config_key VARCHAR(80) PRIMARY KEY,
            config_value TEXT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $ignored) {
    }
}

function ensure_asset_management_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_code VARCHAR(40) NOT NULL UNIQUE,
            external_company_id VARCHAR(80) NULL,
            company_name VARCHAR(180) NOT NULL,
            legal_name VARCHAR(220) NULL,
            address TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(60) NOT NULL UNIQUE,
            is_system TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            asset_code VARCHAR(80) NOT NULL UNIQUE,
            company_id INT NULL,
            asset_group_id INT NULL,
            asset_type_id INT NULL,
            asset_status_id INT NULL,
            asset_mode VARCHAR(20) NOT NULL DEFAULT 'standalone',
            asset_type VARCHAR(60) NOT NULL,
            asset_category VARCHAR(60) NOT NULL,
            asset_name VARCHAR(180) NOT NULL,
            brand VARCHAR(120) NULL,
            model VARCHAR(160) NULL,
            serial_number VARCHAR(160) NULL,
            custodian_name VARCHAR(160) NULL,
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
            INDEX idx_asset_item_category (asset_category),
            INDEX idx_asset_item_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            foreach (['Computer','Printer','Kendaraan','Lain-lain'] as $seedCat) {
                $stmt = $pdo->prepare('SELECT id FROM asset_categories WHERE category_name=? LIMIT 1');
                $stmt->execute([$seedCat]);
                if (!$stmt->fetchColumn()) {
                    $pdo->prepare('INSERT INTO asset_categories (category_name, is_system, is_active) VALUES (?, 1, 1)')->execute([$seedCat]);
                }
            }
        } catch (Throwable $ignored) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_item_members (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            parent_asset_item_id BIGINT NOT NULL,
            child_asset_item_id BIGINT NOT NULL,
            role_name VARCHAR(80) NULL,
            attached_at DATE NOT NULL,
            detached_at DATE NULL,
            notes TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_asset_item_member_parent (parent_asset_item_id),
            INDEX idx_asset_item_member_child (child_asset_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_bundles (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_bundle_members (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_movements (
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
            INDEX idx_movement_item (asset_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_repairs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_repair_parts (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!db_column_exists($pdo, 'asset_companies', 'external_company_id')) {
            $pdo->exec('ALTER TABLE asset_companies ADD COLUMN external_company_id VARCHAR(80) NULL AFTER company_code');
        }
        if (!db_column_exists($pdo, 'asset_items', 'asset_category')) {
            $pdo->exec("ALTER TABLE asset_items ADD COLUMN asset_category VARCHAR(60) NULL AFTER asset_type");
        }
        $pdo->exec("UPDATE asset_items SET asset_category='Computer' WHERE asset_category IS NULL OR TRIM(asset_category)=''");
        try { $pdo->exec('ALTER TABLE asset_items MODIFY asset_category VARCHAR(60) NOT NULL'); } catch (Throwable $ignored) {}
        if (!db_column_exists($pdo, 'asset_items', 'asset_mode')) {
            $pdo->exec("ALTER TABLE asset_items ADD COLUMN asset_mode VARCHAR(20) NOT NULL DEFAULT 'standalone' AFTER company_id");
        }
        // Sinkronisasi data eksisting: item yang aktif menjadi child di asset_item_members diubah mode-nya menjadi 'child'
        if (db_table_exists($pdo, 'asset_item_members') && db_column_exists($pdo, 'asset_items', 'asset_mode')) {
            $pdo->exec("UPDATE asset_items SET asset_mode='child' WHERE id IN (SELECT child_asset_item_id FROM asset_item_members WHERE detached_at IS NULL) AND asset_mode<>'group'");
            $pdo->exec("UPDATE asset_items SET asset_mode='standalone' WHERE id NOT IN (SELECT child_asset_item_id FROM asset_item_members WHERE detached_at IS NULL) AND asset_mode='child'");
        }
        cleanup_and_repair_pc_asset_conflicts($pdo);
        if (!db_column_exists($pdo, 'asset_movements', 'from_parent_asset_item_id')) {
            $pdo->exec('ALTER TABLE asset_movements ADD COLUMN from_parent_asset_item_id BIGINT NULL AFTER to_bundle_id');
        }
        if (!db_column_exists($pdo, 'asset_movements', 'to_parent_asset_item_id')) {
            $pdo->exec('ALTER TABLE asset_movements ADD COLUMN to_parent_asset_item_id BIGINT NULL AFTER from_parent_asset_item_id');
        }
        if (!db_column_exists($pdo, 'pcs', 'asset_item_id')) {
            $pdo->exec('ALTER TABLE pcs ADD COLUMN asset_item_id BIGINT NULL AFTER computer_name');
        }
        if (!db_column_exists($pdo, 'pcs', 'asset_bundle_id')) {
            $pdo->exec('ALTER TABLE pcs ADD COLUMN asset_bundle_id BIGINT NULL AFTER asset_item_id');
        }
        if (db_table_exists($pdo, 'printers')) {
            if (!db_column_exists($pdo, 'printers', 'owner_name')) {
                $pdo->exec('ALTER TABLE printers ADD COLUMN owner_name VARCHAR(160) NULL AFTER location');
            }
            if (!db_column_exists($pdo, 'printers', 'employee_nik')) {
                $pdo->exec('ALTER TABLE printers ADD COLUMN employee_nik VARCHAR(80) NULL AFTER owner_name');
            }
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_asset_master_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_groups (id INT AUTO_INCREMENT PRIMARY KEY, group_code VARCHAR(20) NOT NULL UNIQUE, group_name VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_types (id INT AUTO_INCREMENT PRIMARY KEY, asset_group_id INT NOT NULL, type_code VARCHAR(20) NOT NULL, type_name VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, UNIQUE KEY uq_asset_type_group_code(asset_group_id,type_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_statuses (id INT AUTO_INCREMENT PRIMARY KEY, status_code VARCHAR(30) NOT NULL UNIQUE, status_name VARCHAR(100) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_type_identifiers (id BIGINT AUTO_INCREMENT PRIMARY KEY, asset_type_id INT NOT NULL, identifier_code VARCHAR(40) NOT NULL, identifier_name VARCHAR(120) NOT NULL, data_type VARCHAR(20) NOT NULL DEFAULT 'text', is_required TINYINT(1) NOT NULL DEFAULT 0, is_unique TINYINT(1) NOT NULL DEFAULT 0, is_searchable TINYINT(1) NOT NULL DEFAULT 1, display_order INT NOT NULL DEFAULT 1, UNIQUE KEY uq_type_identifier_code(asset_type_id,identifier_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_identifiers (id BIGINT AUTO_INCREMENT PRIMARY KEY, asset_item_id BIGINT NOT NULL, asset_type_identifier_id BIGINT NOT NULL, identifier_value VARCHAR(255) NOT NULL, UNIQUE KEY uq_asset_identifier(asset_item_id,asset_type_identifier_id), INDEX idx_identifier_value(identifier_value)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_type_specifications (id BIGINT AUTO_INCREMENT PRIMARY KEY, asset_type_id INT NOT NULL, specification_code VARCHAR(40) NOT NULL, specification_name VARCHAR(120) NOT NULL, data_type VARCHAR(20) NOT NULL DEFAULT 'text', is_required TINYINT(1) NOT NULL DEFAULT 0, is_searchable TINYINT(1) NOT NULL DEFAULT 1, display_order INT NOT NULL DEFAULT 1, UNIQUE KEY uq_type_specification_code(asset_type_id,specification_code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_specifications (id BIGINT AUTO_INCREMENT PRIMARY KEY, asset_item_id BIGINT NOT NULL, asset_type_specification_id BIGINT NOT NULL, specification_value VARCHAR(255) NOT NULL, UNIQUE KEY uq_asset_specification(asset_item_id,asset_type_specification_id), INDEX idx_specification_value(specification_value)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_specification_logs (id BIGINT AUTO_INCREMENT PRIMARY KEY, asset_item_id BIGINT NOT NULL, asset_type_specification_id BIGINT NOT NULL, specification_name VARCHAR(120) NOT NULL, old_value VARCHAR(255) NULL, new_value VARCHAR(255) NOT NULL, change_type VARCHAR(40) NOT NULL DEFAULT 'upgrade', notes TEXT NULL, technician_name VARCHAR(160) NULL, changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_asl_asset_item (asset_item_id), INDEX idx_asl_spec (asset_type_specification_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_maintenance_templates (id BIGINT AUTO_INCREMENT PRIMARY KEY, asset_type_id INT NOT NULL, template_name VARCHAR(160) NOT NULL, frequency VARCHAR(20) NOT NULL, interval_value INT NOT NULL DEFAULT 1, meter_type VARCHAR(20) NOT NULL DEFAULT 'calendar', is_active TINYINT(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([['IT','IT Asset'],['VH','Vehicle'],['FC','Facility']] as [$code,$name]) {
            $s = $pdo->prepare('INSERT IGNORE INTO asset_groups(group_code,group_name) VALUES (?,?)');
            $s->execute([$code,$name]);
        }
        foreach ([['ACTIVE','Active'],['REPAIR','In Repair'],['MAINT','Under Maintenance'],['IDLE','Idle'],['TRANSFER','Transferred'],['DISPOSED','Disposed'],['LOST','Lost'],['SOLD','Sold'],['RETIRED','Retired']] as [$code,$name]) {
            $s = $pdo->prepare('INSERT IGNORE INTO asset_statuses(status_code,status_name) VALUES (?,?)');
            $s->execute([$code,$name]);
        }
        foreach ([['IT','CMP','Computer'],['IT','NBK','Notebook'],['IT','PRT','Printer'],['IT','SRV','Server'],['VH','CAR','Car'],['VH','MTR','Motorcycle'],['VH','TRK','Truck']] as [$group,$code,$name]) {
            $s = $pdo->prepare('INSERT IGNORE INTO asset_types(asset_group_id,type_code,type_name) SELECT id,?,? FROM asset_groups WHERE group_code=?');
            $s->execute([$code,$name,$group]);
        }
        // Auto-repair asset_types asset_group_id to correct groups
        repair_asset_type_groups($pdo);
        foreach ([['CMP','SERIAL','Serial Number',0,1],['CMP','HOSTNAME','Hostname',0,1],['CMP','MAC','MAC Address',0,1],['CAR','PLATE','License Plate',1,1],['CAR','ENGINE','Engine Number',1,1],['CAR','VIN','Chassis / VIN',1,1]] as [$type,$code,$name,$required,$unique]) {
            try {
                $s = $pdo->prepare('INSERT IGNORE INTO asset_type_identifiers(asset_type_id,identifier_code,identifier_name,is_required,is_unique,display_order) SELECT id,?,?,?,?,? FROM asset_types WHERE type_code=?');
                $s->execute([$code,$name,$required,$unique,1,$type]);
            } catch (Throwable $ignored) {}
        }
        $defaultSpecs = [
            ['CMP', 'CPU', 'Processor (CPU)', 'text', 0, 1, 1],
            ['CMP', 'RAM', 'RAM / Memori', 'text', 0, 1, 2],
            ['CMP', 'STORAGE', 'Storage / Penyimpanan', 'text', 0, 1, 3],
            ['CMP', 'OS', 'Sistem Operasi (OS)', 'text', 0, 1, 4],
            ['CMP', 'GPU', 'Kartu Grafis (GPU/VGA)', 'text', 0, 1, 5],
            ['NBK', 'CPU', 'Processor (CPU)', 'text', 0, 1, 1],
            ['NBK', 'RAM', 'RAM / Memori', 'text', 0, 1, 2],
            ['NBK', 'STORAGE', 'Storage / Penyimpanan', 'text', 0, 1, 3],
            ['NBK', 'OS', 'Sistem Operasi (OS)', 'text', 0, 1, 4],
            ['NBK', 'GPU', 'Kartu Grafis (GPU/VGA)', 'text', 0, 1, 5],
            ['SRV', 'CPU', 'Processor (CPU)', 'text', 0, 1, 1],
            ['SRV', 'RAM', 'RAM / Memori', 'text', 0, 1, 2],
            ['SRV', 'STORAGE', 'Storage / RAID', 'text', 0, 1, 3],
            ['SRV', 'OS', 'Sistem Operasi (OS)', 'text', 0, 1, 4],
            ['PRT', 'PRT_TYPE', 'Tipe Printer (Inkjet/Laser)', 'text', 0, 1, 1],
            ['PRT', 'PRT_FEAT', 'Fitur (Print/Scan/Copy)', 'text', 0, 1, 2],
            ['CAR', 'CC', 'Kapasitas Mesin (CC)', 'number', 0, 1, 1],
            ['CAR', 'FUEL', 'Bahan Bakar', 'text', 0, 1, 2],
            ['CAR', 'YEAR', 'Tahun Pembuatan', 'number', 0, 1, 3],
            ['MTR', 'CC', 'Kapasitas Mesin (CC)', 'number', 0, 1, 1],
            ['MTR', 'YEAR', 'Tahun Pembuatan', 'number', 0, 1, 2],
        ];
        foreach ($defaultSpecs as [$type, $code, $name, $dataType, $required, $searchable, $order]) {
            try {
                $sSp = $pdo->prepare('INSERT IGNORE INTO asset_type_specifications(asset_type_id,specification_code,specification_name,data_type,is_required,is_searchable,display_order) SELECT id,?,?,?,?,?,? FROM asset_types WHERE type_code=?');
                $sSp->execute([$code, $name, $dataType, $required, $searchable, $order, $type]);
            } catch (Throwable $ignored) {}
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_locations (id INT AUTO_INCREMENT PRIMARY KEY, location_code VARCHAR(30) NOT NULL UNIQUE, location_name VARCHAR(120) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([['HO','Head Office'],['WHS','Gudang / Warehouse'],['PROD','Area Produksi'],['IT','Ruang IT / Server']] as [$locCode,$locName]) {
            $s = $pdo->prepare('INSERT IGNORE INTO asset_locations(location_code,location_name) VALUES (?,?)');
            $s->execute([$locCode,$locName]);
        }
        foreach (['asset_group_id INT NULL','asset_type_id INT NULL','asset_status_id INT NULL','location_id INT NULL'] as $column) {
            $name = strtok($column,' ');
            if (!db_column_exists($pdo,'asset_items',$name)) {
                $pdo->exec('ALTER TABLE asset_items ADD COLUMN '.$column);
            }
        }
        if (!db_column_exists($pdo, 'asset_items', 'custodian_name')) {
            $pdo->exec('ALTER TABLE asset_items ADD COLUMN custodian_name VARCHAR(160) NULL');
        }
    } catch (Throwable $ignored) {
    }
}

function repair_asset_type_groups(PDO $pdo): void
{
    try {
        if (!db_table_exists($pdo, 'asset_groups') || !db_table_exists($pdo, 'asset_types')) {
            return;
        }

        // 1. Ensure asset_groups IT, VH, FC exist
        $itId = (int)$pdo->query("SELECT id FROM asset_groups WHERE UPPER(TRIM(group_code))='IT' OR group_name LIKE '%IT%' ORDER BY id ASC LIMIT 1")->fetchColumn();
        $vhId = (int)$pdo->query("SELECT id FROM asset_groups WHERE UPPER(TRIM(group_code)) IN ('VH','VEHICLE') OR group_name LIKE '%Vehicle%' OR group_name LIKE '%Kendaraan%' OR group_name LIKE '%Mobil%' ORDER BY id ASC LIMIT 1")->fetchColumn();
        $fcId = (int)$pdo->query("SELECT id FROM asset_groups WHERE UPPER(TRIM(group_code)) IN ('FC','FACILITY') OR group_name LIKE '%Facility%' OR group_name LIKE '%Fasilitas%' OR group_name LIKE '%Gedung%' ORDER BY id ASC LIMIT 1")->fetchColumn();

        if ($itId <= 0) {
            $pdo->exec("INSERT INTO asset_groups (group_code, group_name, is_active) VALUES ('IT', 'IT Aset', 1)");
            $itId = (int)$pdo->lastInsertId();
        } else {
            $pdo->exec("UPDATE asset_groups SET group_name = 'IT Aset' WHERE id = {$itId} AND group_name = 'IT Asset'");
        }
        if ($vhId <= 0) {
            $pdo->exec("INSERT INTO asset_groups (group_code, group_name, is_active) VALUES ('VH', 'Vehicle', 1)");
            $vhId = (int)$pdo->lastInsertId();
        }
        if ($fcId <= 0) {
            $pdo->exec("INSERT INTO asset_groups (group_code, group_name, is_active) VALUES ('FC', 'Facility', 1)");
            $fcId = (int)$pdo->lastInsertId();
        }

        // 2. Safe unique index check on asset_types: drop old single type_code unique constraint if exists
        try {
            $indexes = $pdo->query("SHOW INDEX FROM asset_types")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($indexes as $idx) {
                $kName = (string)($idx['Key_name'] ?? '');
                $cName = (string)($idx['Column_name'] ?? '');
                $nonUnique = (int)($idx['Non_unique'] ?? 1);
                if ($nonUnique === 0 && $kName !== 'PRIMARY' && $kName !== 'uq_asset_type_group_code' && $cName === 'type_code') {
                    $pdo->exec("ALTER TABLE asset_types DROP INDEX `{$kName}`");
                }
            }
        } catch (Throwable $ignored) {}

        try {
            $pdo->exec("ALTER TABLE asset_types ADD UNIQUE KEY uq_asset_type_group_code (asset_group_id, type_code)");
        } catch (Throwable $ignored) {}

        // 3. Ensure is_active is 1 for all asset_types
        try {
            $pdo->exec("UPDATE asset_types SET is_active = 1 WHERE is_active IS NULL OR is_active = 0");
        } catch (Throwable $ignored) {}

        // 4. Align existing asset_types to proper asset_group_id
        $types = $pdo->query("SELECT id, type_code, type_name, asset_group_id FROM asset_types")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $pdo->prepare("UPDATE asset_types SET asset_group_id = ? WHERE id = ?");

        foreach ($types as $t) {
            $id = (int)$t['id'];
            $code = strtoupper(trim((string)$t['type_code']));
            $name = strtolower(trim((string)$t['type_name']));
            $currGroup = (int)$t['asset_group_id'];

            $targetGroup = $currGroup;
            if (in_array($code, ['CAR', 'MTR', 'TRK', 'VH', 'VEHICLE', 'MOBIL', 'MOTOR', 'TRUK']) || preg_match('/(car|motor|truck|truk|mobil|kendaraan|sepeda)/i', $name)) {
                $targetGroup = $vhId;
            } elseif (in_array($code, ['AC', 'BLD', 'GEN', 'FC', 'FACILITY', 'FASILITAS', 'GEDUNG', 'GENSET']) || preg_match('/(facility|fasilitas|ac|pendingin|gedung|bangunan|generator|genset|ruang|tanah)/i', $name)) {
                $targetGroup = $fcId;
            } elseif (in_array($code, ['CMP', 'NBK', 'PRT', 'SRV', 'IT', 'PC', 'LAPTOP', 'KOMPUTER', 'SERVER', 'MONITOR', 'SWITCH', 'ROUTER', 'UPS', 'NETWORK']) || preg_match('/(computer|komputer|notebook|laptop|printer|server|pc|network|jaringan|monitor|ups|scanner)/i', $name)) {
                $targetGroup = $itId;
            } elseif ($currGroup <= 0) {
                $targetGroup = $itId;
            }

            if ($targetGroup > 0 && $targetGroup !== $currGroup) {
                try {
                    $upd->execute([$targetGroup, $id]);
                } catch (Throwable $ignored) {}
            }
        }

        // 5. Ensure default standard categories exist for each group
        $defaults = [
            [$itId, 'CMP', 'Computer'],
            [$itId, 'NBK', 'Notebook'],
            [$itId, 'PRT', 'Printer'],
            [$itId, 'SRV', 'Server'],
            [$vhId, 'CAR', 'Car / Mobil'],
            [$vhId, 'MTR', 'Motorcycle / Motor'],
            [$vhId, 'TRK', 'Truck / Truk'],
            [$fcId, 'AC', 'AC / Pendingin'],
            [$fcId, 'BLD', 'Gedung / Bangunan'],
            [$fcId, 'GEN', 'Generator / Genset'],
            [$fcId, 'FC', 'Fasilitas Umum'],
        ];

        foreach ($defaults as [$gId, $tCode, $tName]) {
            if ($gId <= 0) continue;
            $existingId = (int)$pdo->query("SELECT id FROM asset_types WHERE type_code = " . $pdo->quote($tCode) . " LIMIT 1")->fetchColumn();
            if ($existingId > 0) {
                try {
                    $pdo->prepare("UPDATE asset_types SET asset_group_id = ?, type_name = ?, is_active = 1 WHERE id = ?")->execute([$gId, $tName, $existingId]);
                } catch (Throwable $ignored) {}
            } else {
                try {
                    $pdo->prepare("INSERT INTO asset_types (asset_group_id, type_code, type_name, is_active) VALUES (?, ?, ?, 1)")->execute([$gId, $tCode, $tName]);
                } catch (Throwable $ignored) {}
            }
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_maintenance_asset_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_assets (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_asset_items (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!db_column_exists($pdo, 'pcs', 'maintenance_asset_id')) {
            $pdo->exec('ALTER TABLE pcs ADD COLUMN maintenance_asset_id BIGINT NULL AFTER asset_bundle_id');
        }
        if (db_table_exists($pdo, 'printers') && !db_column_exists($pdo, 'printers', 'maintenance_asset_id')) {
            $pdo->exec('ALTER TABLE printers ADD COLUMN maintenance_asset_id BIGINT NULL AFTER model_printer');
        }
        if (db_table_exists($pdo, 'printers') && !db_column_exists($pdo, 'printers', 'asset_item_id')) {
            $pdo->exec('ALTER TABLE printers ADD COLUMN asset_item_id BIGINT NULL AFTER maintenance_asset_id');
        }
        if (!db_table_exists($pdo, 'maintenance_categories')) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS maintenance_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(160) NOT NULL,
                description TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        if (!db_column_exists($pdo, 'maintenance_jobs', 'maintenance_category_id')) {
            $pdo->exec('ALTER TABLE maintenance_jobs ADD COLUMN maintenance_category_id INT NULL AFTER description');
        }
        if (!db_column_exists($pdo, 'maintenance_schedules', 'maintenance_category_id')) {
            $pdo->exec('ALTER TABLE maintenance_schedules ADD COLUMN maintenance_category_id INT NULL AFTER maintenance_asset_id');
        }
        if (!db_column_exists($pdo, 'maintenance_assets', 'asset_group_id')) {
            $pdo->exec('ALTER TABLE maintenance_assets ADD COLUMN asset_group_id INT NULL AFTER maintenance_type');
            try {
                $pdo->exec('ALTER TABLE maintenance_assets ADD INDEX idx_maint_asset_group (asset_group_id)');
            } catch (Throwable $ignored) {}
        }
        if (!db_column_exists($pdo, 'maintenance_assets', 'asset_type_id')) {
            $pdo->exec('ALTER TABLE maintenance_assets ADD COLUMN asset_type_id INT NULL AFTER asset_group_id');
            try {
                $pdo->exec('ALTER TABLE maintenance_assets ADD INDEX idx_maint_asset_type (asset_type_id)');
            } catch (Throwable $ignored) {}
        }
        if (!db_column_exists($pdo, 'maintenance_assets', 'job_desk_name')) {
            $pdo->exec('ALTER TABLE maintenance_assets ADD COLUMN job_desk_name VARCHAR(160) NULL AFTER asset_type_id');
        }
        if (!db_column_exists($pdo, 'maintenance_assets', 'asset_item_id')) {
            $pdo->exec('ALTER TABLE maintenance_assets ADD COLUMN asset_item_id BIGINT NULL AFTER asset_type_id');
            try {
                $pdo->exec('ALTER TABLE maintenance_assets ADD INDEX idx_maint_asset_item (asset_item_id)');
            } catch (Throwable $ignored) {}
        }
        if (db_table_exists($pdo, 'asset_items') && !db_column_exists($pdo, 'asset_items', 'source_pc_id')) {
            $pdo->exec('ALTER TABLE asset_items ADD COLUMN source_pc_id VARCHAR(80) NULL AFTER model');
            try {
                $pdo->exec('ALTER TABLE asset_items ADD INDEX idx_ai_source_pc (source_pc_id)');
            } catch (Throwable $ignored) {}
        }
        try {
            if (db_table_exists($pdo, 'pcs')) {
                $pdo->exec("UPDATE maintenance_assets ma JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci SET ma.asset_item_id = p.asset_item_id WHERE ma.asset_item_id IS NULL AND p.asset_item_id IS NOT NULL AND p.asset_item_id > 0");
                $pdo->exec("UPDATE asset_items ai JOIN pcs p ON p.asset_item_id = ai.id SET ai.source_pc_id = p.pc_id WHERE (ai.source_pc_id IS NULL OR ai.source_pc_id = '') AND p.pc_id IS NOT NULL AND p.pc_id != ''");
                $pdo->exec("UPDATE pcs p JOIN asset_items ai ON ai.source_pc_id COLLATE utf8mb4_unicode_ci = p.pc_id COLLATE utf8mb4_unicode_ci SET p.asset_item_id = ai.id WHERE p.asset_item_id IS NULL");
                // Sync custodian between pcs and asset_items
                $pdo->exec("UPDATE asset_items ai JOIN pcs p ON (p.asset_item_id = ai.id OR p.pc_id COLLATE utf8mb4_unicode_ci = ai.source_pc_id COLLATE utf8mb4_unicode_ci) SET ai.custodian_name = p.owner_name WHERE (ai.custodian_name IS NULL OR ai.custodian_name = '') AND p.owner_name IS NOT NULL AND p.owner_name != ''");
                $pdo->exec("UPDATE asset_items ai JOIN pcs p ON (p.asset_item_id = ai.id OR p.pc_id COLLATE utf8mb4_unicode_ci = ai.source_pc_id COLLATE utf8mb4_unicode_ci) SET ai.custodian_nik = p.employee_nik WHERE (ai.custodian_nik IS NULL OR ai.custodian_nik = '') AND p.employee_nik IS NOT NULL AND p.employee_nik != ''");
                $pdo->exec("UPDATE pcs p JOIN asset_items ai ON (ai.id = p.asset_item_id OR ai.source_pc_id COLLATE utf8mb4_unicode_ci = p.pc_id COLLATE utf8mb4_unicode_ci) SET p.owner_name = ai.custodian_name WHERE (p.owner_name IS NULL OR p.owner_name = '') AND ai.custodian_name IS NOT NULL AND ai.custodian_name != ''");
                $pdo->exec("UPDATE pcs p JOIN asset_items ai ON (ai.id = p.asset_item_id OR ai.source_pc_id COLLATE utf8mb4_unicode_ci = p.pc_id COLLATE utf8mb4_unicode_ci) SET p.employee_nik = ai.custodian_nik WHERE (p.employee_nik IS NULL OR p.employee_nik = '') AND ai.custodian_nik IS NOT NULL AND ai.custodian_nik != ''");
            }
            if (db_table_exists($pdo, 'printers')) {
                $pdo->exec("UPDATE maintenance_assets ma JOIN printers pr ON pr.prn_id COLLATE utf8mb4_unicode_ci = ma.printer_id COLLATE utf8mb4_unicode_ci SET ma.asset_item_id = pr.asset_item_id WHERE ma.asset_item_id IS NULL AND pr.asset_item_id IS NOT NULL AND pr.asset_item_id > 0");
            }
            $pdo->exec("UPDATE maintenance_assets ma JOIN maintenance_asset_items mai ON mai.maintenance_asset_id = ma.id AND mai.detached_at IS NULL SET ma.asset_item_id = mai.asset_item_id WHERE ma.asset_item_id IS NULL");
            // Sync custodian into maintenance_assets
            $pdo->exec("UPDATE maintenance_assets ma JOIN asset_items ai ON ai.id = ma.asset_item_id SET ma.owner_name = ai.custodian_name WHERE (ma.owner_name IS NULL OR ma.owner_name = '') AND ai.custodian_name IS NOT NULL AND ai.custodian_name != ''");
            $pdo->exec("UPDATE maintenance_assets ma JOIN asset_items ai ON ai.id = ma.asset_item_id SET ma.employee_nik = ai.custodian_nik WHERE (ma.employee_nik IS NULL OR ma.employee_nik = '') AND ai.custodian_nik IS NOT NULL AND ai.custodian_nik != ''");
        } catch (Throwable $ignored) {}
        try {
            $itGroupId = (int)$pdo->query("SELECT id FROM asset_groups WHERE group_code='IT' LIMIT 1")->fetchColumn();
            if ($itGroupId > 0) {
                $pdo->exec("UPDATE maintenance_assets SET asset_group_id = {$itGroupId} WHERE asset_group_id IS NULL AND ((pc_id IS NOT NULL AND pc_id != '') OR (printer_id IS NOT NULL AND printer_id != ''))");
                // Auto link PC type to Computer
                $cmpTypeId = (int)$pdo->query("SELECT id FROM asset_types WHERE type_code='CMP' LIMIT 1")->fetchColumn();
                if ($cmpTypeId > 0) {
                    $pdo->exec("UPDATE maintenance_assets SET asset_type_id = {$cmpTypeId} WHERE asset_type_id IS NULL AND pc_id IS NOT NULL AND pc_id != ''");
                }
                // Auto link Printer type to Printer
                $prtTypeId = (int)$pdo->query("SELECT id FROM asset_types WHERE type_code='PRT' LIMIT 1")->fetchColumn();
                if ($prtTypeId > 0) {
                    $pdo->exec("UPDATE maintenance_assets SET asset_type_id = {$prtTypeId} WHERE asset_type_id IS NULL AND printer_id IS NOT NULL AND printer_id != ''");
                }
            }
        } catch (Throwable $ignored) {}
        try {
            $pdo->exec('ALTER TABLE pcs MODIFY pc_id VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo->exec('ALTER TABLE asset_items MODIFY source_pc_id VARCHAR(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
            $pdo->exec('ALTER TABLE maintenance_assets MODIFY pc_id VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
            $pdo->exec('ALTER TABLE maintenance_assets MODIFY printer_id VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');
            $pdo->exec('ALTER TABLE printers MODIFY prn_id VARCHAR(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable $ignored) {
        }

        // Jalankan pembersihan otomatis untuk PC maintenance asset yang belum tersinkronisasi
        cleanup_unsynced_pc_maintenance_assets($pdo);
    } catch (Throwable $ignored) {
    }
}

/**
 * Membersihkan data maintenance_assets yang terhubung ke PC namun PC-nya belum tersinkronisasi ke Asset Item.
 */
function cleanup_unsynced_pc_maintenance_assets(PDO $pdo): int
{
    try {
        if (!db_table_exists($pdo, 'maintenance_assets') || !db_table_exists($pdo, 'pcs')) {
            return 0;
        }
        $stmt = $pdo->query('
            SELECT ma.id 
            FROM maintenance_assets ma 
            LEFT JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci 
            WHERE ma.pc_id IS NOT NULL 
              AND ma.pc_id <> "" 
              AND (ma.printer_id IS NULL OR ma.printer_id = "") 
              AND (p.asset_item_id IS NULL OR p.asset_item_id = 0 OR p.pc_id IS NULL)
        ');
        $orphanIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$orphanIds) {
            return 0;
        }
        $inClause = implode(',', $orphanIds);

        if (db_table_exists($pdo, 'maintenance_schedules') && db_column_exists($pdo, 'maintenance_schedules', 'maintenance_asset_id')) {
            $pdo->exec("UPDATE maintenance_schedules SET maintenance_asset_id = NULL WHERE maintenance_asset_id IN ($inClause)");
        }
        if (db_table_exists($pdo, 'maintenance_asset_items')) {
            $pdo->exec("DELETE FROM maintenance_asset_items WHERE maintenance_asset_id IN ($inClause)");
        }
        $pdo->exec("DELETE FROM maintenance_assets WHERE id IN ($inClause)");
        $pdo->exec("UPDATE pcs SET maintenance_asset_id = NULL WHERE asset_item_id IS NULL OR asset_item_id = 0");
        return count($orphanIds);
    } catch (Throwable $ignored) {
        return 0;
    }
}

function ensure_photo_challenge_schema(PDO $pdo): void
{
    try {
        if (db_table_exists($pdo, 'maintenance_schedules')) {
            if (!db_column_exists($pdo, 'maintenance_schedules', 'photo_challenge_code')) {
                $pdo->exec('ALTER TABLE maintenance_schedules ADD COLUMN photo_challenge_code VARCHAR(12) NULL AFTER arrival_browser');
            }
            if (!db_column_exists($pdo, 'maintenance_schedules', 'photo_challenge_generated_at')) {
                $pdo->exec('ALTER TABLE maintenance_schedules ADD COLUMN photo_challenge_generated_at DATETIME NULL AFTER photo_challenge_code');
            }
        }
        if (db_table_exists($pdo, 'maintenance_reports')) {
            if (!db_column_exists($pdo, 'maintenance_reports', 'process_photos')) {
                $pdo->exec('ALTER TABLE maintenance_reports ADD COLUMN process_photos LONGTEXT NULL AFTER before_photos');
            }
            if (!db_column_exists($pdo, 'maintenance_reports', 'photo_challenge_code')) {
                $pdo->exec('ALTER TABLE maintenance_reports ADD COLUMN photo_challenge_code VARCHAR(12) NULL AFTER after_photos');
            }
            if (!db_column_exists($pdo, 'maintenance_reports', 'photo_audit_meta')) {
                $pdo->exec('ALTER TABLE maintenance_reports ADD COLUMN photo_audit_meta LONGTEXT NULL AFTER photo_challenge_code');
            }
            if (!db_column_exists($pdo, 'maintenance_reports', 'signature_name')) {
                $pdo->exec('ALTER TABLE maintenance_reports ADD COLUMN signature_name VARCHAR(160) NULL AFTER photo_audit_meta');
            }
            if (!db_column_exists($pdo, 'maintenance_reports', 'signature_path')) {
                $pdo->exec('ALTER TABLE maintenance_reports ADD COLUMN signature_path VARCHAR(255) NULL AFTER signature_name');
            }
            if (!db_column_exists($pdo, 'maintenance_reports', 'report_hash')) {
                $pdo->exec('ALTER TABLE maintenance_reports ADD COLUMN report_hash CHAR(64) NULL AFTER signature_path');
            }
            if (!db_column_exists($pdo, 'maintenance_reports', 'locked_at')) {
                $pdo->exec('ALTER TABLE maintenance_reports ADD COLUMN locked_at DATETIME NULL AFTER report_hash');
            }
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_job_estimate_schema(PDO $pdo): void
{
    try {
        if (db_table_exists($pdo, 'maintenance_jobs')) {
            if (!db_column_exists($pdo, 'maintenance_jobs', 'estimated_minutes')) {
                $pdo->exec('ALTER TABLE maintenance_jobs ADD COLUMN estimated_minutes INT NOT NULL DEFAULT 5 AFTER description');
            }
            if (!db_column_exists($pdo, 'maintenance_jobs', 'asset_group_id')) {
                $pdo->exec('ALTER TABLE maintenance_jobs ADD COLUMN asset_group_id INT NULL AFTER estimated_minutes');
            }
            if (!db_column_exists($pdo, 'maintenance_jobs', 'asset_type_id')) {
                $pdo->exec('ALTER TABLE maintenance_jobs ADD COLUMN asset_type_id INT NULL AFTER asset_group_id');
            }
            if (!db_column_exists($pdo, 'maintenance_jobs', 'job_desk_name')) {
                $pdo->exec('ALTER TABLE maintenance_jobs ADD COLUMN job_desk_name VARCHAR(160) NULL AFTER asset_type_id');
            }
        }
        if (db_table_exists($pdo, 'asset_repairs') && !db_column_exists($pdo, 'asset_repairs', 'selected_jobs')) {
            $pdo->exec('ALTER TABLE asset_repairs ADD COLUMN selected_jobs LONGTEXT NULL AFTER repair_action');
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_performance_indexes(PDO $pdo): void
{
    $indexes = [
        ['maintenance_schedules', 'idx_sched_date_status', '(`scheduled_date`, `status`)'],
        ['maintenance_schedules', 'idx_sched_tech_status', '(`technician_id`, `status`)'],
        ['maintenance_reports', 'idx_rep_sched_id', '(`schedule_id`)'],
        ['maintenance_timeline', 'idx_timeline_sched_event', '(`schedule_id`, `event_type`)'],
        ['pcs', 'idx_pcs_asset_item', '(`asset_item_id`)'],
        ['pcs', 'idx_pcs_maintenance_asset', '(`maintenance_asset_id`)'],
        ['printers', 'idx_prn_asset_item', '(`asset_item_id`)'],
        ['printers', 'idx_prn_maintenance_asset', '(`maintenance_asset_id`)'],
        ['asset_items', 'idx_ai_custodian_nik', '(`custodian_nik`)'],
        ['asset_items', 'idx_ai_company', '(`company_id`)'],
        ['maintenance_asset_items', 'idx_mai_item_maint', '(`asset_item_id`, `maintenance_asset_id`)'],
    ];

    foreach ($indexes as [$table, $indexName, $cols]) {
        try {
            if (db_table_exists($pdo, $table)) {
                $chk = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'")->fetch();
                if (!$chk) {
                    $pdo->exec("ALTER TABLE `$table` ADD INDEX `$indexName` $cols");
                }
            }
        } catch (Throwable $ignored) {
        }
    }
}

function ensure_app_schema(PDO $pdo, bool $force = false): void
{
    if (!$force && empty($_GET['force_schema'])) {
        $lockFile = sys_get_temp_dir() . '/pcconnect_schema_v10.lock';
        if (file_exists($lockFile) && (time() - filemtime($lockFile) < 1800) && db_table_exists($pdo, 'pcs')) {
            return;
        }
    }

    ensure_base_schema_from_sql($pdo);
    ensure_user_roles_schema($pdo);
    ensure_printer_schema($pdo);
    ensure_pc_location_schema($pdo);
    ensure_photo_challenge_schema($pdo);
    ensure_job_estimate_schema($pdo);
    ensure_employee_source_schema($pdo);
    ensure_company_source_schema($pdo);
    ensure_asset_management_schema($pdo);
    ensure_asset_master_schema($pdo);
    ensure_maintenance_asset_schema($pdo);
    cleanup_unsynced_pc_maintenance_assets($pdo);
    cleanup_and_repair_pc_asset_conflicts($pdo);
    ensure_corrective_maintenance_schema($pdo);
    ensure_performance_indexes($pdo);

    @touch(sys_get_temp_dir() . '/pcconnect_schema_v10.lock');
}

function ensure_user_roles_schema(PDO $pdo): void
{
    try {
        if (db_table_exists($pdo, 'users')) {
            $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'");
            $row = $stmt->fetch();
            $type = (string)($row['Type'] ?? '');
            if (!str_contains($type, 'corrective_maintenance')) {
                $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','maintenance_admin','technician','corrective_maintenance') NOT NULL DEFAULT 'technician'");
            }
        }
    } catch (Throwable $ignored) {
    }
}

function cleanup_and_repair_pc_asset_conflicts(PDO $pdo): void
{
    if (!db_table_exists($pdo, 'pcs') || !db_table_exists($pdo, 'asset_items')) {
        return;
    }

    try {
        // 1. Bersihkan duplikasi / sampah relasi maintenance_asset_items
        if (db_table_exists($pdo, 'maintenance_assets') && db_table_exists($pdo, 'maintenance_asset_items')) {
            $pdo->exec("DELETE mai FROM maintenance_asset_items mai 
                        JOIN maintenance_assets ma ON ma.id = mai.maintenance_asset_id 
                        JOIN pcs p ON p.pc_id COLLATE utf8mb4_unicode_ci = ma.pc_id COLLATE utf8mb4_unicode_ci 
                        WHERE p.asset_item_id IS NOT NULL AND p.asset_item_id > 0 AND mai.asset_item_id <> p.asset_item_id");
        }

        // 2. Koreksi konflik Vinca (PC000003) & Purnomo (PC000016 / IT-NBK-000002)
        $purnomoAssetStmt = $pdo->query("SELECT id, asset_code, custodian_nik, custodian_name FROM asset_items WHERE asset_code = 'IT-NBK-000002' OR (custodian_nik = '190301' AND asset_type LIKE '%Note%') LIMIT 1");
        $purnomoAsset = $purnomoAssetStmt ? $purnomoAssetStmt->fetch() : null;

        if ($purnomoAsset) {
            $purnomoAssetId = (int)$purnomoAsset['id'];

            // Hubungkan PC Purnomo ke IT-NBK-000002 jika belum
            $purnomoPcStmt = $pdo->query("SELECT pc_id, employee_nik, owner_name FROM pcs WHERE pc_id = 'PC000016' OR employee_nik = '190301' LIMIT 1");
            $purnomoPc = $purnomoPcStmt ? $purnomoPcStmt->fetch() : null;
            if ($purnomoPc) {
                $purnomoPcId = (string)$purnomoPc['pc_id'];
                $pdo->prepare("UPDATE pcs SET asset_item_id = ? WHERE pc_id = ?")->execute([$purnomoAssetId, $purnomoPcId]);
                if (function_exists('sync_pc_maintenance_asset')) {
                    sync_pc_maintenance_asset($pdo, $purnomoPcId);
                }
            }

            // Cek jika PC Vinca (PC000003) masih tertaut ke asset Purnomo
            $vincaPcStmt = $pdo->query("SELECT * FROM pcs WHERE pc_id = 'PC000003' OR employee_nik = '190801' LIMIT 1");
            $vincaPc = $vincaPcStmt ? $vincaPcStmt->fetch() : null;

            if ($vincaPc && (int)($vincaPc['asset_item_id'] ?? 0) === $purnomoAssetId) {
                $vincaPcId = (string)$vincaPc['pc_id'];

                // Cari apakah sudah ada asset khusus Vinca yang lain
                $vincaAssetStmt = $pdo->query("SELECT id FROM asset_items WHERE id <> $purnomoAssetId AND (custodian_nik = '190801' OR custodian_name LIKE '%VINCA%') LIMIT 1");
                $vincaAssetId = (int)($vincaAssetStmt ? $vincaAssetStmt->fetchColumn() : 0);

                if ($vincaAssetId <= 0) {
                    ensure_asset_master_schema($pdo);
                    $typeStmt = $pdo->query("SELECT id, type_code, type_name FROM asset_types WHERE type_code = 'CMP' AND is_active = 1 LIMIT 1");
                    $typeRow = $typeStmt ? $typeStmt->fetch() : null;
                    $typeId = (int)($typeRow['id'] ?? 1);
                    $c = asset_type_form_config($pdo, $typeId);
                    $statusId = (int)$pdo->query("SELECT id FROM asset_statuses WHERE status_code = 'ACTIVE' OR is_active = 1 ORDER BY id ASC LIMIT 1")->fetchColumn();
                    $code = generate_next_asset_code($pdo, (string)($c['type']['group_code'] ?? 'IT'), 'CMP');
                    $assetName = trim((string)($vincaPc['computer_name'] ?: $vincaPcId));

                    $pdo->prepare("INSERT INTO asset_items (asset_code, asset_group_id, asset_type_id, asset_status_id, asset_mode, asset_name, custodian_name, custodian_nik, company_id, location_label, asset_type, asset_category, status) VALUES (?, ?, ?, ?, 'standalone', ?, ?, '190801', ?, ?, 'Desktop Computer', 'Computer', 'active')")
                        ->execute([
                            $code,
                            (int)($c['type']['asset_group_id'] ?? 1),
                            $typeId,
                            $statusId ?: 1,
                            $assetName,
                            $vincaPc['owner_name'] ?: 'TAMBUNTA VINCA RUDANG MAYANG T',
                            (int)($vincaPc['company_id'] ?? 0) ?: null,
                            $vincaPc['location_label'] ?: null,
                        ]);
                    $vincaAssetId = (int)$pdo->lastInsertId();
                }

                // Tautkan PC Vinca ke asset-nya sendiri dan sinkronkan maintenance asset
                $pdo->prepare("UPDATE pcs SET asset_item_id = ? WHERE pc_id = ?")->execute([$vincaAssetId, $vincaPcId]);
                if (function_exists('sync_pc_maintenance_asset')) {
                    sync_pc_maintenance_asset($pdo, $vincaPcId);
                }
            }
        }

        // Sinkronkan tipe asset Vinca menjadi Notebook (NBK) dan perbarui kode aset menjadi IT-NBK-xxxxxx
        $nbkTypeStmt = $pdo->query("SELECT id, type_name FROM asset_types WHERE type_code = 'NBK' AND is_active = 1 LIMIT 1");
        $nbkType = $nbkTypeStmt ? $nbkTypeStmt->fetch() : null;
        if ($nbkType) {
            $vincaAssetStmt = $pdo->query("SELECT id, asset_code FROM asset_items WHERE custodian_nik = '190801' OR custodian_name LIKE '%VINCA%' LIMIT 1");
            $vRow = $vincaAssetStmt ? $vincaAssetStmt->fetch() : null;
            if ($vRow) {
                $vId = (int)$vRow['id'];
                $vCode = (string)$vRow['asset_code'];
                if (str_starts_with($vCode, 'IT-CMP-')) {
                    $newNbkCode = generate_next_asset_code($pdo, 'IT', 'NBK');
                    $pdo->prepare("UPDATE asset_items SET asset_code = ?, asset_type_id = ?, asset_type = ? WHERE id = ?")
                        ->execute([$newNbkCode, (int)$nbkType['id'], (string)$nbkType['type_name'], $vId]);
                } else {
                    $pdo->prepare("UPDATE asset_items SET asset_type_id = ?, asset_type = ? WHERE id = ?")
                        ->execute([(int)$nbkType['id'], (string)$nbkType['type_name'], $vId]);
                }
                if (function_exists('sync_pc_maintenance_asset')) {
                    sync_pc_maintenance_asset($pdo, 'PC000003');
                }
            }
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_corrective_maintenance_schema(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS corrective_tickets (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            ticket_code VARCHAR(40) NOT NULL UNIQUE,
            asset_category VARCHAR(60) NOT NULL DEFAULT 'IT',
            asset_item_id BIGINT NULL,
            maintenance_asset_id BIGINT NULL,
            pc_id VARCHAR(60) NULL,
            prn_id VARCHAR(60) NULL,
            company_id INT NULL,
            location_label VARCHAR(150) NULL,
            vehicle_odometer_km INT NULL,
            reporter_name VARCHAR(120) NOT NULL,
            reporter_nik VARCHAR(60) NULL,
            reporter_contact VARCHAR(100) NULL,
            issue_category VARCHAR(60) NOT NULL DEFAULT 'hardware',
            priority VARCHAR(20) NOT NULL DEFAULT 'medium',
            subject VARCHAR(200) NOT NULL,
            description TEXT NULL,
            photo_before VARCHAR(255) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            assigned_technician_name VARCHAR(120) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at DATETIME NULL,
            closed_at DATETIME NULL,
            INDEX idx_ct_status(status),
            INDEX idx_ct_asset(asset_item_id),
            INDEX idx_ct_pc(pc_id),
            INDEX idx_ct_created(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS corrective_repairs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            ticket_id BIGINT NOT NULL,
            technician_name VARCHAR(120) NOT NULL,
            action_type VARCHAR(60) NOT NULL DEFAULT 'hardware_repair',
            root_cause_analysis TEXT NULL,
            solution_details TEXT NOT NULL,
            vendor_name VARCHAR(150) NULL,
            vendor_invoice_no VARCHAR(100) NULL,
            repair_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
            photo_after VARCHAR(255) NULL,
            repaired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cr_ticket(ticket_id),
            INDEX idx_cr_date(repaired_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS corrective_repair_parts (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            repair_id BIGINT NOT NULL,
            old_part_name VARCHAR(150) NULL,
            old_part_serial VARCHAR(100) NULL,
            old_part_condition VARCHAR(100) NULL,
            new_part_name VARCHAR(150) NOT NULL,
            new_part_serial VARCHAR(100) NULL,
            part_cost DECIMAL(15,2) NOT NULL DEFAULT 0,
            part_warranty_until DATE NULL,
            vendor_supplier VARCHAR(150) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_crp_repair(repair_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_walkarounds (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            walkaround_code VARCHAR(40) NOT NULL UNIQUE,
            asset_item_id BIGINT NULL,
            maintenance_asset_id BIGINT NULL,
            pc_id VARCHAR(60) NULL,
            prn_id VARCHAR(60) NULL,
            inspector_name VARCHAR(120) NOT NULL,
            inspector_nik VARCHAR(60) NULL,
            inspection_date DATE NOT NULL,
            location_label VARCHAR(150) NULL,
            odometer_km INT NULL,
            overall_condition VARCHAR(30) NOT NULL DEFAULT 'good',
            notes TEXT NULL,
            photo VARCHAR(255) NULL,
            ticket_id BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_aw_asset(asset_item_id),
            INDEX idx_aw_date(inspection_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS corrective_job_desks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_desk_code VARCHAR(40) NULL,
            job_desk_name VARCHAR(160) NOT NULL UNIQUE,
            asset_group_id INT NULL,
            asset_type_id INT NULL,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cjd_group (asset_group_id),
            INDEX idx_cjd_type (asset_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS corrective_action_types (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            action_code VARCHAR(60) NOT NULL UNIQUE,
            action_name VARCHAR(120) NOT NULL,
            job_desk_name VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
            asset_group_id INT NULL,
            asset_type_id INT NULL,
            estimated_minutes INT NOT NULL DEFAULT 15,
            description VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cat_active(is_active),
            INDEX idx_cat_order(sort_order),
            INDEX idx_cat_desk(job_desk_name),
            INDEX idx_cat_group(asset_group_id),
            INDEX idx_cat_type(asset_type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Pastikan kolom baru ada jika tabel corrective_action_types sudah ada sebelumnya
        foreach ([
            ['job_desk_name', 'VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER action_name'],
            ['asset_group_id', 'INT NULL AFTER job_desk_name'],
            ['asset_type_id', 'INT NULL AFTER asset_group_id'],
            ['estimated_minutes', 'INT NOT NULL DEFAULT 15 AFTER asset_type_id'],
        ] as [$col, $colDef]) {
            if (!db_column_exists($pdo, 'corrective_action_types', $col)) {
                try {
                    $pdo->exec("ALTER TABLE corrective_action_types ADD COLUMN {$col} {$colDef}");
                } catch (Throwable $ignored) {}
            }
        }

        // Ambil atau siapkan Asset Group IT dan Asset Type CMP (Computer)
        $itGroupId = null;
        $cmpTypeId = null;
        try {
            $gStmt = $pdo->query("SELECT id FROM asset_groups WHERE group_code = 'IT' OR group_name LIKE '%IT%' ORDER BY id ASC LIMIT 1");
            $itGroupId = $gStmt ? $gStmt->fetchColumn() : null;
            if ($itGroupId) {
                $tStmt = $pdo->prepare("SELECT id FROM asset_types WHERE asset_group_id = ? AND (type_code = 'CMP' OR type_name LIKE '%Comp%') ORDER BY id ASC LIMIT 1");
                $tStmt->execute([$itGroupId]);
                $cmpTypeId = $tStmt->fetchColumn() ?: null;
            }
        } catch (Throwable $ignored) {}

        // Seed default master Job Desk & Action Types HANYA JIKA kedua tabel masih kosong sama sekali (fresh install)
        $deskCount = (int)$pdo->query("SELECT COUNT(*) FROM corrective_job_desks")->fetchColumn();
        $actionCount = (int)$pdo->query("SELECT COUNT(*) FROM corrective_action_types")->fetchColumn();

        if ($deskCount === 0 && $actionCount === 0) {
            $defaultDeskName = 'Job Desk Corrective IT Comp';
            try {
                $insDesk = $pdo->prepare("INSERT INTO corrective_job_desks (job_desk_name, asset_group_id, asset_type_id, description) VALUES (?, ?, ?, ?)");
                $insDesk->execute([$defaultDeskName, $itGroupId ?: null, $cmpTypeId ?: null, 'Daftar jenis tindakan / job desk corrective maintenance standar perangkat Computer (IT Comp)']);
            } catch (Throwable $ignored) {}

            $defaultActionTypes = [
                ['hardware_repair', 'Reparasi / Service Hardware', 'Perbaikan fisik perangkat keras / komponen', 10],
                ['part_replacement', 'Penggantian Sparepart / Komponen', 'Penggantian komponen atau modul yang rusak dengan yang baru', 20],
                ['software_fix', 'Troubleshooting Software / OS / Driver', 'Penyelesaian masalah sistem operasi, aplikasi, konfigurasi, atau driver', 30],
                ['vendor_service', 'Service Bengkel / Vendor Pihak Ketiga', 'Pengerjaan perbaikan yang didelegasikan ke vendor / rekanan luar', 40],
                ['general_fix', 'Pembersihan / Penyetelan / Lainnya', 'Pembersihan fisik, kalibrasi, tune up, atau tindakan umum lainnya', 50],
                ['walkaround_fix', 'Tindakan Patroli / Walk Around', 'Tindakan temuan perbaikan langsung saat inspeksi lapangan', 60],
            ];
            $stmt = $pdo->prepare("INSERT INTO corrective_action_types (action_code, action_name, job_desk_name, asset_group_id, asset_type_id, description, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($defaultActionTypes as $at) {
                $stmt->execute([$at[0], $at[1], $defaultDeskName, $itGroupId ?: null, $cmpTypeId ?: null, $at[2], $at[3]]);
            }
        }
    } catch (Throwable $ignored) {
    }
}

function ensure_brand_and_master_item_schema(PDO $pdo): void
{
    try {
        // 1. Tabel Master Brand / Merk
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_brands (
            id INT AUTO_INCREMENT PRIMARY KEY,
            brand_code VARCHAR(40) NULL,
            brand_name VARCHAR(120) NOT NULL UNIQUE,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ab_active(is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 2. Tabel Master Barang / Katalog Model
        $pdo->exec("CREATE TABLE IF NOT EXISTS asset_master_items (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            item_code VARCHAR(80) NULL UNIQUE,
            item_name VARCHAR(180) NOT NULL,
            asset_group_id INT NOT NULL,
            asset_type_id INT NOT NULL,
            brand_id INT NULL,
            model_name VARCHAR(160) NULL,
            specifications TEXT NULL,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ami_group(asset_group_id),
            INDEX idx_ami_type(asset_type_id),
            INDEX idx_ami_brand(brand_id),
            INDEX idx_ami_active(is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 3. Kolom master_item_id dan brand_id pada asset_items
        if (db_table_exists($pdo, 'asset_items')) {
            if (!db_column_exists($pdo, 'asset_items', 'master_item_id')) {
                try {
                    $pdo->exec("ALTER TABLE asset_items ADD COLUMN master_item_id BIGINT NULL AFTER asset_code");
                    $pdo->exec("ALTER TABLE asset_items ADD INDEX idx_ai_master_item (master_item_id)");
                } catch (Throwable $ignored) {}
            }
            if (!db_column_exists($pdo, 'asset_items', 'brand_id')) {
                try {
                    $pdo->exec("ALTER TABLE asset_items ADD COLUMN brand_id INT NULL AFTER master_item_id");
                    $pdo->exec("ALTER TABLE asset_items ADD INDEX idx_ai_brand (brand_id)");
                } catch (Throwable $ignored) {}
            }
        }

        // 4. Seed default Brands jika tabel brand masih kosong
        $brandCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_brands")->fetchColumn();
        if ($brandCount === 0) {
            $defaultBrands = [
                'Lenovo', 'Dell', 'HP', 'Asus', 'Acer', 'Apple', 'Samsung', 'LG',
                'Brother', 'Canon', 'Epson', 'Toshiba', 'Cisco', 'MikroTik', 'APC',
                'Toyota', 'Honda', 'Daihatsu', 'Mitsubishi', 'Suzuki', 'Isuzu',
                'Panasonic', 'Daikin', 'Sharp', 'Yamaha', 'Generic / OEM'
            ];
            $stmtB = $pdo->prepare("INSERT IGNORE INTO asset_brands (brand_name, is_active) VALUES (?, 1)");
            foreach ($defaultBrands as $bName) {
                $stmtB->execute([$bName]);
            }
        }

        // 5. Ekstrak brand yang sudah ada di asset_items/pcs/printers jika ada yang belum terdaftar di asset_brands
        if (db_table_exists($pdo, 'asset_items')) {
            $existingBrandNames = $pdo->query("SELECT DISTINCT TRIM(brand) AS bname FROM asset_items WHERE brand IS NOT NULL AND TRIM(brand) != ''")->fetchAll(PDO::FETCH_COLUMN);
            $stmtInsB = $pdo->prepare("INSERT IGNORE INTO asset_brands (brand_name, is_active) VALUES (?, 1)");
            foreach ($existingBrandNames as $eb) {
                if ($eb !== '') {
                    $stmtInsB->execute([$eb]);
                }
            }

            // Hubungkan brand_id pada asset_items jika masih null
            $brandRows = $pdo->query("SELECT id, brand_name FROM asset_brands")->fetchAll(PDO::FETCH_ASSOC);
            if ($brandRows) {
                $updAiBrand = $pdo->prepare("UPDATE asset_items SET brand_id = ? WHERE (brand_id IS NULL OR brand_id = 0) AND (TRIM(brand) = ? OR UPPER(TRIM(brand)) = UPPER(?))");
                foreach ($brandRows as $br) {
                    $updAiBrand->execute([(int)$br['id'], trim((string)$br['brand_name']), trim((string)$br['brand_name'])]);
                }
            }
        }

        // 6. Auto-migrate / auto-seed master barang dari data asset_items yang sudah ada
        if (db_table_exists($pdo, 'asset_items') && db_table_exists($pdo, 'asset_master_items')) {
            $unlinkedItems = $pdo->query("SELECT id, asset_group_id, asset_type_id, brand_id, brand, model, asset_name, asset_code FROM asset_items WHERE (master_item_id IS NULL OR master_item_id = 0) AND (asset_group_id IS NOT NULL OR asset_type_id IS NOT NULL)")->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($unlinkedItems)) {
                $findMasterStmt = $pdo->prepare("SELECT id FROM asset_master_items WHERE asset_group_id = ? AND asset_type_id = ? AND ((brand_id = ? AND brand_id IS NOT NULL) OR (brand_id IS NULL AND ? IS NULL)) AND model_name = ? LIMIT 1");
                $insMasterStmt = $pdo->prepare("INSERT INTO asset_master_items (item_code, item_name, asset_group_id, asset_type_id, brand_id, model_name, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                $linkAiStmt = $pdo->prepare("UPDATE asset_items SET master_item_id = ? WHERE id = ?");

                foreach ($unlinkedItems as $u) {
                    $gId = (int)($u['asset_group_id'] ?? 0);
                    $tId = (int)($u['asset_type_id'] ?? 0);
                    if ($gId <= 0 || $tId <= 0) {
                        continue;
                    }
                    $bId = (int)($u['brand_id'] ?? 0) ?: null;
                    $mName = trim((string)($u['model'] ?? ''));
                    $aName = trim((string)($u['asset_name'] ?? ''));
                    if ($mName === '' && $aName !== '') {
                        $mName = $aName;
                    }
                    if ($mName === '') {
                        $mName = 'Standard Unit';
                    }

                    $findMasterStmt->execute([$gId, $tId, $bId, $bId, $mName]);
                    $masterId = (int)($findMasterStmt->fetchColumn() ?: 0);

                    if ($masterId <= 0) {
                        $brandStr = trim((string)($u['brand'] ?? ''));
                        $fullName = trim($brandStr . ' ' . $mName);
                        if ($fullName === '') {
                            $fullName = $aName ?: 'Master Barang ' . $gId . '-' . $tId;
                        }
                        $itemCode = 'ITM-' . strtoupper(substr(md5($fullName . $gId . $tId . uniqid('', true)), 0, 8));
                        $insMasterStmt->execute([$itemCode, $fullName, $gId, $tId, $bId, $mName]);
                        $masterId = (int)$pdo->lastInsertId();
                    }

                    if ($masterId > 0) {
                        $linkAiStmt->execute([$masterId, (int)$u['id']]);
                    }
                }
            }
        }

        // 7. Seed default Master Barang templates jika tabel masih kosong
        $masterCount = (int)$pdo->query("SELECT COUNT(*) FROM asset_master_items")->fetchColumn();
        if ($masterCount === 0) {
            $defaultMasters = [
                ['IT', 'CMP', 'Dell', 'OptiPlex 3080', 'PC Desktop Dell OptiPlex 3080 Core i5'],
                ['IT', 'CMP', 'Lenovo', 'ThinkCentre M720q', 'PC Mini Lenovo ThinkCentre M720q'],
                ['IT', 'NBK', 'Lenovo', 'ThinkPad T480', 'Laptop Lenovo ThinkPad T480 Core i5'],
                ['IT', 'NBK', 'Dell', 'Latitude 5420', 'Laptop Dell Latitude 5420 Core i7'],
                ['IT', 'NBK', 'Asus', 'VivoBook 14', 'Laptop ASUS VivoBook 14 Core i3'],
                ['IT', 'PRT', 'HP', 'LaserJet Pro MFP M428fdw', 'Printer HP LaserJet Pro MFP M428fdw'],
                ['IT', 'PRT', 'Epson', 'L3210 EcoTank', 'Printer Epson L3210 All-in-One'],
                ['IT', 'SRV', 'Dell', 'PowerEdge R740', 'Server Dell PowerEdge R740 2U'],
                ['VH', 'CAR', 'Toyota', 'Avanza 1.3 G', 'Mobil Toyota Avanza 1.3 G M/T'],
                ['VH', 'CAR', 'Daihatsu', 'Gran Max Blind Van', 'Mobil Operasional Daihatsu Gran Max'],
                ['VH', 'MTR', 'Honda', 'Vario 160', 'Motor Honda Vario 160 CBS'],
                ['VH', 'MTR', 'Yamaha', 'NMAX 155', 'Motor Yamaha NMAX 155 Connected'],
            ];
            $stmtInsM = $pdo->prepare("INSERT INTO asset_master_items (item_code, item_name, asset_group_id, asset_type_id, brand_id, model_name, is_active) 
                SELECT ?, ?, g.id, t.id, b.id, ?, 1 
                FROM asset_groups g 
                JOIN asset_types t ON t.asset_group_id = g.id AND t.type_code = ? 
                LEFT JOIN asset_brands b ON b.brand_name = ? 
                WHERE g.group_code = ? 
                LIMIT 1");
            $seq = 1;
            foreach ($defaultMasters as [$gCode, $tCode, $bName, $mName, $iName]) {
                $code = 'ITM-' . $gCode . '-' . $tCode . '-' . str_pad((string)$seq++, 3, '0', STR_PAD_LEFT);
                $stmtInsM->execute([$code, $iName, $mName, $tCode, $bName, $gCode]);
            }
        }
    } catch (Throwable $ignored) {
    }
}
