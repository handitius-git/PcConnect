ALTER TABLE pcs
    ADD COLUMN location_label VARCHAR(180) NULL AFTER computer_name,
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER location_label,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
    ADD COLUMN location_radius_m INT NOT NULL DEFAULT 5 AFTER longitude;
