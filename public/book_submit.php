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
$user = $userOverride ?: $currentUser;

$assetId  = (int)($_POST['asset_id'] ?? 0);
$startRaw = $_POST['start_datetime'] ?? '';
$endRaw   = $_POST['end_datetime'] ?? '';
$reservationNote = trim((string)($_POST['reservation_note'] ?? ''));
if (mb_strlen($reservationNote) > 5000) {
    die('Reservation notes must be 5,000 characters or fewer.');
}

if (!$assetId || !$startRaw || !$endRaw) {
    die('Missing required fields.');
}

$timezone = app_get_timezone($config);
$startDateTime = app_parse_local_datetime_input($startRaw, $timezone);
$endDateTime = app_parse_local_datetime_input($endRaw, $timezone);

if (!$startDateTime || !$endDateTime) {
    die('Invalid date/time.');
}

$startTs = $startDateTime->getTimestamp();
$endTs = $endDateTime->getTimestamp();
$start = $startDateTime->format('Y-m-d H:i:s');
$end = $endDateTime->format('Y-m-d H:i:s');

if ($endDateTime <= $startDateTime) {
    die('End time must be after start time.');
}

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$overrideEmail = strtolower(trim((string)($userOverride['email'] ?? '')));
$currentEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
$isOnBehalfBooking = is_array($userOverride) && $overrideEmail !== '' && $overrideEmail !== $currentEmail;

// Load asset from Snipe-IT
try {
    $asset = get_asset($assetId);
} catch (Exception $e) {
    die('Error loading asset from Snipe-IT: ' . htmlspecialchars($e->getMessage()));
}

if (empty($asset['id'])) {
    die('Asset not found.');
}
$assetName = $asset['name'] ?? ('Asset #' . $assetId);

// Check for overlapping reservations
$sql = "
    SELECT COUNT(*) AS c
    FROM reservations
    WHERE asset_id = :asset_id
      AND status IN ('pending','confirmed','completed')
      AND (
        (start_datetime < :end AND end_datetime > :start)
      )
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':asset_id' => $assetId,
    ':start'    => $start,
    ':end'      => $end,
]);
$row = $stmt->fetch();

if ($row && $row['c'] > 0) {
    die('Sorry, this item is already booked for that time.');
}

// Build user info from Snipe-IT user record
$userName  = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
if ($userName === '') {
    $userName = (string)($user['email'] ?? 'Unknown user');
}
$userEmail = (string)($user['email'] ?? '');
$userId    = (string)($user['id'] ?? ''); // store their Snipe-IT ID as "user_id" too if you like

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

// Insert booking
$insert = $pdo->prepare("
    INSERT INTO reservations (
        user_name, user_email, user_id, snipeit_user_id,
        asset_id, asset_name_cache,
        reservation_note, start_datetime, end_datetime, status
    ) VALUES (
        :user_name, :user_email, :user_id, :snipeit_user_id,
        :asset_id, :asset_name_cache,
        :reservation_note, :start_datetime, :end_datetime, 'pending'
    )
");
$insert->execute([
    ':user_name'        => $userName,
    ':user_email'       => $userEmail,
    ':user_id'          => $userId,
    ':snipeit_user_id'  => $user['id'],
    ':asset_id'         => $assetId,
    ':asset_name_cache' => 'Pending checkout',
    ':reservation_note' => $reservationNote !== '' ? $reservationNote : null,
    ':start_datetime'   => $start,
    ':end_datetime'     => $end,
]);

$reservationId = (int)$pdo->lastInsertId();
activity_log_event('reservation_submitted', 'Reservation submitted', [
    'subject_type' => 'reservation',
    'subject_id'   => $reservationId,
    'metadata'     => [
        'asset_id'   => $assetId,
        'asset_name' => $assetName,
        'start'      => $start,
        'end'        => $end,
        'booked_for' => $userEmail,
        'reservation_note' => $reservationNote,
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

    $userBody = [
        "Reservation #{$reservationId} has been submitted.",
        "Asset: {$assetName}",
        "Start: {$startDisplay}",
        "End: {$endDisplay}",
    ];
    if ($reservationNote !== '') {
        $userBody[] = "Reservation notes: {$reservationNote}";
    }
    if ($isOnBehalfBooking) {
        $userBody[] = "Submitted by: {$submittedByDisplay}";
    }

    $adminBody = [
        "Reservation #{$reservationId} has been submitted.",
        "Booked for: {$bookedForDisplay}",
        "Asset: {$assetName}",
        "Start: {$startDisplay}",
        "End: {$endDisplay}",
        "Submitted by: {$submittedByDisplay}",
    ];
    if ($reservationNote !== '') {
        $adminBody[] = "Reservation notes: {$reservationNote}";
    }
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
    $templateVariables = [
        'person_name' => $userName,
        'person_email' => $userEmail,
        'equipment_list' => $assetName,
        'start_date' => $startDisplay,
        'return_date' => $endDisplay,
        'reservation_id' => (string)$reservationId,
        'reservation_link' => layout_reservation_detail_url($reservationId, $config),
        'my_reservations_link' => layout_my_reservations_url($config),
        'staff_reservations_link' => layout_staff_reservations_url($config),
        'staff_name' => $submittedByName !== '' ? $submittedByName : $submittedByEmail,
        'staff_email' => $submittedByEmail,
        'reservation_note' => $reservationNote,
    ];

    $notifiedEmails = [];
    if ($sendUserDefault && $userEmail !== '') {
        layout_send_notification(
            $userEmail,
            $userName !== '' ? $userName : $userEmail,
            'Reservation submitted',
            $userBody,
            $config,
            true,
            'reservation_submitted',
            $templateVariables
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
                $config,
                true,
                'reservation_submitted',
                $templateVariables
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
            $config,
            true,
            'reservation_submitted',
            $templateVariables
        );
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= _('Booking submitted') ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>

        <div class="page-header">
            <h1><?= _('Reservation submitted') ?></h1>
            <div class="page-subtitle"><?= _('Your reservation request has been recorded successfully.') ?></div>
        </div>

        <?= layout_render_nav(
            'my_bookings.php',
            !empty($currentUser['is_staff']) || !empty($currentUser['is_admin']),
            !empty($currentUser['is_admin']),
            true
        ) ?>

        <div class="card mx-auto" style="max-width: 720px;">
            <div class="card-body p-4 p-md-5 text-center">
                <div class="alert alert-success mb-4" role="status">
                    <div class="fs-1 lh-1 mb-2" aria-hidden="true">&#10003;</div>
                    <h2 class="h4 mb-1"><?= _('Thank you') ?></h2>
                    <p class="mb-0"><?= _('Your booking has been submitted.') ?></p>
                </div>

                <dl class="row text-start mb-4">
                    <dt class="col-sm-4"><?= _('Reservation') ?></dt>
                    <dd class="col-sm-8">#<?= (int)$reservationId ?></dd>
                    <dt class="col-sm-4"><?= _('Starts') ?></dt>
                    <dd class="col-sm-8"><?= h(app_format_datetime($start)) ?></dd>
                    <dt class="col-sm-4"><?= _('Returns') ?></dt>
                    <dd class="col-sm-8 mb-0"><?= h(app_format_datetime($end)) ?></dd>
                </dl>

                <p class="text-muted mb-4">
                    <?= _('You can review the reservation and its current status from My Reservations.') ?>
                </p>

                <div class="d-flex flex-column flex-sm-row justify-content-center gap-2">
                    <a href="my_bookings.php" class="btn btn-primary"><?= _('View my reservations') ?></a>
                    <a href="catalogue.php" class="btn btn-outline-primary"><?= _('Book more equipment') ?></a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
