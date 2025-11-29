-- schema.sql - Database schema for the Snipe-IT booking layer
--
-- This will:
--   * create a database named `equipment_booking`
--   * create a `reservations` table
--   * create an optional `students` table (not strictly required)

CREATE DATABASE IF NOT EXISTS equipment_booking
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE equipment_booking;

-- Core reservations table
CREATE TABLE IF NOT EXISTS reservations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_name VARCHAR(255) NOT NULL,
    student_email VARCHAR(255) NULL,
    student_id VARCHAR(100) NULL,
    asset_id INT UNSIGNED NOT NULL,           -- Snipe-IT hardware ID
    asset_name_cache VARCHAR(255) NOT NULL,   -- cached asset name for convenience
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    status ENUM('pending', 'confirmed', 'cancelled', 'completed')
        NOT NULL DEFAULT 'pending',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_asset_time (asset_id, start_datetime, end_datetime),
    INDEX idx_student (student_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional: simple students table (not required for basic operation)
CREATE TABLE IF NOT EXISTS students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
