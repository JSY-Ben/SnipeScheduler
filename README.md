# ReserveIT

ReserveIT is a lightweight PHP/MySQL web app that layers booking and checkout workflows on top of Snipe-IT. Students can request equipment, and staff can manage reservations, checkouts, and checked-out assets from a unified “Reservations” hub.

## Features
- Catalogue and basket flow for students to request equipment.
- My Reservations view for students.
- Staff “Reservations” hub with tabs for Today’s Reservations (checkout), Checked Out Reservations, and Reservation History.
- Quick checkout/checkin flows for ad-hoc asset handling.
- Snipe-IT integration for model and asset data; optional LDAP/AD for authentication.

## System requirements
- PHP 8.0+ with extensions: pdo_mysql, curl, ldap, mbstring, openssl, json.
- MySQL/MariaDB database for the booking tables.
- Web server: Apache or Nginx (PHP-FPM or mod_php).
- Snipe-IT instance with API access token.

## Installation
1. Clone or copy this repository to your web root.
2. Ensure the web server user can write to `config/` (for `config.php`) and create `config/cache/` if needed.
3. Point your web server at the `public/` directory.
4. Visit `public/install.php` in your browser:
   - Fill in database, Snipe-IT API, and LDAP connection details (tests are available inline).
   - Generate `config/config.php` and optionally create the database from `schema.sql`.
   - Remove or restrict access to `install.php` after successful setup.
5. If you prefer manual configuration, copy `config/config.example.php` to `config/config.php` and update values. Then import `schema.sql` into your database.

## General usage
- Students:
  - Browse equipment via `catalogue.php`, add to basket, and submit reservations.
  - View their reservations on `my_bookings.php` (labelled “My Reservations”).
- Staff:
  - Use `reservations.php` for day-to-day work:
    - Today’s Reservations (checkout against bookings).
    - Checked Out Reservations (view/overdue assets from Snipe-IT).
    - Reservation History (filter/search all reservations).
  - Quick checkout/checkin pages exist for ad-hoc asset handling.
- Settings:
  - Configure app, API, and LDAP options via `settings.php` (staff only). Test buttons let you validate connections without saving.

## Notes
- Keep your Snipe-IT API token and LDAP bind password secure; avoid committing `config/config.php`.
- After installation, delete or restrict `public/install.php` to prevent reconfiguration.
