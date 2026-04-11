/*
 * Snipe-IT Booking App – Database Schema
 * -------------------------------------
 * This schema contains ONLY tables owned by the booking application.
 * It does NOT modify or depend on the Snipe-IT production database.
 *
 * Safe to commit to GitHub.
 */

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ------------------------------------------------------
-- Users table
-- (local representation of authenticated users)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_user_id (user_id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Reservations table
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(64) NOT NULL,    -- user identifier
    user_name VARCHAR(255) NOT NULL, -- user display name
    user_email VARCHAR(255) NOT NULL,
    snipeit_user_id INT UNSIGNED DEFAULT NULL, -- optional link to Snipe-IT user id

    asset_id INT UNSIGNED NOT NULL DEFAULT 0,  -- optional: single-asset reservations
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,

    status ENUM('pending','confirmed','completed','cancelled','missed') NOT NULL DEFAULT 'pending',

    -- Cached display string of items (for quick admin lists)
    asset_name_cache TEXT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_reservations_user_id (user_id),
    KEY idx_reservations_dates (start_datetime, end_datetime),
    KEY idx_reservations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Reservation items
-- (models + quantities per reservation)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservation_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED NOT NULL,
    item_type VARCHAR(32) NOT NULL DEFAULT 'model',
    item_id INT UNSIGNED NOT NULL DEFAULT 0,
    item_name_cache VARCHAR(255) NOT NULL DEFAULT '',
    model_id INT UNSIGNED NOT NULL,
    model_name_cache VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    KEY idx_reservation_items_reservation (reservation_id),
    KEY idx_reservation_items_type_item (item_type, item_id),
    KEY idx_reservation_items_model (model_id),

    CONSTRAINT fk_res_items_reservation
        FOREIGN KEY (reservation_id)
        REFERENCES reservations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Cached checked-out assets (from Snipe-IT sync)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS checked_out_asset_cache (
    asset_id INT UNSIGNED NOT NULL,
    asset_tag VARCHAR(255) NOT NULL,
    asset_name VARCHAR(255) NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    model_name VARCHAR(255) NOT NULL,
    assigned_to_id INT UNSIGNED DEFAULT NULL,
    assigned_to_name VARCHAR(255) DEFAULT NULL,
    assigned_to_email VARCHAR(255) DEFAULT NULL,
    assigned_to_username VARCHAR(255) DEFAULT NULL,
    status_label VARCHAR(255) DEFAULT NULL,
    last_checkout VARCHAR(32) DEFAULT NULL,
    expected_checkin VARCHAR(32) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (asset_id),
    KEY idx_checked_out_model (model_id),
    KEY idx_checked_out_expected (expected_checkin),
    KEY idx_checked_out_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checked_out_asset_cache_build LIKE checked_out_asset_cache;

-- ------------------------------------------------------
-- Cached catalogue models/assets/accessories/kits (from Snipe-IT sync)
-- ------------------------------------------------------
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

CREATE TABLE IF NOT EXISTS catalogue_model_cache_build LIKE catalogue_model_cache;

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

CREATE TABLE IF NOT EXISTS catalogue_asset_cache_build LIKE catalogue_asset_cache;

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

CREATE TABLE IF NOT EXISTS catalogue_cache_meta (
    cache_key VARCHAR(64) NOT NULL,
    synced_at DATETIME NOT NULL,
    model_count INT UNSIGNED NOT NULL DEFAULT 0,
    asset_count INT UNSIGNED NOT NULL DEFAULT 0,
    checked_out_count INT UNSIGNED NOT NULL DEFAULT 0,

    PRIMARY KEY (cache_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic DB-backed cache for direct Snipe-IT GET responses used outside the dedicated cache tables.
CREATE TABLE IF NOT EXISTS snipeit_api_response_cache (
    cache_key CHAR(40) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    request_params MEDIUMTEXT NOT NULL,
    response_payload MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (cache_key),
    KEY idx_snipeit_api_response_cache_endpoint (endpoint),
    KEY idx_snipeit_api_response_cache_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Activity log
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(64) NOT NULL,
    actor_user_id VARCHAR(64) DEFAULT NULL,
    actor_name VARCHAR(255) DEFAULT NULL,
    actor_email VARCHAR(255) DEFAULT NULL,
    subject_type VARCHAR(64) DEFAULT NULL,
    subject_id VARCHAR(64) DEFAULT NULL,
    message VARCHAR(255) NOT NULL,
    metadata TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_activity_event (event_type),
    KEY idx_activity_actor (actor_user_id),
    KEY idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- User favourite models
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_favourite_models (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_email VARCHAR(255) NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_favourite_models_user_model (user_email, model_id),
    KEY idx_user_favourite_models_user (user_email),
    KEY idx_user_favourite_models_model (model_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Optional: simple schema versioning
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_version (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version VARCHAR(32) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_version_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_version (version)
VALUES ('v0.8.0-beta');
