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

if (!$assetId || !$startRaw || !$endRaw) {
    die('Missing required fields.');
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
        start_datetime, end_datetime, status
    ) VALUES (
        :user_name, :user_email, :user_id, :snipeit_user_id,
        :asset_id, :asset_name_cache,
        :start_datetime, :end_datetime, 'pending'
    )
");
$insert->execute([
    ':user_name'        => $userName,
    ':user_email'       => $userEmail,
    ':user_id'          => $userId,
    ':snipeit_user_id'  => $user['id'],
    ':asset_id'         => $assetId,
    ':asset_name_cache' => 'Pending checkout',
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
