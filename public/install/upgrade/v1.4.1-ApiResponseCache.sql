-- Upgrade: add DB-backed cache for direct Snipe-IT GET responses

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
