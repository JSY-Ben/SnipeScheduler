<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/reservation_policy.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/layout.php';

$userOverride = $_SESSION['booking_user_override'] ?? null;
$user   = $userOverride ?: $currentUser;
$basket = $_SESSION['basket'] ?? [];

if (empty($basket)) {
    die('Your basket is empty.');
}

$startRaw = $_POST['start_datetime'] ?? '';
$endRaw   = $_POST['end_datetime'] ?? '';

if (!$startRaw || !$endRaw) {
    die('Start and end date/time are required.');
}

$startTs = strtotime($startRaw);
$endTs   = strtotime($endRaw);

if ($startTs === false || $endTs === false) {
    die('Invalid date/time.');
}

$start = date('Y-m-d H:i:s', $startTs);
$end   = date('Y-m-d H:i:s', $endTs);

if ($end <= $start) {
    die('End time must be after start time.');
}

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$overrideEmail = strtolower(trim((string)($userOverride['email'] ?? '')));
$currentEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
$isOnBehalfBooking = is_array($userOverride) && $overrideEmail !== '' && $overrideEmail !== $currentEmail;

// Build user info from Snipe-IT user record
$userName  = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
if ($userName === '') {
    $userName = (string)($user['email'] ?? 'Unknown user');
}
$userEmail = (string)($user['email'] ?? '');
$userId    = (string)($user['id'] ?? ''); // Snipe-IT user id

$reservationPolicy = reservation_policy_get($config);
$policyViolations = reservation_policy_validate_booking($pdo, $reservationPolicy, [
    'start_ts' => $startTs,
    'end_ts' => $endTs,
    'target_user_id' => $userId,
    'target_user_email' => $userEmail,
    'is_admin' => $isAdmin,
    'is_staff' => $isStaff,
    'is_on_behalf' => $isOnBehalfBooking,
]);
if (!empty($policyViolations)) {
    die('Could not create booking: ' . htmlspecialchars($policyViolations[0]));
}

$pdo->beginTransaction();

try {
    $models = [];
    $totalRequestedItems = 0;

    foreach ($basket as $modelId => $qty) {
        $modelId = (int)$modelId;
        $qty     = (int)$qty;

        if ($modelId <= 0 || $qty < 1) {
            throw new Exception('Invalid model/quantity in basket.');
        }

        $model = get_model($modelId);
        if (empty($model['id'])) {
            throw new Exception('Model not found in Snipe-IT: ID ' . $modelId);
        }

        // How many units of this model are already booked for this time range?
        $sql = "
            SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
            FROM reservation_items ri
            JOIN reservations r ON r.id = ri.reservation_id
            WHERE ri.model_id = :model_id
              AND r.status IN ('pending','confirmed')
              AND (r.start_datetime < :end AND r.end_datetime > :start)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':model_id' => $modelId,
            ':start'    => $start,
            ':end'      => $end,
        ]);
        $row = $stmt->fetch();
        $existingBooked = $row ? (int)$row['booked_qty'] : 0;

        // Total requestable units in Snipe-IT
        $totalRequestable = count_requestable_assets_by_model($modelId);
        $activeCheckedOut = count_checked_out_assets_by_model($modelId);
        $availableNow = $totalRequestable > 0 ? max(0, $totalRequestable - $activeCheckedOut) : 0;

        if ($totalRequestable > 0 && $existingBooked + $qty > $availableNow) {
            throw new Exception(
                'Not enough units available for "' . ($model['name'] ?? ('ID '.$modelId)) . '" '
                . 'in that time period. Requested ' . $qty . ', already booked ' . $existingBooked
                . ', total available ' . $availableNow . '.'
            );
        }

        $models[] = [
            'model' => $model,
            'qty'   => $qty,
        ];
        $totalRequestedItems += $qty;
    }

    // Reservation header summary text
    if (!empty($models)) {
        $firstName = $models[0]['model']['name'] ?? 'Multiple models';
    } else {
        $firstName = 'Multiple models';
    }

    $label = $firstName;
    if ($totalRequestedItems > 1) {
        $label .= ' +' . ($totalRequestedItems - 1) . ' more item(s)';
    }

    $insertRes = $pdo->prepare("
        INSERT INTO reservations (
            user_name, user_email, user_id, snipeit_user_id,
            asset_id, asset_name_cache,
            start_datetime, end_datetime, status
        ) VALUES (
            :user_name, :user_email, :user_id, :snipeit_user_id,
            0, :asset_name_cache,
            :start_datetime, :end_datetime, 'pending'
        )
    ");
    $insertRes->execute([
        ':user_name'        => $userName,
        ':user_email'       => $userEmail,
        ':user_id'          => $userId,
        ':snipeit_user_id'  => $user['id'],
        ':asset_name_cache' => 'Pending checkout',
        ':start_datetime'   => $start,
        ':end_datetime'     => $end,
    ]);

    $reservationId = (int)$pdo->lastInsertId();

    // Insert reservation_items as model-level rows with quantity
    $insertItem = $pdo->prepare("
        INSERT INTO reservation_items (
            reservation_id, model_id, model_name_cache, quantity
        ) VALUES (
            :reservation_id, :model_id, :model_name_cache, :quantity
        )
    ");

    foreach ($models as $entry) {
        $model = $entry['model'];
        $qty   = (int)$entry['qty'];

        $insertItem->execute([
            ':reservation_id'   => $reservationId,
            ':model_id'         => (int)$model['id'],
            ':model_name_cache' => $model['name'] ?? ('Model #' . $model['id']),
            ':quantity'         => $qty,
        ]);
    }

    $pdo->commit();
    $_SESSION['basket'] = []; // clear basket

    activity_log_event('reservation_submitted', 'Reservation submitted', [
        'subject_type' => 'reservation',
        'subject_id'   => $reservationId,
        'metadata'     => [
            'items'     => $totalRequestedItems,
            'start'     => $start,
            'end'       => $end,
            'booked_for'=> $userEmail,
        ],
    ]);

    $appCfg = $config['app'] ?? [];
    $notifyEnabled = array_key_exists('notification_reservation_submitted_enabled', $appCfg)
        ? !empty($appCfg['notification_reservation_submitted_enabled'])
        : true;
    if ($notifyEnabled) {
        $sendUserDefault = array_key_exists('notification_reservation_submitted_send_user', $appCfg)
            ? !empty($appCfg['notification_reservation_submitted_send_user'])
            : true;
        $legacySendStaffDefault = array_key_exists('notification_reservation_submitted_send_staff', $appCfg)
            ? !empty($appCfg['notification_reservation_submitted_send_staff'])
            : true;
        $sendCheckoutUsersDefault = array_key_exists('notification_reservation_submitted_send_checkout_users', $appCfg)
            ? !empty($appCfg['notification_reservation_submitted_send_checkout_users'])
            : $legacySendStaffDefault;
        $sendAdminsDefault = array_key_exists('notification_reservation_submitted_send_admins', $appCfg)
            ? !empty($appCfg['notification_reservation_submitted_send_admins'])
            : $legacySendStaffDefault;

        $startDisplay = app_format_datetime($start, $config);
        $endDisplay = app_format_datetime($end, $config);
        $submittedByName = trim((string)($currentUser['first_name'] ?? '') . ' ' . (string)($currentUser['last_name'] ?? ''));
        $submittedByEmail = trim((string)($currentUser['email'] ?? ''));
        $submittedByDisplay = $submittedByName !== '' ? $submittedByName : ($submittedByEmail !== '' ? $submittedByEmail : 'Unknown user');
        if ($submittedByName !== '' && $submittedByEmail !== '' && strcasecmp($submittedByName, $submittedByEmail) !== 0) {
            $submittedByDisplay .= " ({$submittedByEmail})";
        }

        $bookedForDisplay = $userName !== '' ? $userName : ($userEmail !== '' ? $userEmail : 'Unknown user');
        if ($userName !== '' && $userEmail !== '' && strcasecmp($userName, $userEmail) !== 0) {
            $bookedForDisplay .= " ({$userEmail})";
        }

        $modelLabels = [];
        foreach ($models as $entry) {
            $modelName = trim((string)($entry['model']['name'] ?? 'Item'));
            if ($modelName === '') {
                $modelName = 'Item';
            }
            $qty = (int)($entry['qty'] ?? 0);
            $modelLabels[] = $qty > 1 ? ($modelName . " (x{$qty})") : $modelName;
        }
        $itemsSummary = !empty($modelLabels)
            ? implode(', ', $modelLabels)
            : ((int)$totalRequestedItems . ' item(s)');

        $userBody = [
            "Reservation #{$reservationId} has been submitted.",
            "Items: {$itemsSummary}",
            "Start: {$startDisplay}",
            "End: {$endDisplay}",
        ];
        if ($isOnBehalfBooking) {
            $userBody[] = "Submitted by: {$submittedByDisplay}";
        }

        $adminBody = [
            "Reservation #{$reservationId} has been submitted.",
            "Booked for: {$bookedForDisplay}",
            "Items: {$itemsSummary}",
            "Start: {$startDisplay}",
            "End: {$endDisplay}",
            "Submitted by: {$submittedByDisplay}",
        ];
        $reservationLinkLine = layout_reservation_link_line($reservationId, $config);
        if ($reservationLinkLine !== null) {
            $userBody[] = $reservationLinkLine;
            $adminBody[] = $reservationLinkLine;
        }
        $userPortalLinkLine = layout_my_reservations_link_line($config);
        if ($userPortalLinkLine !== null) {
            $userBody[] = $userPortalLinkLine;
        }
        $staffPortalLinkLine = layout_staff_reservations_link_line($config);
        if ($staffPortalLinkLine !== null) {
            $adminBody[] = $staffPortalLinkLine;
        }

        $notifiedEmails = [];
        if ($sendUserDefault && $userEmail !== '') {
            layout_send_notification(
                $userEmail,
                $userName !== '' ? $userName : $userEmail,
                'Reservation submitted',
                $userBody,
                $config
            );
            $notifiedEmails[] = $userEmail;
        }

        if ($sendCheckoutUsersDefault || $sendAdminsDefault) {
            $roleRecipients = layout_role_notification_recipients(
                $sendCheckoutUsersDefault,
                $sendAdminsDefault,
                $config,
                $notifiedEmails
            );
            foreach ($roleRecipients as $recipient) {
                layout_send_notification(
                    $recipient['email'],
                    $recipient['name'],
                    'New reservation submitted',
                    $adminBody,
                    $config
                );
                $notifiedEmails[] = $recipient['email'];
            }
        }

        $extraRecipients = layout_extra_notification_recipients(
            (string)($appCfg['notification_reservation_submitted_extra_emails'] ?? ''),
            $notifiedEmails
        );
        foreach ($extraRecipients as $recipient) {
            layout_send_notification(
                $recipient['email'],
                $recipient['name'],
                'New reservation submitted',
                $adminBody,
                $config
            );
        }
    }

} catch (Exception $e) {
    $pdo->rollBack();
    die('Could not create booking: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking submitted</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
    <?= layout_logo_tag() ?>
    <h1>Thank you</h1>
    <p>Your booking has been submitted for <?= (int)$totalRequestedItems ?> item(s).</p>
    <p>
        <a href="catalogue.php" class="btn btn-primary">Book more equipment</a>
        <a href="my_bookings.php" class="btn btn-secondary">View my bookings</a>
    </p>
</div>
<?php layout_footer(); ?>
</body>
</html>
