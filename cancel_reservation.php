<?php
require 'db.php';
require 'auth.php';

$reservationId = (int)($_POST['reservation_id'] ?? 0);
$email         = trim($_POST['email'] ?? '');

if (!$reservationId || $email === '') {
    die('Invalid request.');
}

// Load reservation
$sql = "
    SELECT *
    FROM reservations
    WHERE id = :id
      AND student_email = :email
      AND status IN ('pending','confirmed')
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id'    => $reservationId,
    ':email' => $email,
]);
$res = $stmt->fetch();

if (!$res) {
    die('Booking not found or cannot be cancelled.');
}

// Check that start time is still in the future
$start = new DateTime($res['start_datetime']);
$now   = new DateTime();

if ($start <= $now) {
    die('You cannot cancel a booking that has already started.');
}

// Update status to cancelled
$upd = $pdo->prepare("
    UPDATE reservations
    SET status = 'cancelled'
    WHERE id = :id
");
$upd->execute([':id' => $reservationId]);

header('Location: my_bookings.php?email=' . urlencode($email) . '&cancelled=1');
exit;
