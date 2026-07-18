<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/activity_log.php';

$reservationId = (int)($_POST['reservation_id'] ?? 0);
$currentUserId = (string)($currentUser['id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

if (!$reservationId || $currentUserId === '') {
    http_response_code(400);
    die('Invalid request.');
}

// Load reservation
$sql = "
    SELECT *
    FROM reservations
    WHERE id = :id
      AND user_id = :user_id
      AND status IN ('pending','confirmed')
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id'    => $reservationId,
    ':user_id' => $currentUserId,
]);
$res = $stmt->fetch();

if (!$res) {
    die('Booking not found or cannot be cancelled.');
}

// Check that start time is still in the future
$timezone = app_get_timezone($config);
$start = app_parse_datetime_value($res['start_datetime'], $timezone);
$now = new DateTime('now', $timezone ?: null);

if (!$start || $start <= $now) {
    die('You cannot cancel a booking that has already started.');
}

// Update status to cancelled
$upd = $pdo->prepare("
    UPDATE reservations
    SET status = 'cancelled'
    WHERE id = :id
      AND user_id = :user_id
      AND status IN ('pending','confirmed')
");
$upd->execute([
    ':id' => $reservationId,
    ':user_id' => $currentUserId,
]);
if ($upd->rowCount() !== 1) {
    http_response_code(409);
    die('Booking could not be cancelled because its status has changed.');
}

activity_log_event('reservation_cancelled', 'Reservation cancelled', [
    'subject_type' => 'reservation',
    'subject_id'   => $reservationId,
    'metadata'     => [
        'user_id' => $currentUserId,
        'email' => (string)($currentUser['email'] ?? ''),
    ],
]);

header('Location: my_bookings.php?tab=reservations&cancelled=' . $reservationId);
exit;
