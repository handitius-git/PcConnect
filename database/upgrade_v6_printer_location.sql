ALTER TABLE printers
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER location,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
    ADD COLUMN location_radius_m INT NOT NULL DEFAULT 5 AFTER longitude;
