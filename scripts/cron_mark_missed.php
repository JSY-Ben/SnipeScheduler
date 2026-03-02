<?php
// cron_mark_missed.php
// Mark reservations as "missed" if they were not checked out within a cutoff window.
//
// Run via cron, e.g.:
//   */10 * * * * /usr/bin/php /path/to/scripts/cron_mark_missed.php >> /var/log/layout_missed.log 2>&1

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/email.php';

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
$notifyMissedEnabled = !empty($appCfg['notification_mark_missed_enabled']);
$notifyMissedSendUser = array_key_exists('notification_mark_missed_send_user', $appCfg)
    ? !empty($appCfg['notification_mark_missed_send_user'])
    : true;
$notifyMissedSendCheckoutUsers = array_key_exists('notification_mark_missed_send_checkout_users', $appCfg)
    ? !empty($appCfg['notification_mark_missed_send_checkout_users'])
    : false;
$notifyMissedSendAdmins = array_key_exists('notification_mark_missed_send_admins', $appCfg)
    ? !empty($appCfg['notification_mark_missed_send_admins'])
    : false;
$notifyMissedExtraRecipientsRaw = (string)($appCfg['notification_mark_missed_extra_emails'] ?? '');
$notifyMissedRoleRecipients = [];
if ($notifyMissedSendCheckoutUsers || $notifyMissedSendAdmins) {
    $notifyMissedRoleRecipients = layout_role_notification_recipients(
        $notifyMissedSendCheckoutUsers,
        $notifyMissedSendAdmins,
        $config
    );
}
$emailSent = 0;
$emailFailed = 0;
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
    SELECT id, user_name, user_email, start_datetime, end_datetime
      FROM reservations
     WHERE status IN ('pending', 'confirmed')
       AND start_datetime < (NOW() - INTERVAL :mins MINUTE)
");
$selectStmt->bindValue(':mins', $cutoffMinutes, PDO::PARAM_INT);
$selectStmt->execute();
$missedReservations = $selectStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$missedIds = array_values(array_filter(array_map(static function (array $row): int {
    return (int)($row['id'] ?? 0);
}, $missedReservations), static function (int $id): bool {
    return $id > 0;
}));

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

foreach ($missedReservations as $reservation) {
    $resId = (int)($reservation['id'] ?? 0);
    if ($resId <= 0) {
        continue;
    }

    $assetSummary = $assetsByReservation[$resId] ?? [];
    activity_log_event('reservation_missed', 'Reservation marked as missed', [
        'subject_type' => 'reservation',
        'subject_id'   => $resId,
        'metadata'     => [
            'assets' => $assetSummary,
            'cutoff_minutes' => $cutoffMinutes,
        ],
    ]);

    if (!$notifyMissedEnabled) {
        continue;
    }

    $startDisplay = app_format_datetime((string)($reservation['start_datetime'] ?? ''), $config, $scriptTz);
    $endDisplay = app_format_datetime((string)($reservation['end_datetime'] ?? ''), $config, $scriptTz);
    $itemsDisplay = !empty($assetSummary) ? implode(', ', $assetSummary) : 'No reservation item details available';
    $userEmail = trim((string)($reservation['user_email'] ?? ''));
    $userName = trim((string)($reservation['user_name'] ?? ''));
    $userDisplay = $userName !== '' ? $userName : ($userEmail !== '' ? $userEmail : 'Unknown user');
    if ($userEmail !== '' && $userName !== '' && strcasecmp($userName, $userEmail) !== 0) {
        $userDisplay .= " ({$userEmail})";
    }

    $bodyBase = [
        "Reservation #{$resId} has been marked as missed.",
        "Scheduled start: {$startDisplay}",
        "Scheduled end: {$endDisplay}",
        "Items: {$itemsDisplay}",
        "Missed cutoff: {$cutoffMinutes} minute(s) after the start time.",
    ];
    $adminBody = array_merge($bodyBase, ["Reserved for: {$userDisplay}"]);
    $reservationLinkLine = layout_reservation_link_line($resId, $config);
    if ($reservationLinkLine !== null) {
        $bodyBase[] = $reservationLinkLine;
        $adminBody[] = $reservationLinkLine;
    }
    $notifiedEmails = [];
    $notifiedEmailKeys = [];

    if ($notifyMissedSendUser && $userEmail !== '') {
        $sent = layout_send_notification(
            $userEmail,
            $userName !== '' ? $userName : $userEmail,
            'Reservation marked as missed',
            $bodyBase,
            $config
        );
        if ($sent) {
            $emailSent++;
            $notifiedEmails[] = $userEmail;
            $notifiedEmailKeys[strtolower($userEmail)] = true;
        } else {
            $emailFailed++;
            $logOut('warn', "Failed to send missed reservation email to {$userEmail} for reservation #{$resId}");
        }
    }

    foreach ($notifyMissedRoleRecipients as $recipient) {
        $recipientEmail = trim((string)($recipient['email'] ?? ''));
        $recipientKey = strtolower($recipientEmail);
        if ($recipientKey === '' || isset($notifiedEmailKeys[$recipientKey])) {
            continue;
        }

        $sent = layout_send_notification(
            $recipientEmail,
            (string)($recipient['name'] ?? $recipientEmail),
            'Reservation marked as missed',
            $adminBody,
            $config
        );
        if ($sent) {
            $emailSent++;
            $notifiedEmails[] = $recipientEmail;
            $notifiedEmailKeys[$recipientKey] = true;
        } else {
            $emailFailed++;
            $logOut('warn', "Failed to send missed reservation email to {$recipientEmail} for reservation #{$resId}");
        }
    }

    $extraRecipients = layout_extra_notification_recipients($notifyMissedExtraRecipientsRaw, $notifiedEmails);
    foreach ($extraRecipients as $recipient) {
        $recipientEmail = trim((string)($recipient['email'] ?? ''));
        if ($recipientEmail === '') {
            continue;
        }
        $sent = layout_send_notification(
            $recipientEmail,
            (string)($recipient['name'] ?? $recipientEmail),
            'Reservation marked as missed',
            $adminBody,
            $config
        );
        if ($sent) {
            $emailSent++;
        } else {
            $emailFailed++;
            $logOut('warn', "Failed to send missed reservation email to {$recipientEmail} for reservation #{$resId}");
        }
    }
}

$summary = sprintf(
    'Marked %d reservation(s) as missed (cutoff %d minutes)',
    $affected,
    $cutoffMinutes
);
if ($notifyMissedEnabled) {
    $summary .= sprintf('; emails sent %d, failed %d', $emailSent, $emailFailed);
}
$logOut('done', $summary);
