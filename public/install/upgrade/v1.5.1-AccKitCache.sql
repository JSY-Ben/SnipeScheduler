-- Upgrade: add local catalogue cache tables for accessories and kits

CREATE TABLE IF NOT EXISTS catalogue_accessory_cache (
    accessory_id INT UNSIGNED NOT NULL,
    accessory_name VARCHAR(255) NOT NULL,
    manufacturer_name VARCHAR(255) DEFAULT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    category_name VARCHAR(255) DEFAULT NULL,
    image_path VARCHAR(1024) DEFAULT NULL,
    notes_text MEDIUMTEXT DEFAULT NULL,
    total_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    available_quantity INT UNSIGNED NOT NULL DEFAULT 0,
    raw_payload MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (accessory_id),
    KEY idx_catalogue_accessory_category (category_id),
    KEY idx_catalogue_accessory_name (accessory_name),
    KEY idx_catalogue_accessory_available (available_quantity),
    KEY idx_catalogue_accessory_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogue_accessory_cache_build LIKE catalogue_accessory_cache;

CREATE TABLE IF NOT EXISTS catalogue_kit_cache (
    kit_id INT UNSIGNED NOT NULL,
    kit_name VARCHAR(255) NOT NULL,
    image_path VARCHAR(1024) DEFAULT NULL,
    notes_text MEDIUMTEXT DEFAULT NULL,
    raw_payload MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (kit_id),
    KEY idx_catalogue_kit_name (kit_name),
    KEY idx_catalogue_kit_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogue_kit_cache_build LIKE catalogue_kit_cache;

CREATE TABLE IF NOT EXISTS catalogue_kit_item_cache (
    kit_id INT UNSIGNED NOT NULL,
    item_type VARCHAR(32) NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    raw_payload MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (kit_id, item_type, item_id),
    KEY idx_catalogue_kit_item_type (item_type),
    KEY idx_catalogue_kit_item_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS catalogue_kit_item_cache_build LIKE catalogue_kit_item_cache;
