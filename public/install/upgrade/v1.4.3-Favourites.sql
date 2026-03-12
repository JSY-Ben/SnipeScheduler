-- Add per-user model favourites
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
