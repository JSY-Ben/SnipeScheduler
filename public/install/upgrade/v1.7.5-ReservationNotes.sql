ALTER TABLE reservations
    ADD COLUMN reservation_note TEXT NULL AFTER asset_name_cache;
