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
$msCfg     = $config['microsoft_oauth'] ?? [];
$ldapEnabled   = array_key_exists('ldap_enabled', $authCfg) ? !empty($authCfg['ldap_enabled']) : true;
$googleEnabled = !empty($authCfg['google_oauth_enabled']);
$msEnabled     = !empty($authCfg['microsoft_oauth_enabled']);
$showGoogle    = $googleEnabled && !empty($googleCfg['client_id']);
$showMicrosoft = $msEnabled && !empty($msCfg['client_id']);
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

                <?php if ($showGoogle): ?>
                    <a href="login_process.php?provider=google" class="btn btn-outline-dark w-100 mt-3 d-flex align-items-center justify-content-center gap-2">
                        <svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 533.5 544.3">
                            <path fill="#EA4335" d="M533.5 278.4c0-18.6-1.5-37.3-4.8-55.5H272v105h147.6c-6.3 34-25 62.8-53.3 82.1l86.2 67.2c50.6-46.6 80-115.3 80-198.8z"/>
                            <path fill="#34A853" d="M272 544.3c71.8 0 132-23.5 176-64.1l-86.2-67.2c-24 16.4-54.8 26-89.8 26-69 0-127.5-46.5-148.4-108.9l-90 69.4C72.8 483.3 163.1 544.3 272 544.3z"/>
                            <path fill="#4A90E2" d="M123.6 330.1c-10.8-32.5-10.8-67.7 0-100.2l-90-69.4c-39.2 78.4-39.2 170.6 0 249.1l90-69.5z"/>
                            <path fill="#FBBC05" d="M272 106.1c37.8-.6 74.2 13 102 38.2l76.1-76.1C403.9 24.8 339.7.5 272 1 163.1 1 72.8 62 33.6 160.5l90 69.4C144.6 152.6 203 106.1 272 106.1z"/>
                        </svg>
                        <span>Sign in with Google</span>
                    </a>
                <?php endif; ?>

                <?php if ($showMicrosoft): ?>
                    <a href="login_process.php?provider=microsoft" class="btn btn-outline-dark w-100 mt-2 d-flex align-items-center justify-content-center gap-2">
                        <svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 23 23">
                            <rect width="10.5" height="10.5" x="0.5" y="0.5" fill="#F35325"/>
                            <rect width="10.5" height="10.5" x="12" y="0.5" fill="#81BC06"/>
                            <rect width="10.5" height="10.5" x="0.5" y="12" fill="#05A6F0"/>
                            <rect width="10.5" height="10.5" x="12" y="12" fill="#FFBA08"/>
                        </svg>
                        <span>Sign in with Microsoft</span>
                    </a>
                <?php endif; ?>
            </form>
        <?php elseif ($showGoogle || $showMicrosoft): ?>
            <?php if ($showGoogle): ?>
                <a href="login_process.php?provider=google" class="btn btn-outline-dark w-100 mt-3 d-flex align-items-center justify-content-center gap-2">
                    <svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 533.5 544.3">
                        <path fill="#EA4335" d="M533.5 278.4c0-18.6-1.5-37.3-4.8-55.5H272v105h147.6c-6.3 34-25 62.8-53.3 82.1l86.2 67.2c50.6-46.6 80-115.3 80-198.8z"/>
                        <path fill="#34A853" d="M272 544.3c71.8 0 132-23.5 176-64.1l-86.2-67.2c-24 16.4-54.8 26-89.8 26-69 0-127.5-46.5-148.4-108.9l-90 69.4C72.8 483.3 163.1 544.3 272 544.3z"/>
                        <path fill="#4A90E2" d="M123.6 330.1c-10.8-32.5-10.8-67.7 0-100.2l-90-69.4c-39.2 78.4-39.2 170.6 0 249.1l90-69.5z"/>
                        <path fill="#FBBC05" d="M272 106.1c37.8-.6 74.2 13 102 38.2l76.1-76.1C403.9 24.8 339.7.5 272 1 163.1 1 72.8 62 33.6 160.5l90 69.4C144.6 152.6 203 106.1 272 106.1z"/>
                    </svg>
                    <span>Sign in with Google</span>
                </a>
            <?php endif; ?>
            <?php if ($showMicrosoft): ?>
                <a href="login_process.php?provider=microsoft" class="btn btn-outline-dark w-100 mt-2 d-flex align-items-center justify-content-center gap-2">
                    <svg aria-hidden="true" focusable="false" width="20" height="20" viewBox="0 0 23 23">
                        <rect width="10.5" height="10.5" x="0.5" y="0.5" fill="#F35325"/>
                        <rect width="10.5" height="10.5" x="12" y="0.5" fill="#81BC06"/>
                        <rect width="10.5" height="10.5" x="0.5" y="12" fill="#05A6F0"/>
                        <rect width="10.5" height="10.5" x="12" y="12" fill="#FFBA08"/>
                    </svg>
                    <span>Sign in with Microsoft</span>
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$showGoogle && !$showMicrosoft && !$showLdap): ?>
            <div class="alert alert-warning mt-3">
                No authentication methods are enabled. Please contact an administrator.
            </div>
        <?php endif; ?>
    </div>
</div>
<?php reserveit_footer(); ?>
</body>
</html>
