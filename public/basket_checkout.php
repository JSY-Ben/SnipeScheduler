<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/reservation_policy.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/layout.php';

$userOverride = $_SESSION['booking_user_override'] ?? null;
$user = $userOverride ?: $currentUser;
$basket = booking_session_basket_items($_SESSION['basket'] ?? []);

if (empty($basket)) {
    die('Your basket is empty.');
}

$startRaw = $_POST['start_datetime'] ?? '';
$endRaw = $_POST['end_datetime'] ?? '';

if (!$startRaw || !$endRaw) {
    die('Start and end date/time are required.');
}

$startTs = strtotime($startRaw);
$endTs = strtotime($endRaw);

if ($startTs === false || $endTs === false) {
    die('Invalid date/time.');
}

$start = date('Y-m-d H:i:s', $startTs);
$end = date('Y-m-d H:i:s', $endTs);

if ($end <= $start) {
    die('End time must be after start time.');
}

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$overrideEmail = strtolower(trim((string)($userOverride['email'] ?? '')));
$currentEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
$isOnBehalfBooking = is_array($userOverride) && $overrideEmail !== '' && $overrideEmail !== $currentEmail;

$userName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
if ($userName === '') {
    $userName = (string)($user['email'] ?? 'Unknown user');
}
$userEmail = (string)($user['email'] ?? '');
$userId = (string)($user['id'] ?? '');

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

$reservationItemsTyped = booking_reservation_items_have_typed_columns($pdo);
$hasNonModelItems = false;
foreach ($basket as $basketItem) {
    if (booking_normalize_item_type((string)($basketItem['type'] ?? 'model')) !== 'model') {
        $hasNonModelItems = true;
        break;
    }
}
if ($hasNonModelItems && !$reservationItemsTyped) {
    die('This installation must run the latest database upgrade before accessories or kits can be booked.');
}

$pdo->beginTransaction();

try {
    $items = [];
    $totalRequestedItems = 0;

    foreach ($basket as $basketItem) {
        $itemType = booking_normalize_item_type((string)($basketItem['type'] ?? 'model'));
        $itemId = (int)($basketItem['id'] ?? 0);
        $qty = (int)($basketItem['qty'] ?? 0);

        if ($itemId <= 0 || $qty < 1) {
            throw new Exception('Invalid item/quantity in basket.');
        }

        $record = booking_fetch_catalogue_item_record($itemType, $itemId);
        if (empty($record['id'])) {
            throw new Exception(ucfirst($itemType) . ' not found in Snipe-IT: ID ' . $itemId);
        }

        $existingBooked = booking_count_reserved_item_quantity(
            $pdo,
            $itemType,
            $itemId,
            $start,
            $end,
            ['pending', 'confirmed']
        );
        $totalRequestable = booking_get_requestable_total_for_item($itemType, $itemId);
        $activeCheckedOut = booking_count_effective_checked_out_for_item($itemType, $itemId, $config, (int)$startTs);
        $availableNow = $totalRequestable > 0 ? max(0, $totalRequestable - $activeCheckedOut) : 0;

        if ($totalRequestable > 0 && $existingBooked + $qty > $availableNow) {
            throw new Exception(
                'Not enough units available for "' . ($record['name'] ?? (ucfirst($itemType) . ' #' . $itemId)) . '" '
                . 'in that time period. Requested ' . $qty . ', already booked ' . $existingBooked
                . ', total available ' . $availableNow . '.'
            );
        }

        $items[] = [
            'type' => $itemType,
            'item' => $record,
            'qty' => $qty,
        ];
        $totalRequestedItems += $qty;
    }

    $firstName = !empty($items) ? (string)($items[0]['item']['name'] ?? 'Multiple items') : 'Multiple items';
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
        ':user_name' => $userName,
        ':user_email' => $userEmail,
        ':user_id' => $userId,
        ':snipeit_user_id' => $user['id'],
        ':asset_name_cache' => 'Pending checkout',
        ':start_datetime' => $start,
        ':end_datetime' => $end,
    ]);

    $reservationId = (int)$pdo->lastInsertId();

    if ($reservationItemsTyped) {
        $insertItem = $pdo->prepare("
            INSERT INTO reservation_items (
                reservation_id, item_type, item_id, item_name_cache,
                model_id, model_name_cache, quantity
            ) VALUES (
                :reservation_id, :item_type, :item_id, :item_name_cache,
                :model_id, :model_name_cache, :quantity
            )
        ");

        foreach ($items as $entry) {
            $itemType = booking_normalize_item_type((string)($entry['type'] ?? 'model'));
            $record = $entry['item'];
            $qty = (int)($entry['qty'] ?? 0);
            $itemId = (int)($record['id'] ?? 0);

            $insertItem->execute([
                ':reservation_id' => $reservationId,
                ':item_type' => $itemType,
                ':item_id' => $itemId,
                ':item_name_cache' => $record['name'] ?? (ucfirst($itemType) . ' #' . $itemId),
                ':model_id' => $itemType === 'model' ? $itemId : 0,
                ':model_name_cache' => $itemType === 'model'
                    ? ($record['name'] ?? ('Model #' . $itemId))
                    : '',
                ':quantity' => $qty,
            ]);
        }
    } else {
        $insertItem = $pdo->prepare("
            INSERT INTO reservation_items (
                reservation_id, model_id, model_name_cache, quantity
            ) VALUES (
                :reservation_id, :model_id, :model_name_cache, :quantity
            )
        ");

        foreach ($items as $entry) {
            $record = $entry['item'];
            $qty = (int)($entry['qty'] ?? 0);
            $itemId = (int)($record['id'] ?? 0);

            $insertItem->execute([
                ':reservation_id' => $reservationId,
                ':model_id' => $itemId,
                ':model_name_cache' => $record['name'] ?? ('Model #' . $itemId),
                ':quantity' => $qty,
            ]);
        }
    }

    $pdo->commit();
    $_SESSION['basket'] = [];

    activity_log_event('reservation_submitted', 'Reservation submitted', [
        'subject_type' => 'reservation',
        'subject_id' => $reservationId,
        'metadata' => [
            'items' => $totalRequestedItems,
            'start' => $start,
            'end' => $end,
            'booked_for' => $userEmail,
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

        $itemLabels = [];
        foreach ($items as $entry) {
            $itemName = trim((string)($entry['item']['name'] ?? 'Item'));
            if ($itemName === '') {
                $itemName = 'Item';
            }
            $qty = (int)($entry['qty'] ?? 0);
            $itemLabels[] = $qty > 1 ? ($itemName . " (x{$qty})") : $itemName;
        }
        $itemsSummary = !empty($itemLabels)
            ? implode(', ', $itemLabels)
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
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
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
    <p>Your booking has been submitted.</p>
    <p>
        <a href="catalogue.php" class="btn btn-primary">Book more equipment</a>
        <a href="my_bookings.php" class="btn btn-secondary">View my bookings</a>
    </p>
</div>
<?php layout_footer(); ?>
</body>
</html>
