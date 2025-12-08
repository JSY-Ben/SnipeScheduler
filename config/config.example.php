<?php
/**
 * Global configuration for the Snipe-IT booking app.
 *
 * Edit the values below to match your environment.
 *
 * Copy this file to config/config.php and keep your secrets out of version control.
 */

/**
 * Paging / limits for catalogue + Snipe-IT API
 * These run as soon as config.php is required.
 */
if (!defined('SNIPEIT_API_PAGE_LIMIT')) {
    define('SNIPEIT_API_PAGE_LIMIT', 12); // or whatever default you want
}

if (!defined('CATALOGUE_ITEMS_PER_PAGE')) {
    define('CATALOGUE_ITEMS_PER_PAGE', SNIPEIT_API_PAGE_LIMIT);
}

/**
 * Maximum number of models that will ever be fetched from Snipe-IT
 * in one catalogue request (safety cap).
 *
 * This is used so sorting can be done globally before pagination.
 */
if (!defined('SNIPEIT_MAX_MODELS_FETCH')) {
    define('SNIPEIT_MAX_MODELS_FETCH', 1000);
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

    'snipeit' => [
        'base_url'  => '',
        'api_token' => '',     // keep your existing token
        'verify_ssl' => false,
    ],

    'ldap' => [
        'host'          => 'ldaps://',
        'base_dn'       => '',
        'bind_dn'       => '',
        'bind_password' => '', // keep your existing password
        'ignore_cert'   => true,
    ],

    'auth' => [
        // Accepts a single CN string or an array of CNs
        'staff_group_cn' => [
            // 'ICT Staff',
            // 'Another Staff Group',
        ],
    ],

    'app' => [
        'timezone' => 'Europe/Jersey',
        'debug'    => true,
        'logo_url' => '', // optional: full URL or relative path to logo image
        'primary_color' => '#660000', // main UI colour for gradients/buttons
        'missed_cutoff_minutes' => 60, // minutes after start time before marking reservation as missed
        'api_cache_ttl_seconds' => 60, // cache Snipe-IT GET responses for this many seconds
    ],

    'catalogue' => [
        // Restrict which categories appear in the catalogue filter.
        // Leave empty to show all categories returned by Snipe-IT.
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
        'from_name'  => 'ReserveIT',
    ],
];
