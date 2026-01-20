<?php
require_once __DIR__ . '/../../src/bootstrap.php';

$configPath = CONFIG_PATH . '/config.php';
$legacyConfigPath = APP_ROOT . '/config.php';
$installed = is_file($configPath) || is_file($legacyConfigPath);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install – SnipeScheduler</title>
    <link rel="stylesheet" href="../assets/style.css">
    <?php if (function_exists('layout_theme_styles')): ?>
        <?= layout_theme_styles() ?>
    <?php endif; ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <div class="page-header">
            <h1>Installer</h1>
        </div>

        <?php if ($installed): ?>
            <div class="alert alert-success">
                This installation already has a config.php.
            </div>
            <p>Do you want to run the database upgrade?</p>
            <a class="btn btn-primary" href="upgrade/">Go to upgrade</a>
        <?php else: ?>
            <div class="alert alert-warning">
                No config.php found. Continue with installation.
            </div>
            <a class="btn btn-primary" href="install.php">Run installer</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
