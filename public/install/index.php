<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once APP_ROOT . '/src/layout.php';

$configPath = CONFIG_PATH . '/config.php';
$legacyConfigPath = APP_ROOT . '/config.php';
$installed = is_file($configPath) || is_file($legacyConfigPath);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install – KitGrab</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/style.css">
    <?= layout_theme_styles() ?>
    <style>
        body { background: #f7f9fc; }
        .installer-page {
            max-width: 760px;
            margin: 0 auto;
        }
    </style>
</head>
<body class="p-4">
<div class="container installer-page">
    <div class="page-shell">
        <?= str_replace('href="index.php"', 'href="../index.php"', layout_logo_tag()) ?>
        <div class="page-header">
            <h1>KitGrab Installer</h1>
            <div class="page-subtitle">
                Set up a new installation. Remove or protect these tools after use.
            </div>
        </div>

        <?php if ($installed): ?>
            <div class="alert alert-success">
                This installation already has a config.php.
            </div>
            <div class="card">
                <div class="card-body">
                    <p class="mb-3">If you need to reinstall, run the installer again.</p>
                    <a class="btn btn-outline-danger" href="install.php">Run installer again</a>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                No config.php found. Continue with installation.
            </div>
            <div class="card">
                <div class="card-body">
                    <a class="btn btn-primary" href="install.php">Run installer</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
