CREATE TABLE IF NOT EXISTS company_source_config (
    config_key VARCHAR(80) PRIMARY KEY,
    config_value TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE asset_companies
    ADD COLUMN IF NOT EXISTS external_company_id VARCHAR(80) NULL AFTER company_code;

ALTER TABLE pcs
    ADD COLUMN IF NOT EXISTS asset_item_id BIGINT NULL AFTER computer_name,
    ADD COLUMN IF NOT EXISTS asset_bundle_id BIGINT NULL AFTER asset_item_id;

CREATE INDEX IF NOT EXISTS idx_asset_company_external ON asset_companies (external_company_id);
CREATE INDEX IF NOT EXISTS idx_pcs_asset_item ON pcs (asset_item_id);
CREATE INDEX IF NOT EXISTS idx_pcs_asset_bundle ON pcs (asset_bundle_id);
