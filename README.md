# ReserveIT

Please note - this app is still in an alpha stage of development as a product. It has been built for production on a single site and has been working without issue, however please consider this an in-development product for now. Please do try it, report issues and request features, but consider it unsuitable for a production environment until any bugs have been ironed out.  

![Catalogue](https://github.com/user-attachments/assets/ead32453-1db3-4026-8a93-4f6d118ec1f1)

![Reservations](https://github.com/user-attachments/assets/8d4880c5-6203-4d5f-84e0-5c43d9672afa)


ReserveIT is a PHP/MySQL web app that layers equipment booking and checkout workflows on top of Snipe-IT. It has been very deliberately designed to use the Snipe-IT API for all functions and not the Snipe-IT database directly. This allows it to sit on a separate server to your Snipe-IT Installation, and doesn't require direct access to the Snipe-IT server from the user endpoint. As long as this app can access your Snipe-IT API from the server side, this app should function. Images from Snipe-IT are delivered via an image proxy.  

Due to the fact that the Snipe-IT API is used for all functions, there is currently a requirement for this app and Snipe-IT to be configured to use either LDAP, Google OAuth or Microsoft Entra OAuth for authentication. There is currently no local user signup or login available on this app yet. However I am open to implementing this if you feel this would help. Please do ask! 

In the app, Users can request equipment, and staff can manage reservations, checkouts, and checked-out assets from a unified “Reservations” hub.

## Features
- Catalogue and basket flow for users to request equipment.
- Staff “Reservations” hub with tabs for Today’s Reservations (checkout), Checked Out Reservations, and Reservation History.
- Quick checkout/checkin flows for ad-hoc asset handling.
- Snipe-IT integration for model and asset data
- LDAP/AD, Google OAuth and Microsoft Entra Integration for authentication.

## System requirements
- PHP 8.0+ with extensions: pdo_mysql, curl, ldap, mbstring, openssl, json.
- MySQL/MariaDB database for the booking tables.
- Web server: Apache or Nginx (PHP-FPM or mod_php).
- Snipe-IT instance with API access token and either LDAP, Google OAuth or Microsoft Entra Authentication enabled.

## Installation
1. Clone or copy this repository to your web root.
2. Ensure the web server user can write to `config/` (for `config.php`) and create `config/cache/` if needed.
3. Point your web server at the `public/` directory.
4. Visit `public/install.php` in your browser:
   - Fill in database, Snipe-IT API, and at least one of the authentication (LDAP/Google/Entra) methods (tests are available inline).
   - Generate `config/config.php` and optionally create the database from `schema.sql`.
   - Remove or restrict access to `install.php` after successful setup.
5. If you prefer manual configuration, copy `config/config.example.php` to `config/config.php` and update values. Then import `schema.sql` into your database.

## General usage
- Users:
  - Browse equipment via `Catalogue`, add to basket, and submit reservations.
  - View their reservations on `My Reservations').
- Staff:
  - Use `Reservations` page for:
    - Today’s Reservations (checkout against bookings).
    - Checked Out Reservations (view/overdue assets from Snipe-IT).
    - Reservation History (filter/search all reservations).
  - Quick checkout/checkin pages exist for ad-hoc asset handling.
- Settings:
  - Configure app, API, and LDAP options via `Settings` (staff only). Test buttons let you validate connections without saving.

## Making Equipment available to be booked.

For an asset on Snipe-IT to be made available on this app for reservation, both the model and the asset itself must be set to 'Requestable' in Snipe-IT. If a model is set to 'Requestable' and the asset is not, the model will be listed on the catalogue of this app, however the specific asset will not be able to be reserved. This is useful in case you have a certain batch of assets, but you don't want all of them to necessarily be bookable.

## Setting up Admins/Staff

As mentioned, this app uses LDAP, Google OAuth or Microsoft Entra for authentication. When installing this app, please make sure to add Users/Groups on the initial config that contain your users that you wish to be admins/staff. Standard users only have access to reservations, whereas specified Groups/Users assigned to the staff section of this app can checkout/checkin equipment. 

## CRON Scripts

In the scripts folder of this app, there are certain PHP scripts you can run as a cron or via PHP CLI. The 'cron_mark_missed.php' script will automatically mark all reservations not checked out after a specified time period (set in the script) as missed and release them to be booked again. By default, this is set to 1 hour. The email_overdue_staff and users.php scripts will automatically email users that have overdue equipment and inform staff specified explicitly in the script of currently overdue reservations. The teams_overdue_reminder scripts will (once an app registration has been setup in Microsoft Azure, details of which are in the script) attempt to send a message to the overdue user on Microsoft Teams to remind them of overdue equipment. The MS Teams scripts have not been tested to a great degree yet, so proceed carefully :) 
