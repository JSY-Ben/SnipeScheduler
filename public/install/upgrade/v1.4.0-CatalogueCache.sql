-- Upgrade: add local catalogue cache tables
-- Compatible with existing SnipeScheduler schema.

CREATE TABLE IF NOT EXISTS catalogue_model_cache (
    model_id INT UNSIGNED NOT NULL,
    model_name VARCHAR(255) NOT NULL,
    manufacturer_name VARCHAR(255) DEFAULT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    category_name VARCHAR(255) DEFAULT NULL,
    image_path VARCHAR(1024) DEFAULT NULL,
    notes_text MEDIUMTEXT DEFAULT NULL,
    total_asset_count INT UNSIGNED NOT NULL DEFAULT 0,
    requestable_asset_count INT UNSIGNED NOT NULL DEFAULT 0,
    raw_payload MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (model_id),
    KEY idx_catalogue_model_category (category_id),
    KEY idx_catalogue_model_name (model_name),
    KEY idx_catalogue_model_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogue_asset_cache (
    asset_id INT UNSIGNED NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    model_name VARCHAR(255) NOT NULL,
    asset_tag VARCHAR(255) NOT NULL,
    asset_name VARCHAR(255) NOT NULL,
    requestable TINYINT(1) NOT NULL DEFAULT 0,
    status_label VARCHAR(255) DEFAULT NULL,
    assigned_to_id INT UNSIGNED DEFAULT NULL,
    assigned_to_name VARCHAR(255) DEFAULT NULL,
    assigned_to_email VARCHAR(255) DEFAULT NULL,
    assigned_to_username VARCHAR(255) DEFAULT NULL,
    default_location_name VARCHAR(255) DEFAULT NULL,
    raw_payload MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (asset_id),
    KEY idx_catalogue_asset_model (model_id),
    KEY idx_catalogue_asset_requestable (requestable),
    KEY idx_catalogue_asset_model_requestable (model_id, requestable),
    KEY idx_catalogue_asset_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogue_cache_meta (
    cache_key VARCHAR(64) NOT NULL,
    synced_at DATETIME NOT NULL,
    model_count INT UNSIGNED NOT NULL DEFAULT 0,
    asset_count INT UNSIGNED NOT NULL DEFAULT 0,
    checked_out_count INT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
