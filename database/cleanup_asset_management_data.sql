-- BACKUP DATABASE SEBELUM MENJALANKAN QUERY INI.
-- Membersihkan data Asset Management, tetapi mempertahankan:
-- users, master kategori asset, master maintenance, data PC, dan data printer.

START TRANSACTION;

UPDATE maintenance_schedules SET maintenance_asset_id = NULL;
UPDATE pcs SET asset_item_id = NULL, asset_bundle_id = NULL, maintenance_asset_id = NULL;
UPDATE printers SET asset_item_id = NULL, maintenance_asset_id = NULL;

DELETE FROM asset_repair_parts;
DELETE FROM asset_repairs;
DELETE FROM asset_movements;
DELETE FROM asset_bundle_members;
DELETE FROM asset_item_members;
DELETE FROM maintenance_asset_items;
DELETE FROM maintenance_assets;
DELETE FROM asset_bundles;
DELETE FROM asset_items;
DELETE FROM asset_companies;

COMMIT;

-- Reset nomor AUTO_INCREMENT setelah transaksi selesai (ALTER TABLE melakukan implicit commit).
ALTER TABLE asset_repair_parts AUTO_INCREMENT = 1;
ALTER TABLE asset_repairs AUTO_INCREMENT = 1;
ALTER TABLE asset_movements AUTO_INCREMENT = 1;
ALTER TABLE asset_bundle_members AUTO_INCREMENT = 1;
ALTER TABLE asset_item_members AUTO_INCREMENT = 1;
ALTER TABLE maintenance_asset_items AUTO_INCREMENT = 1;
ALTER TABLE maintenance_assets AUTO_INCREMENT = 1;
ALTER TABLE asset_bundles AUTO_INCREMENT = 1;
ALTER TABLE asset_items AUTO_INCREMENT = 1;
ALTER TABLE asset_companies AUTO_INCREMENT = 1;
