-- Upgrade: add activity_log table
-- Compatible with existing SnipeScheduler schema.

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

UPDATE schema_version
   SET version = 'v0.8.0-beta'
 WHERE version <> 'v0.8.0-beta';

INSERT IGNORE INTO schema_version (version)
VALUES ('v0.8.0-beta');
