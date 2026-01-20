# KitGrab - An Asset Reservation/Checkout System

[![Donate with PayPal to help me continue developing these apps!](https://www.paypalobjects.com/en_US/i/btn/btn_donateCC_LG.gif)](https://www.paypal.com/donate/?business=5TRANVZF49AN6&no_recurring=0&item_name=Thank+you+for+any+donations%21+It+will+help+me+put+money+into+the+tools+I+use+to+develop+my+apps+and+services.&currency_code=GBP)

Please note - this app is still in a beta stage of development as a product. It has been built for production on a single site and has been working without issue, however please consider this an in-development product for now. Please do use it, report issues and request features, but consider it unsuitable for a high risk production environment until any bugs have been ironed out.

![Catalogue](https://github.com/user-attachments/assets/ead32453-1db3-4026-8a93-4f6d118ec1f1)

![Reservations](https://github.com/user-attachments/assets/8d4880c5-6203-4d5f-84e0-5c43d9672afa)

KitGrab is a PHP/MySQL web app for equipment booking, checkout workflows, and asset tracking. It uses its own local asset model and asset inventory database and stores users locally (created automatically when they sign in).

Authentication supports local accounts plus LDAP, Google OAuth, or Microsoft Entra OAuth. The installer creates an initial local admin account. When users sign in via external providers, they are added to the local user database automatically.

In the app, users can request equipment, and staff can manage reservations, checkouts, and checked-out assets from a unified "Reservations" hub.

## Features
- Catalogue and basket flow for users to request equipment.
- Staff "Reservations" hub with tabs for Today's Reservations (checkout), Checked Out Reservations, and Reservation History.
- Quick checkout/checkin flows for ad-hoc asset handling.
- Local inventory database for models and assets.
- LDAP/AD, Google OAuth and Microsoft Entra integration for authentication.

## System requirements
- PHP 8.0+ with extensions: pdo_mysql, curl, ldap, mbstring, openssl, json.
- MySQL/MariaDB database for the app tables.
- Web server: Apache or Nginx (PHP-FPM or mod_php).

## Installation
1. Clone or copy this repository to your web root.
2. Ensure the web server user can write to `config/` (for `config.php`).
3. Point your web server at the `public/` directory.
4. Visit https://www.yourinstallation.com/install/ in your browser:
   - Fill in database details and create the initial local admin account.
   - Generate `config/config.php` and optionally create the database from `public/install/schema.sql`.
   - Configure LDAP/Google/Entra later in the Admin Settings page if needed.
   - Remove or restrict access to `public/install` after successful setup.
5. If you prefer manual configuration, copy `config/config.example.php` to `config/config.php` and update values. Then import `public/install/schema.sql` into your database.

## Inventory setup
- Populate `asset_categories`, `asset_models`, and `assets` tables with your equipment data.
- Assets marked as `requestable=1` will appear in the catalogue if their model exists.
- Checked-out assets are tracked in the `checked_out_asset_cache` table by the app.

## General usage
- Users:
  - Browse equipment via `Catalogue`, add to basket, and submit reservations.
  - View their reservations on `My Reservations`.
- Staff:
  - Use `Reservations` page for:
    - Today's Reservations (checkout against bookings).
    - Checked Out Reservations (view/overdue assets).
    - Reservation History (filter/search all reservations).
  - Quick checkout/checkin pages exist for ad-hoc asset handling.
- Settings:
  - Configure app, authentication, and SMTP options via `Settings` (admin only). Test buttons let you validate connections without saving.

## Setting up Admins/Staff

This app supports local accounts plus LDAP, Google OAuth, or Microsoft Entra. During installation you create the first local admin. After install, define admins/staff via local users or external groups/emails in the settings page. Standard users only have access to reservations, whereas specified staff can checkout/checkin equipment.

## CRON Scripts

In the scripts folder of this app, there are certain PHP scripts you should run as a cron or via PHP CLI at regular intervals.

- The `cron_mark_missed.php` script will automatically mark reservations not checked out after a specified time period (set on the settings page) as missed and release them to be booked again. By default, this is set to 1 hour.
- The `email_overdue_staff.php` and `email_overdue_users.php` scripts will automatically email users with overdue equipment and inform staff specified on the settings page of currently overdue reservations. Run these daily if you want reminders.
