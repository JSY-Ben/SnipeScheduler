<?php
/**
 * Global configuration for KitGrab.
 *
 * Edit the values below to match your environment.
 *
 * Copy this file to config/config.php and keep your secrets out of version control.
 */

/**
 * Paging / limits for catalogue
 * These run as soon as config.php is required.
 */
if (!defined('CATALOGUE_ITEMS_PER_PAGE')) {
    define('CATALOGUE_ITEMS_PER_PAGE', 12);
}

// ---------------------------------------------------------------------
// Main config array (keep your existing values here)
// ---------------------------------------------------------------------
return [

    'db_booking' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => '',
        'username' => '',
        'password' => '',      // keep your existing password
        'charset'  => 'utf8mb4',
    ],

    'ldap' => [
        'host'          => 'ldaps://',
        'base_dn'       => '',
        'bind_dn'       => '',
        'bind_password' => '', // keep your existing password
        'ignore_cert'   => true,
    ],

    'auth' => [
        'ldap_enabled' => true,
        'google_oauth_enabled' => false,
        'microsoft_oauth_enabled' => false,
        // Accepts a single CN string or an array of CNs
        'admin_group_cn' => [
            // 'ICT Admins',
            // 'Another Admin Group',
        ],
        'checkout_group_cn' => [
            // 'Checkout Staff',
            // 'Equipment Desk',
        ],
        // Optional: treat these Google accounts as administrators
        'google_admin_emails' => [
            // 'admin@example.com',
        ],
        // Optional: treat these Google accounts as checkout staff
        'google_checkout_emails' => [
            // 'staff@example.com',
        ],
        // Optional: treat these Microsoft accounts as administrators
        'microsoft_admin_emails' => [
            // 'admin@example.com',
        ],
        // Optional: treat these Microsoft accounts as checkout staff
        'microsoft_checkout_emails' => [
            // 'staff@example.com',
        ],
    ],

    'google_oauth' => [
        'client_id'     => '',
        'client_secret' => '',
        // Leave blank to auto-detect the login_process.php callback URL
        'redirect_uri'  => '',
        // Optional restriction to specific Google Workspace domains
        'allowed_domains' => [
            // 'example.com',
        ],
    ],

    // Optional: Google Workspace directory search (requires Admin SDK + service account)
    'google_directory' => [
        // Provide either a raw JSON string or a filesystem path to the JSON file
        'service_account_json' => '',
        'service_account_path' => '',
        // Admin user email to impersonate for directory read access
        'impersonated_user'     => '',
    ],

    'microsoft_oauth' => [
        'client_id'     => '',
        'client_secret' => '',
        // Tenant ID (GUID)
        'tenant'        => '',
        // Leave blank to auto-detect the login_process.php callback URL
        'redirect_uri'  => '',
        // Optional restriction to specific domains
        'allowed_domains' => [
            // 'example.com',
        ],
    ],

    // Optional: Entra directory search (defaults to microsoft_oauth client_id/secret/tenant)
    'entra_directory' => [
        'client_id'     => '',
        'client_secret' => '',
        'tenant'        => '',
    ],

    'app' => [
        'name' => 'KitGrab',
        'timezone' => 'Europe/Jersey',
        'debug'    => true,
        'logo_url' => '', // optional: full URL or relative path to logo image
        'primary_color' => '#660000', // main UI colour for gradients/buttons
        'missed_cutoff_minutes' => 60, // minutes after start time before marking reservation as missed
        'overdue_staff_email' => '', // overdue report recipients (comma/newline separated)
        'overdue_staff_name'  => '', // optional names for recipients (comma/newline separated)
        'block_catalogue_overdue' => true, // block catalogue for users with overdue checkouts
    ],

    'catalogue' => [
        // Restrict which categories appear in the catalogue filter.
        // Leave empty to show all categories.
        'allowed_categories' => [],
    ],

    'smtp' => [
        'host'       => '',
        'port'       => 587,
        'username'   => '',
        'password'   => '',
        'encryption' => 'tls', // none|ssl|tls
        'auth_method'=> 'login', // login|plain|none
        'from_email' => '',
        'from_name'  => 'KitGrab',
    ],
];
