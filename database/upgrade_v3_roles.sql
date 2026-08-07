ALTER TABLE users
    MODIFY role ENUM('admin','maintenance_admin','technician') NOT NULL DEFAULT 'technician';
