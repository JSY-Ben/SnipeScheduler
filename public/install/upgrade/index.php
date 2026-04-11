<?php
require_once __DIR__ . '/../../../src/bootstrap.php';
if (!defined('AUTH_LOGIN_PATH')) {
    define('AUTH_LOGIN_PATH', '../../login.php');
}
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/config_writer.php';

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

$configPath = CONFIG_PATH . '/config.php';
$legacyConfigPath = APP_ROOT . '/config.php';
$configFile = is_file($configPath) ? $configPath : (is_file($legacyConfigPath) ? $legacyConfigPath : '');
$config = [];
if ($configFile !== '') {
    try {
        $config = require $configFile;
        if (!is_array($config)) {
            $config = [];
        }
    } catch (Throwable $e) {
        $config = [];
    }
}

function upgrade_dump_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function upgrade_dump_value(PDO $pdo, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return $pdo->quote((string)$value);
}

function upgrade_dump_table_order(PDO $pdo, string $databaseName, array $tables): array
{
    $orderedTables = [];
    $tables = array_values(array_filter(array_map('strval', $tables), 'strlen'));
    $dependencies = [];

    foreach ($tables as $table) {
        $dependencies[$table] = [];
    }

    if ($databaseName !== '') {
        try {
            $stmt = $pdo->prepare("
                SELECT TABLE_NAME, REFERENCED_TABLE_NAME
                  FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = :schema
                   AND REFERENCED_TABLE_SCHEMA = :schema
                   AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            $stmt->execute([':schema' => $databaseName]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $table = (string)($row['TABLE_NAME'] ?? '');
                $refTable = (string)($row['REFERENCED_TABLE_NAME'] ?? '');
                if ($table === '' || $refTable === '') {
                    continue;
                }
                if (!isset($dependencies[$table]) || !isset($dependencies[$refTable])) {
                    continue;
                }
                $dependencies[$table][$refTable] = true;
            }
        } catch (Throwable $e) {
            return $tables;
        }
    }

    $temporary = [];
    $permanent = [];
    $visit = static function (string $table) use (&$visit, &$dependencies, &$temporary, &$permanent, &$orderedTables): void {
        if (isset($permanent[$table])) {
            return;
        }
        if (isset($temporary[$table])) {
            return;
        }

        $temporary[$table] = true;
        foreach (array_keys($dependencies[$table] ?? []) as $dependency) {
            $visit($dependency);
        }
        unset($temporary[$table]);

        $permanent[$table] = true;
        $orderedTables[] = $table;
    };

    foreach ($tables as $table) {
        $visit($table);
    }

    return array_values(array_unique($orderedTables));
}

function upgrade_stream_database_backup(PDO $pdo, string $databaseName, string $appName): void
{
    $timestamp = date('Ymd-His');
    $safeDbName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $databaseName);
    $safeDbName = $safeDbName !== '' ? $safeDbName : 'database';
    $fileName = $safeDbName . '-backup-' . $timestamp . '.sql';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $databaseEsc = str_replace(["\r", "\n"], ' ', $databaseName);
    $appNameEsc = str_replace(["\r", "\n"], ' ', $appName);

    echo "-- {$appNameEsc} booking database backup\n";
    echo "-- Database: {$databaseEsc}\n";
    echo '-- Generated: ' . date('Y-m-d H:i:s') . "\n\n";
    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    echo "SET AUTOCOMMIT = 0;\n";
    echo "START TRANSACTION;\n";
    echo "SET time_zone = '+00:00';\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $tables = upgrade_dump_table_order($pdo, $databaseName, $tables);
    foreach ($tables as $tableName) {
        $tableName = (string)$tableName;
        if ($tableName === '') {
            continue;
        }

        $tableRef = upgrade_dump_identifier($tableName);
        $createStmt = $pdo->query('SHOW CREATE TABLE ' . $tableRef)->fetch(PDO::FETCH_ASSOC);
        if (!$createStmt) {
            continue;
        }

        $createSql = '';
        foreach ($createStmt as $key => $value) {
            if (stripos((string)$key, 'create table') !== false) {
                $createSql = (string)$value;
                break;
            }
        }
        if ($createSql === '') {
            continue;
        }

        echo "--\n";
        echo "-- Table structure for table {$tableName}\n";
        echo "--\n\n";
        echo "DROP TABLE IF EXISTS {$tableRef};\n";
        echo $createSql . ";\n\n";

        $rows = $pdo->query('SELECT * FROM ' . $tableRef);
        if (!$rows) {
            continue;
        }

        $rowCount = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row)) {
                continue;
            }

            if ($rowCount === 0) {
                echo "--\n";
                echo "-- Dumping data for table {$tableName}\n";
                echo "--\n\n";
            }

            $columns = [];
            $values = [];
            foreach ($row as $column => $value) {
                if (is_int($column)) {
                    continue;
                }
                $columns[] = upgrade_dump_identifier((string)$column);
                $values[] = upgrade_dump_value($pdo, $value);
            }

            if (!empty($columns)) {
                echo 'INSERT INTO ' . $tableRef
                    . ' (' . implode(', ', $columns) . ') VALUES ('
                    . implode(', ', $values) . ");\n";
                $rowCount++;
            }
        }

        if ($rowCount > 0) {
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    echo "COMMIT;\n";
}

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
$appNameEsc = htmlspecialchars(layout_app_name($config), ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'download_backup') {
        try {
            $databaseName = trim((string)($config['db_booking']['dbname'] ?? ''));
            if ($databaseName === '') {
                $databaseName = 'booking-database';
            }
            upgrade_stream_database_backup($pdo, $databaseName, layout_app_name($config));
            exit;
        } catch (Throwable $e) {
            $errors[] = 'Could not generate database backup: ' . $e->getMessage();
        }
    } elseif ($action === 'run') {
        if (empty($pending)) {
            $messages[] = 'No pending upgrade scripts found.';
        } else {
            foreach ($pending as $item) {
                $version = $item['version'];
                $path = $item['path'];

                $phpPath = $upgradeDir . '/upgrade_' . $version . '.php';
                if (is_file($phpPath)) {
                    require_once $phpPath;
                    $fnName = 'upgrade_apply_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $version);
                    if (function_exists($fnName)) {
                        $config = $fnName($configFile, $config, $messages, $errors);
                    }
                }

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
                    if ($pdo->inTransaction()) {
                        $pdo->commit();
                    }
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
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Upgrade Database – <?= $appNameEsc ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../../assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= str_replace('href="index.php"', 'href="../../index.php"', layout_logo_tag()) ?>
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
                <h5 class="card-title">Backup current booking database</h5>
                <p class="text-muted mb-3">
                    Download a `.sql` backup of the configured SnipeScheduler booking database before applying upgrades.
                    This does not export the live Snipe-IT database.
                </p>
                <form method="post" class="mb-0">
                    <input type="hidden" name="action" value="download_backup">
                    <button class="btn btn-outline-secondary" type="submit">
                        Download SQL backup
                    </button>
                </form>
            </div>
        </div>

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
