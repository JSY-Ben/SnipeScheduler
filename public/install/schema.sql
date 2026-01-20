/*
 * KitGrab – Database Schema
 * -------------------------------------
 * This schema contains ONLY tables owned by the booking application.
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
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    is_admin TINYINT(1) NOT NULL DEFAULT 0,
    is_staff TINYINT(1) NOT NULL DEFAULT 0,
    password_hash VARCHAR(255) DEFAULT NULL,
    auth_source VARCHAR(32) NOT NULL DEFAULT 'local',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_user_id (user_id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Asset categories
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_asset_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Asset models
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_models (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    manufacturer VARCHAR(255) DEFAULT NULL,
    category_id INT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    image_url VARCHAR(1024) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_asset_models_category (category_id),
    CONSTRAINT fk_asset_models_category
        FOREIGN KEY (category_id)
        REFERENCES asset_categories (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Assets
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS assets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_tag VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    status ENUM('available','checked_out','maintenance','retired') NOT NULL DEFAULT 'available',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_assets_asset_tag (asset_tag),
    KEY idx_assets_model (model_id),

    CONSTRAINT fk_assets_model
        FOREIGN KEY (model_id)
        REFERENCES asset_models (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Asset notes (check-in / check-out)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS asset_notes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id INT UNSIGNED NOT NULL,
    note_type ENUM('checkout','checkin') NOT NULL,
    note TEXT NOT NULL,
    actor_user_id INT UNSIGNED DEFAULT NULL,
    actor_name VARCHAR(255) DEFAULT NULL,
    actor_email VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_asset_notes_asset (asset_id),
    KEY idx_asset_notes_created (created_at),
    KEY idx_asset_notes_actor (actor_user_id),

    CONSTRAINT fk_asset_notes_asset
        FOREIGN KEY (asset_id)
        REFERENCES assets (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Reservations table
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(64) NOT NULL,    -- user identifier
    user_name VARCHAR(255) NOT NULL, -- user display name
    user_email VARCHAR(255) NOT NULL,

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
    model_id INT UNSIGNED NOT NULL,
    model_name_cache VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    KEY idx_reservation_items_reservation (reservation_id),
    KEY idx_reservation_items_model (model_id),

    CONSTRAINT fk_res_items_reservation
        FOREIGN KEY (reservation_id)
        REFERENCES reservations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Checked-out assets (local inventory)
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
VALUES ('0.5 (alpha)');
