<?php
require_once __DIR__ . '/bootstrap.php';

// auth.php
// Simple authentication guard used by all protected pages.

session_start();

$script = basename($_SERVER['PHP_SELF']);
$scriptPath = trim(str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? '')), '/');
$loginPath = defined('AUTH_LOGIN_PATH') ? AUTH_LOGIN_PATH : 'login.php';
$loginProcessPath = defined('AUTH_LOGIN_PROCESS_PATH') ? AUTH_LOGIN_PROCESS_PATH : 'login_process.php';
$isAuthenticated = !empty($_SESSION['user']);
$isPublicGuestAccess = false;

$config = [];
try {
    $config = load_config();
} catch (Throwable $e) {
    $config = [];
}

$catalogueCfg = $config['catalogue'] ?? [];
$allowPublicCatalogueView = array_key_exists('allow_public_view', $catalogueCfg)
    ? !empty($catalogueCfg['allow_public_view'])
    : false;
$isRootAppScript = (bool)preg_match('#^(index|catalogue)\.php$#', $scriptPath)
    || (bool)preg_match('#(?:^|/)public/(index|catalogue)\.php$#', $scriptPath);
$isGuestAllowedPage = $allowPublicCatalogueView
    && $isRootAppScript
    && in_array($script, ['index.php', 'catalogue.php'], true);

// If no logged-in user, redirect to login.php (except allowed guest pages and login pages).
if (!$isAuthenticated) {
    if (
        !$isGuestAllowedPage
        && !in_array($script, [basename($loginPath), basename($loginProcessPath)], true)
    ) {
        header('Location: ' . $loginPath);
        exit;
    }

    if ($isGuestAllowedPage) {
        $isPublicGuestAccess = true;
    }

    // Expose a safe empty user shape for guest-access pages.
    $currentUser = [
        'id' => 0,
        'email' => '',
        'username' => '',
        'display_name' => 'Guest',
        'first_name' => 'Guest',
        'last_name' => '',
        'is_staff' => false,
        'is_admin' => false,
    ];
} else {
    // User is logged in – expose as $currentUser for the including script
    $currentUser = $_SESSION['user'];
}

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
