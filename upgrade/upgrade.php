<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$isAdmin = !empty($currentUser['is_admin']);
if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$versionFile = APP_ROOT . '/version.txt';
$currentVersion = is_file($versionFile) ? trim((string)@file_get_contents($versionFile)) : 'unknown';

$upgradeDir = __DIR__;
$upgradeFiles = glob($upgradeDir . '/*.sql');
sort($upgradeFiles);

$appliedVersions = [];
$loadError = '';
try {
    $rows = $pdo->query('SELECT version FROM schema_version ORDER BY applied_at ASC')->fetchAll(PDO::FETCH_COLUMN);
    $appliedVersions = array_map('strval', $rows);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$pending = [];
foreach ($upgradeFiles as $file) {
    $base = basename($file, '.sql');
    if (!in_array($base, $appliedVersions, true)) {
        $pending[] = [
            'version' => $base,
            'path' => $file,
        ];
    }
}

$messages = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    if (empty($pending)) {
        $messages[] = 'No pending upgrade scripts found.';
    } else {
        foreach ($pending as $item) {
            $version = $item['version'];
            $path = $item['path'];
            $sql = is_file($path) ? file_get_contents($path) : '';
            if ($sql === '') {
                $errors[] = "Upgrade file {$version} is empty or missing.";
                continue;
            }

            try {
                $pdo->beginTransaction();
                $pdo->exec($sql);
                $stmt = $pdo->prepare('INSERT IGNORE INTO schema_version (version) VALUES (:version)');
                $stmt->execute([':version' => $version]);
                $pdo->commit();
                $messages[] = "Applied upgrade {$version}.";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "Failed to apply {$version}: " . $e->getMessage();
            }
        }
    }

    $appliedVersions = [];
    try {
        $rows = $pdo->query('SELECT version FROM schema_version ORDER BY applied_at ASC')->fetchAll(PDO::FETCH_COLUMN);
        $appliedVersions = array_map('strval', $rows);
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
    }

    $pending = [];
    foreach ($upgradeFiles as $file) {
        $base = basename($file, '.sql');
        if (!in_array($base, $appliedVersions, true)) {
            $pending[] = [
                'version' => $base,
                'path' => $file,
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upgrade Database – SnipeScheduler</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../public/assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Database Upgrade</h1>
            <div class="page-subtitle">
                Current app version: <?= h($currentVersion) ?>
            </div>
        </div>

        <?php if (!empty($messages)): ?>
            <div class="alert alert-success">
                <ul class="mb-0">
                    <?php foreach ($messages as $msg): ?>
                        <li><?= h($msg) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== ''): ?>
            <div class="alert alert-warning">
                Could not load schema_version table: <?= h($loadError) ?>
            </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">Pending upgrades</h5>
                <?php if (empty($pending)): ?>
                    <div class="text-muted">No pending upgrade scripts.</div>
                <?php else: ?>
                    <ul class="mb-0">
                        <?php foreach ($pending as $item): ?>
                            <li><?= h($item['version']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="run">
            <button class="btn btn-primary" type="submit" <?= empty($pending) ? 'disabled' : '' ?>>
                Run pending upgrades
            </button>
        </form>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
