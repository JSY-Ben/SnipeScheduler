CREATE TABLE IF NOT EXISTS snipeit_user_group_cache (
    user_email VARCHAR(255) NOT NULL,
    snipeit_user_id INT UNSIGNED NOT NULL DEFAULT 0,
    user_name VARCHAR(255) NOT NULL DEFAULT '',
    group_id INT UNSIGNED NOT NULL,
    group_name VARCHAR(255) NOT NULL DEFAULT '',
    synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (user_email, group_id),
    KEY idx_snipeit_user_group_cache_group (group_id),
    KEY idx_snipeit_user_group_cache_synced (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
