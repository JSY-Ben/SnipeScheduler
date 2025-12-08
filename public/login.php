<?php
// login.php
require_once __DIR__ . '/../src/bootstrap.php';
session_start();
require_once SRC_PATH . '/footer.php';

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
    <?= reserveit_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell" style="max-width: 480px; margin: 0 auto;">
        <?= reserveit_logo_tag() ?>
        <div class="page-header">
            <h1>Sign in</h1>
            <div class="page-subtitle">
                Use your college email address and password.
            </div>
        </div>

        <?php if ($loginError): ?>
            <div class="alert alert-danger white-space-prewrap">
                <?= nl2br(htmlspecialchars($loginError)) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login_process.php" class="card p-3">
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
    </div>
</div>
<?php reserveit_footer(); ?>
</body>
</html>
