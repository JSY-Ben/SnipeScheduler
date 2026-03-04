-- Upgrade: add permanent build tables for cache staging swaps

CREATE TABLE IF NOT EXISTS checked_out_asset_cache_build LIKE checked_out_asset_cache;
CREATE TABLE IF NOT EXISTS catalogue_model_cache_build LIKE catalogue_model_cache;
CREATE TABLE IF NOT EXISTS catalogue_asset_cache_build LIKE catalogue_asset_cache;
