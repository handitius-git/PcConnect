ALTER TABLE asset_items
    ADD COLUMN IF NOT EXISTS asset_mode VARCHAR(20) NOT NULL DEFAULT 'standalone' AFTER company_id;

CREATE TABLE IF NOT EXISTS asset_item_members (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE asset_movements
    ADD COLUMN IF NOT EXISTS from_parent_asset_item_id BIGINT NULL AFTER to_bundle_id,
    ADD COLUMN IF NOT EXISTS to_parent_asset_item_id BIGINT NULL AFTER from_parent_asset_item_id;

CREATE INDEX IF NOT EXISTS idx_asset_item_mode ON asset_items (asset_mode);
CREATE INDEX IF NOT EXISTS idx_asset_movement_from_parent ON asset_movements (from_parent_asset_item_id);
CREATE INDEX IF NOT EXISTS idx_asset_movement_to_parent ON asset_movements (to_parent_asset_item_id);
