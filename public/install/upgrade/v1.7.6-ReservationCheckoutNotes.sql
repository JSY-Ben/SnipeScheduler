-- Upgrade: Store the Snipe-IT checkout note in reservation history
ALTER TABLE reservations
    ADD COLUMN checkout_note TEXT NULL AFTER reservation_note;
