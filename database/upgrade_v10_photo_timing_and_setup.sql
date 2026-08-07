ALTER TABLE maintenance_jobs
    ADD COLUMN estimated_minutes INT NOT NULL DEFAULT 5 AFTER description;
