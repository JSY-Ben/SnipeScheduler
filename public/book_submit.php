<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/inventory_client.php';
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

// Load asset from local inventory
try {
    $asset = get_asset($assetId);
} catch (Exception $e) {
    die('Error loading asset: ' . htmlspecialchars($e->getMessage()));
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

// Build user info from local user record
$userName  = trim($user['first_name'] . ' ' . $user['last_name']);
$userEmail = $user['email'];
$userId    = $user['id'];

// Insert booking
$insert = $pdo->prepare("
    INSERT INTO reservations (
        user_name, user_email, user_id,
        asset_id, asset_name_cache,
        start_datetime, end_datetime, status
    ) VALUES (
        :user_name, :user_email, :user_id,
        :asset_id, :asset_name_cache,
        :start_datetime, :end_datetime, 'pending'
    )
");
$insert->execute([
    ':user_name'        => $userName,
    ':user_email'       => $userEmail,
    ':user_id'          => $userId,
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
