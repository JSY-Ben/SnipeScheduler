ALTER TABLE reservation_items
    ADD COLUMN item_type VARCHAR(32) NOT NULL DEFAULT 'model' AFTER reservation_id,
    ADD COLUMN item_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER item_type,
    ADD COLUMN item_name_cache VARCHAR(255) NOT NULL DEFAULT '' AFTER item_id,
    ADD KEY idx_reservation_items_type_item (item_type, item_id);

UPDATE reservation_items
   SET item_type = 'model'
 WHERE item_type IS NULL
    OR item_type = '';

UPDATE reservation_items
   SET item_id = model_id
 WHERE item_id = 0;

UPDATE reservation_items
   SET item_name_cache = model_name_cache
 WHERE item_name_cache = ''
    OR item_name_cache IS NULL;
