<?php
// cron_mark_missed.php
// Mark reservations as "missed" if they were not checked out within a cutoff window.
//
// Run via cron, e.g.:
//   */10 * * * * /usr/bin/php /path/to/scripts/cron_mark_missed.php >> /var/log/layout_missed.log 2>&1

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';

$config = load_config();
$scriptTz = app_get_timezone($config);
$nowStamp = static function () use ($config, $scriptTz): string {
    return app_format_datetime(time(), $config, $scriptTz);
};
$logOut = static function (string $level, string $message) use ($nowStamp): void {
    fwrite(STDOUT, '[' . $nowStamp() . '] [' . $level . '] ' . $message . PHP_EOL);
};

$appCfg         = $config['app'] ?? [];
$cutoffMinutes  = isset($appCfg['missed_cutoff_minutes']) ? (int)$appCfg['missed_cutoff_minutes'] : 60;
$cutoffMinutes  = max(1, $cutoffMinutes);
$logOut('info', 'cron_mark_missed run started');

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
        $logOut('info', "Updated reservations.status enum to include 'missed'");
    }
} catch (Throwable $e) {
    $logOut('warn', 'Could not verify/alter status column: ' . $e->getMessage());
}

// Use DB server time to avoid PHP/DB drift.
$sql = "
    UPDATE reservations
       SET status = 'missed'
     WHERE status IN ('pending', 'confirmed')
       AND start_datetime < (NOW() - INTERVAL :mins MINUTE)
";

$pdo->beginTransaction();

$selectStmt = $pdo->prepare("
    SELECT id
      FROM reservations
     WHERE status IN ('pending', 'confirmed')
       AND start_datetime < (NOW() - INTERVAL :mins MINUTE)
");
$selectStmt->bindValue(':mins', $cutoffMinutes, PDO::PARAM_INT);
$selectStmt->execute();
$missedIds = $selectStmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare($sql);
$stmt->bindValue(':mins', $cutoffMinutes, PDO::PARAM_INT);
$stmt->execute();

$affected = $stmt->rowCount();

$pdo->commit();

// Build asset summaries per reservation for logging.
$assetsByReservation = [];
$missedIdInts = array_values(array_filter(array_map('intval', $missedIds), static function (int $id): bool {
    return $id > 0;
}));
if (!empty($missedIdInts)) {
    $placeholders = implode(',', array_fill(0, count($missedIdInts), '?'));
    $itemsStmt = $pdo->prepare("
        SELECT reservation_id, model_name_cache, quantity
          FROM reservation_items
         WHERE reservation_id IN ({$placeholders})
    ");
    $itemsStmt->execute($missedIdInts);
    $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $rid = (int)($row['reservation_id'] ?? 0);
        if ($rid <= 0) {
            continue;
        }
        $name = trim((string)($row['model_name_cache'] ?? ''));
        $qty = (int)($row['quantity'] ?? 0);
        if ($name === '') {
            $name = 'Item';
        }
        $label = $qty > 1 ? ($name . ' (x' . $qty . ')') : $name;
        $assetsByReservation[$rid][] = $label;
    }
}

foreach ($missedIds as $missedId) {
    $resId = (int)$missedId;
    if ($resId > 0) {
        activity_log_event('reservation_missed', 'Reservation marked as missed', [
            'subject_type' => 'reservation',
            'subject_id'   => $resId,
            'metadata'     => [
                'assets' => $assetsByReservation[$resId] ?? [],
                'cutoff_minutes' => $cutoffMinutes,
            ],
        ]);
    }
}

$logOut('done', sprintf(
    'Marked %d reservation(s) as missed (cutoff %d minutes)',
    $affected,
    $cutoffMinutes
));
