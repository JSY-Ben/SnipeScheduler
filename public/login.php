<?php
// login.php
require_once __DIR__ . '/../src/bootstrap.php';
session_start();
require_once SRC_PATH . '/footer.php';

$config = [];
try {
    $config = load_config();
} catch (Throwable $e) {
    $config = [];
}

$authCfg   = $config['auth'] ?? [];
$googleCfg = $config['google_oauth'] ?? [];
$ldapEnabled   = array_key_exists('ldap_enabled', $authCfg) ? !empty($authCfg['ldap_enabled']) : true;
$googleEnabled = !empty($authCfg['google_oauth_enabled']);
$showGoogle    = $googleEnabled && !empty($googleCfg['client_id']);
$showLdap      = $ldapEnabled;

// Show any previous error
$loginError = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

// Already logged in? Go to dashboard
if (!empty($_SESSION['user']['email'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Equipment Booking – Login</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= reserveit_theme_styles($config) ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell" style="max-width: 480px; margin: 0 auto;">
        <?= reserveit_logo_tag($config) ?>
        <div class="page-header">
            <h1>Sign in</h1>
            <div class="page-subtitle">
                Choose an available sign-in option below.
            </div>
        </div>

        <?php if ($loginError): ?>
            <div class="alert alert-danger white-space-prewrap">
                <?= nl2br(htmlspecialchars($loginError)) ?>
            </div>
        <?php endif; ?>

        <?php if ($showGoogle): ?>
            <a href="login_process.php?provider=google" class="btn btn-outline-dark w-100 mb-3">
                Sign in with Google
            </a>
        <?php endif; ?>

        <?php if ($showLdap): ?>
            <form method="post" action="login_process.php" class="card p-3 mt-3">
                <input type="hidden" name="provider" value="ldap">
                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <input type="email"
                           class="form-control"
                           id="email"
                           name="email"
                           autocomplete="email"
                           required>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password"
                           class="form-control"
                           id="password"
                           name="password"
                           autocomplete="current-password"
                           required>
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    Sign in
                </button>
            </form>
        <?php endif; ?>

        <?php if (!$showGoogle && !$showLdap): ?>
            <div class="alert alert-warning mt-3">
                No authentication methods are enabled. Please contact an administrator.
            </div>
        <?php endif; ?>
    </div>
</div>
<?php reserveit_footer(); ?>
</body>
</html>
