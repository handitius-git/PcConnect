ALTER TABLE pcs
    ADD COLUMN security_code VARCHAR(12) NULL AFTER pc_id;

UPDATE pcs
SET security_code = UPPER(CONCAT(CHAR(65 + (CRC32(pc_id) % 26)), (CRC32(CONCAT(pc_id, '-pcconnect')) % 10)))
WHERE security_code IS NULL OR security_code = '';

ALTER TABLE pcs
    MODIFY security_code VARCHAR(12) NOT NULL;

ALTER TABLE maintenance_schedules
    MODIFY status ENUM('scheduled','done','validated','in_progress','completed','reopened') NOT NULL DEFAULT 'scheduled';

UPDATE maintenance_schedules SET status='completed' WHERE status='done';

ALTER TABLE maintenance_schedules
    MODIFY status ENUM('scheduled','validated','in_progress','completed','reopened') NOT NULL DEFAULT 'scheduled',
    ADD COLUMN arrival_at DATETIME NULL AFTER notes,
    ADD COLUMN arrival_ip VARCHAR(80) NULL AFTER arrival_at,
    ADD COLUMN arrival_user_agent TEXT NULL AFTER arrival_ip,
    ADD COLUMN arrival_lat DECIMAL(10,7) NULL AFTER arrival_user_agent,
    ADD COLUMN arrival_lng DECIMAL(10,7) NULL AFTER arrival_lat,
    ADD COLUMN arrival_browser VARCHAR(180) NULL AFTER arrival_lng,
    ADD COLUMN completed_at DATETIME NULL AFTER arrival_browser,
    ADD COLUMN locked_at DATETIME NULL AFTER completed_at,
    ADD COLUMN unlocked_at DATETIME NULL AFTER locked_at,
    ADD COLUMN unlocked_by INT NULL AFTER unlocked_at,
    ADD COLUMN unlock_reason TEXT NULL AFTER unlocked_by;

ALTER TABLE maintenance_reports
    ADD COLUMN condition_rating ENUM('Excellent','Good','Fair','Need Repair','Need Replace') NULL AFTER technician_id,
    ADD COLUMN before_photos LONGTEXT NULL AFTER additional_notes,
    ADD COLUMN after_photos LONGTEXT NULL AFTER before_photos,
    ADD COLUMN signature_name VARCHAR(160) NULL AFTER after_photos,
    ADD COLUMN signature_path VARCHAR(255) NULL AFTER signature_name,
    ADD COLUMN report_hash CHAR(64) NULL AFTER signature_path,
    ADD COLUMN locked_at DATETIME NULL AFTER report_hash;

CREATE TABLE IF NOT EXISTS maintenance_timeline (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    schedule_id BIGINT NOT NULL,
    event_type VARCHAR(60) NOT NULL,
    event_note TEXT NULL,
    actor_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_timeline_schedule (schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE schedule_jobs
    ADD COLUMN IF NOT EXISTS note TEXT NULL AFTER done_at;

CREATE TABLE IF NOT EXISTS mobile_api_tokens (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    device_name VARCHAR(160) NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mobile_token_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
