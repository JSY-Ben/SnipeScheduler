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
VALUES ('v0.6.0-beta');
