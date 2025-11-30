<?php
// cron_mark_missed.php
// Mark reservations as "missed" if they were not checked out within a cutoff window.
//
// Run via cron, e.g.:
//   */10 * * * * /usr/bin/php /path/to/cron_mark_missed.php >> /var/log/reserveit_missed.log 2>&1

require_once __DIR__ . '/db.php';
$config = require __DIR__ . '/config.php';

$appCfg         = $config['app'] ?? [];
$cutoffMinutes  = isset($appCfg['missed_cutoff_minutes']) ? (int)$appCfg['missed_cutoff_minutes'] : 60;
$cutoffMinutes  = max(1, $cutoffMinutes);

// Ensure the status column includes 'missed' in the ENUM definition.
try {
    $col = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $type = $col['Type'] ?? '';
    if ($type !== '' && stripos($type, 'missed') === false) {
        $pdo->exec("
            ALTER TABLE reservations
            MODIFY status ENUM('pending','confirmed','completed','cancelled','missed')
            NOT NULL DEFAULT 'pending'
        ");
        echo "[" . date('Y-m-d H:i:s') . "] Updated reservations.status enum to include 'missed'\n";
    }
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Warning: could not verify/alter status column: " . $e->getMessage() . "\n";
}

// Use DB server time to avoid PHP/DB drift.
$sql = "
    UPDATE reservations
       SET status = 'missed'
     WHERE status IN ('pending', 'confirmed')
       AND start_datetime < (NOW() - INTERVAL :mins MINUTE)
";

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':mins', $cutoffMinutes, PDO::PARAM_INT);
$stmt->execute();

$affected = $stmt->rowCount();

echo sprintf(
    "[%s] Marked %d reservation(s) as missed (cutoff %d minutes)\n",
    date('Y-m-d H:i:s'),
    $affected,
    $cutoffMinutes
);
