<?php
require 'auth.php';
require 'db.php';
require 'snipeit_client.php';

$user = $currentUser;

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

// Build student info from Snipe-IT user
$studentName  = trim($user['first_name'] . ' ' . $user['last_name']);
$studentEmail = $user['email'];
$studentId    = $user['id']; // store their Snipe-IT ID as "student_id" too if you like

// Insert booking
$insert = $pdo->prepare("
    INSERT INTO reservations (
        student_name, student_email, student_id, snipeit_user_id,
        asset_id, asset_name_cache,
        start_datetime, end_datetime, status
    ) VALUES (
        :student_name, :student_email, :student_id, :snipeit_user_id,
        :asset_id, :asset_name_cache,
        :start_datetime, :end_datetime, 'pending'
    )
");
$insert->execute([
    ':student_name'     => $studentName,
    ':student_email'    => $studentEmail,
    ':student_id'       => $studentId,
    ':snipeit_user_id'  => $user['id'],
    ':asset_id'         => $assetId,
    ':asset_name_cache' => $assetName,
    ':start_datetime'   => $start,
    ':end_datetime'     => $end,
]);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Booking submitted</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
    <h1>Thank you</h1>
    <p>Your booking has been submitted.</p>
    <p>
        <a href="catalogue.php" class="btn btn-primary">Book more equipment</a>
        <a href="my_bookings.php" class="btn btn-secondary">View my bookings</a>
    </p>
</div>
</body>
</html>
