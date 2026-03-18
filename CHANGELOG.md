# Changelog

## [v1.5.0](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v1.5.0) - 18/03/2026

### Please Read before Installing

This release has a database upgrade, so requires running the upgrade script.

After updating the files, please do the following:

1) Run the upgrade script at [www.yourinstallation.com/install/upgrade](url) through a browser.

### New catalogue booking types

1) The Catalogue now has separate `Models`, `Accessories`, and `Kits` tabs.

2) Accessories can now be added to the basket, reserved, and checked out directly to users from the staff checkout page.

3) Kits can now be added from the Catalogue. Supported kit contents are expanded into their underlying models and accessories when added to the basket.

## [v1.4.4](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v1.4.4) - 12/03/2026

### Please Read before Installing

This release has a database upgrade, so requires running the upgrade script.

After updating the files, please do the following:

1) Run the upgrade script at [www.yourinstallation.com/install/upgrade](url) through a browser.

### A Minor new feature release

1) There is now a 'Favourites' feature for users on the catalogue, so users can add specific models as a favourite if they book it often. There is a new toggle in the search box on the catalogue to show only Favourites so it makes it easier to regularly book regularly needed items.

2) You can now click on the image of a model in the catalogue and get a zoomed in version of the image to see more detail

3) You can now change the quantity of booked items in the basket with a new +/- toggle, without going back to the catalogue.


## v1.4.3 - 06/03/2026

### A Minor new feature release

1) In the 'Today's Reservations (Checkout)' tab, you can now press a toggle that will allow you to check out any upcoming reservation, even if it isn't for today. You will be warned if doing this will clash with any upcoming bookings.

## [v1.4.2](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v1.4.2) - 06/03/2026

### Please Read before Installing

This release has some fundamental changes to the backend of this app, so please backup your files and database before you attempt this upgrade just in case!

Firstly, after installing this version, please do the following:

1) Run the upgrade script at [www.yourinstallation.com/install/upgrade](url) through a browser, as some database updates/changes are required.
 
2) The **sync_checked_out_assets.php** cron script in /scripts has been replaced with **snipeit_asset_cache_update.php**. Please update your cron settings, and keep it updating regularly.

### Changes in this release

- The caching system in this version has been replaced entirely to avoid constantly pushing the Snipe-IT API for busy installations or installations with a lot of assets that need to be loaded every time a page is loaded. So for your users, this should make 5-10 second page loads now almost instant.

- You can now set to bypass reservation restrictions for 'Quick Checkout'

- You can now restrict the catalogue from showing assets that have a particular status in Snipe-IT.

- You can now set whether checked out equipment should be listed as unavailable for future bookings even if the return date is before the requested booking. This helps avoid problems if you have users who often don't return equipment on time. 

- Other Minor Bugfixes.



## [v1.3.2](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v1.3.2) - 02/03/2026

### A Minor new feature release

1) In the 'Checked Out Reservations' section, there is now an inline 'Checkin' button for every listed piece of equipment that is signed out, in both the 'All Checked Out' and 'Overdue' tab, so you can quickly sign in individual items. You can also tick multiple items or 'Select all' and check in multiple listed items at once.

2) Email Notifications now include links to not only the individual reservations mentioned, but the Reservations page in the case of admin/checkout staff and 'My Reservations' in the case of normal users.

## [v1.3.1](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v1.3.1) - 27/02/2026

Just a little change for this release. You can now set the App Name in Frontend Settings. This allows you to use your own brand name across the app UI and email notifications.

## [v1.3.0](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v1.3.0) - 26/02/2026

### A Minor New Feature Release

1. Added a new 'Notifications' tab in the admin area where you can control who receives email notifications for specific events, including a new email notification option for newly submitted  reservations. Manual email addresses can be added as well as tickboxes for checkout users, admins and the reservation user.

2. Added public guest browsing mode for Dashboard/Catalogue (login still required for basket/booking actions).

## [v.1.2.0](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v.1.2.0) - 23/02/2026

### A New Feature and Bug Fix release based on user requests and feedback:

1. New Reservation Control features added, which allow you to add certain requirements before reservations are made, such as minimum notice period, minimum and maximum reservation duration, maximum concurrent reservations per user and blackout slots. This can be bypassed for admins and checkout users so they can book on a user's behalf if necessary.

2. New 'Reports' feature in Admin area, with reports on equipment utilisation by category and model, Peak demand reports, cancellation and no-show reports.  

3. There is now an announcements section in the Admin area where admins can display one-time timed announcement modals on the app when a user logs in. Useful for displaying things like opening/closing time changes for example.

4. Time and Date Pickers across the app, which were originally rendered using browser native pickers, have been replaced with a cross-platform alternative for consistency and ease of use. The mobile version of the app has been set to use Operating System pickers though as this is a more user-friendly experience on mobile.  

5.  Settings Tidied up and split into Frontend and Backend settings

6.  Activity Log pagination fixed, as long logs were causing page numbers to go off the screen.

7.  You can now upload an app logo using the settings page, select your colour scheme for your site using a colour picker and select your timezone as a dropdown, as opposed to typing in the PHP Identifier manually, as before.

8. Removed manual “Update availability” buttons on the catalogue and basket and defaulted to auto-refreshing when changes are made.

9.  Users noted that dates would randomly jump if you clicked off the date/time picker without changing it. This has been fixed.

10. When pages auto-refresh, your page position is now remembered rather than the app skipping to the top of the page.

11. Mobile Layout Fixes on Catalogue, mainly around reservation window selection.

12. All scripts now timestamp correctly when logging (in admin set time/date format)

As always, please do report issues and request features using the 'Issues' section. We love to hear from you!

## [v1.1.0](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v1.1.0) - 17/02/2026

A New Feature release based on a user request:

Each equipment model on the catalogue is now a clickable link that opens a popup modal with the full notes of the model from Snipe-IT, and a calendar view of all the bookings for that particular model:

![Calendar](https://github.com/user-attachments/assets/bce14374-c894-45b9-bf4e-5616b79b1f23)

As usual, please do report issues and any feature requests you would like.

## [v1.0.0-RC2](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v1.0.0-RC2) - 15/02/2026

Some small bug fixes in this release:

1. Until now, if the user updated the date range of the reservation in the basket, the newly chosen date would not be applied to the booking unless the 'Check Availability' button was pressed, so the old date would have persisted after the booking was submitted. This has been fixed.

2. The manual 'Check Availability' button on the basket has now been removed, and the availability is updated automatically when any date is changed in the basket.

Also, the 'Reservation History' tab naming was causing some confusion as it also contained present and future reservations, so it has been renamed to 'All Reservations'

As always, please feel free to request new features and report bugs in the 'Issues' tab of Github. Many thanks for all those who have responded so far. 

## [v1.0.0-RC1](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v1.0.0-RC1) - 05/02/2026

We are moving SnipeScheduler out of Beta now and into Release Candidates. Hopefully all major bugs have finally been squashed and we can concentrate on new features, but please do report and issues you may find.

## [v0.8.5-beta](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v0.8.5-beta) - 26/01/2026

Some bug fixes and new features for this release:

**New Features:**

1. There is now a pre-loader on the Catalogue page. It will render the page instantly with a 'Fetching Assets' spinning wheel while Snipe-IT API tasks are carried out, so an inpatient user isn't kept waiting by a loading bar in the browser. 

2. The Date/Time is now standardised across the app, therefore you can set the date/time format to whatever international format is appropriate for your location from the settings page.

## [v0.8.3-beta](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v0.8.3-beta) - 26/01/2026

This is a bug fix release:

1. If a user's name or email address appears twice in Snipe-IT, it will now ask the user to confirm which one to use upon checkout.

2. Today's Reservation Page now resets after a checkout is complete rather than leaving the just checked out reservation left open.

3. When you remove a model from a reservation upon checkout, the page now doesn't reset, it maintains already chosen items in the asset dropdown.

4. Changes made to the Install workflow to make it less confusing.

## [v0.8.1-beta](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v0.8.1-beta) - 20/01/2026

This is a new feature release:

The 'Settings' page has now been replaced by an 'Admin' section, which has the settings as before, but also a new tab called 'Activity Log' which now logs every action your users and staff do, such as logging in/out, reserving items, checking in/out etc. This can be very useful for auditing your processes.

Instead of just 'Staff' users, there are now two types of staff users, 'Checkout' users and 'Admin' Users. The admin users have access to everything, including the new 'Activity Log' page and the 'Settings' page. The 'Checkout' users have access to check in and out equipment, and adjust/delete reservations, but cannot access the new 'Admin' section. When you run the upgrade script below, it will convert all your current staff users to the new admin users.

Upgrade Process:

Due to these changes, if you currently have an existing installation, please upgrade the app via the browser by pointing your browser to https://www.yourinstallation.com/install/. This will automatically make the necessary changes to your current config.php file and add new entries to your database in order to make the new features work. Please then consider removing or restricting access to the install folder afterward.

## [v0.7.4-beta](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v0.7.4-beta) - 15/01/2026

A few minor bug fixes in this release:

1. When you try to do a 'Quick Checkout' of an asset that already has a reservation, it was displaying a warning saying that the asset was already reserved whether there was more than one asset of that model available or not. This has now been fixed.

2. Selecting a Category, or changing sorting options on the Catalogue now auto-reloads after selection rather than you having to press the 'Filter' button manually.

3. When you select a reservation to checkout in the 'Today's Reservations' page, it now autoloads rather than you having to press the button manually.

4. When you switch from 'Today's Reservations' to another page, and go back, the original reservation you selected was still open. This has now been fixed.

## [v0.7.3-beta](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v0.7.3-beta) - 14/01/2026

Release Notes:

Bug Fixes:

1. Substantial mySQL bug fixed on Checkout cache. If the same asset appeared more than once in an API call of checking checked out items, the checkout cache pull would fail and the available items would not be correct. This should now be fixed.

2. The 'All Checked Out' page was showing items as overdue that were due on the same day. This has now been rectified.  

3. Overdue email Cron Script - Query Error Fixed. Script should now work again.

New Features:

1. You can now remove items from a reservation as you are checking it out, as some users reported they did not want to check out everything they had reserved.

2. Reservation History and Checked Out Item lists are now paginated to avoid slow loads when the list gets big!

## [v0.7.2-beta](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v0.7.2-beta) - 14/01/2026

A bug fix release that fixes some styling issues and also fixes items remaining on the 'Quick Checkin' screen after check ins have completed.

## [v0.7.0-beta](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v0.7.0-beta) - 12/01/2026

Some feature updates for this release:

- When Signing out a Reservation and assigning asset numbers, the image of the model from Snipe-IT is now shown as reference.
- Reservations that are pending can now be edited from the 'Reservation History' page. This includes adding new models to the reservation.
- Reservations that are checked out can now be renewed from the 'Checked Out Reservations' page. Multiple items can be renewed at once. This will renew them in Snipe-IT.
- Reservations that have been missed can now be Restored/Re-Enabled from the 'Reservation History' page. This will check if the equipment is still available first. If the end date was in the past, it will automatically renew it until the following day at 9am. This can then be edited later if necessary.

No Database Changes in this build, so you can update from the last version by just replacing the files.

## [v0.6.0-beta](https://github.com/JSY-Ben/SnipeScheduler/releases/tag/v0.6.0-beta) - 11/01/2026

Thank you for downloading this initial public release of SnipeScheduler!

Please use the README.md to find the instructions on how to install and configure the app.

As mentioned in the Readme, this is still a beta release, so I wouldn't consider it ready for a high-risk production installation yet. It has been in use in production on a single site but you may find errors that others haven't, so please do report them and request features in the Issues tab on GitHub.
