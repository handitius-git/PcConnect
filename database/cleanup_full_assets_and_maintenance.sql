-- ============================================================================
-- SQL SCRIPT: CLEANUP ASSET ITEMS, MASTER BARANG, MAINTENANCE ASSETS & HISTORI
-- ============================================================================
-- PENTING:
-- 1. Backup database sebelum menjalankan query ini!
-- 2. Query ini menghapus:
--    - Seluruh Unit Asset Fisik (asset_items & tabel anaknya)
--    - Seluruh Master Barang / Katalog SKU (asset_master_items)
--    - Seluruh Maintenance Asset (maintenance_assets & maintenance_asset_items)
--    - Seluruh Schedule & Histori Maintenance (preventive & corrective tickets/repairs)
-- 3. Data yang TETAP DIPERTAHANKAN (TIDAK DIHAPUS):
--    - Data PC (tabel pcs) & Analisa PC (analysis_runs)
--    - Data Printer (tabel printers)
--    - Data User / Karyawan (users, employee_directory)
--    - Master Komoditas (asset_groups) & Master Kategori (asset_types)
--    - Master Brand / Merk (asset_brands) & Status Asset (asset_statuses)
--    - Master Job Desk Preventive & Corrective Maintenance
-- ============================================================================

START TRANSACTION;

-- Matikan foreign key checks sementara agar proses pembersihan aman
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Putuskan relasi foreign key pada tabel pcs dan printers (Data PC & Printer tetap utuh)
UPDATE pcs SET asset_item_id = NULL, asset_bundle_id = NULL, maintenance_asset_id = NULL;
UPDATE printers SET asset_item_id = NULL, maintenance_asset_id = NULL;

-- 2. Hapus Histori Corrective Maintenance & Tiket Masalah
DELETE FROM corrective_repair_parts;
DELETE FROM corrective_repairs;
DELETE FROM corrective_tickets;
DELETE FROM asset_walkarounds;

-- 3. Hapus Histori Preventive Maintenance & Schedule
DELETE FROM maintenance_timeline;
DELETE FROM maintenance_reports;
DELETE FROM schedule_jobs;
DELETE FROM maintenance_schedules;

-- 4. Hapus Perbaikan & Mutasi Asset Legacy
DELETE FROM asset_repair_parts;
DELETE FROM asset_repairs;
DELETE FROM asset_movements;
DELETE FROM asset_bundle_members;
DELETE FROM asset_bundles;
DELETE FROM asset_item_members;
DELETE FROM asset_identifiers;

-- 5. Hapus Maintenance Asset
DELETE FROM maintenance_asset_items;
DELETE FROM maintenance_assets;

-- 6. Hapus Unit Asset Fisik & Master Barang (Katalog Model)
DELETE FROM asset_items;
DELETE FROM asset_master_items;

-- Hidupkan kembali foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

COMMIT;

-- ============================================================================
-- RESET AUTO_INCREMENT COUNTER KE 1
-- ============================================================================
ALTER TABLE asset_items AUTO_INCREMENT = 1;
ALTER TABLE asset_master_items AUTO_INCREMENT = 1;
ALTER TABLE maintenance_assets AUTO_INCREMENT = 1;
ALTER TABLE maintenance_asset_items AUTO_INCREMENT = 1;
ALTER TABLE maintenance_schedules AUTO_INCREMENT = 1;
ALTER TABLE maintenance_reports AUTO_INCREMENT = 1;
ALTER TABLE maintenance_timeline AUTO_INCREMENT = 1;
ALTER TABLE corrective_tickets AUTO_INCREMENT = 1;
ALTER TABLE corrective_repairs AUTO_INCREMENT = 1;
ALTER TABLE corrective_repair_parts AUTO_INCREMENT = 1;
ALTER TABLE asset_walkarounds AUTO_INCREMENT = 1;
ALTER TABLE asset_item_members AUTO_INCREMENT = 1;
ALTER TABLE asset_identifiers AUTO_INCREMENT = 1;
ALTER TABLE asset_movements AUTO_INCREMENT = 1;
ALTER TABLE asset_repairs AUTO_INCREMENT = 1;
ALTER TABLE asset_repair_parts AUTO_INCREMENT = 1;
ALTER TABLE asset_bundles AUTO_INCREMENT = 1;
ALTER TABLE asset_bundle_members AUTO_INCREMENT = 1;

