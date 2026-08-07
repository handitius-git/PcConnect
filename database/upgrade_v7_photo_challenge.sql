ALTER TABLE maintenance_schedules
    ADD COLUMN photo_challenge_code VARCHAR(12) NULL AFTER arrival_browser,
    ADD COLUMN photo_challenge_generated_at DATETIME NULL AFTER photo_challenge_code;

ALTER TABLE maintenance_reports
    ADD COLUMN photo_challenge_code VARCHAR(12) NULL AFTER after_photos;
