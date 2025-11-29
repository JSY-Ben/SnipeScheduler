<?php

require_once __DIR__ . '/config.php';

// auth.php
// Simple authentication guard used by all protected pages.

session_start();

$script = basename($_SERVER['PHP_SELF']);

// If no logged-in user, redirect to login.php (except on login pages themselves)
if (empty($_SESSION['user'])) {
    if (!in_array($script, ['login.php', 'login_process.php'], true)) {
        header('Location: login.php');
        exit;
    }
    // On login pages, do nothing more
    return;
}

// User is logged in – expose as $currentUser for the including script
$currentUser = $_SESSION['user'];

// Global HTML output helper:
//  - Decodes any existing entities (e.g. &quot;) so they show as "
//  - Then safely escapes once for HTML output.
if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars(
            htmlspecialchars_decode($value ?? '', ENT_QUOTES),
            ENT_QUOTES
        );
    }
}
