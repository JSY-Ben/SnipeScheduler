<?php
// delete_reservation.php
// Deletes a reservation and its items (admins or the owning user).

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/staff_group_visibility.php';

$isAdmin       = !empty($currentUser['is_admin']);
$isStaff       = !empty($currentUser['is_staff']) || $isAdmin;
$currentUserId = (string)($currentUser['id'] ?? '');
$config        = load_config();
$restrictReservationsToSameGroup = staff_group_visibility_restriction_enabled($config, $currentUser);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

$resId = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;
$action = trim((string)($_POST['action'] ?? 'delete'));
$returnToHistory = !empty($_POST['return_to_history']);
if ($resId <= 0) {
    http_response_code(400);
    echo 'Invalid reservation ID.';
    exit;
}

// Load reservation to check ownership
$stmt = $pdo->prepare("
    SELECT id, user_id, user_email, status
    FROM reservations
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $resId]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    http_response_code(404);
    echo 'Reservation not found.';
    exit;
}

$ownsReservation = $currentUserId !== ''
    && isset($reservation['user_id'])
    && (string)$reservation['user_id'] === $currentUserId;

if (!$isStaff && !$ownsReservation) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if ($isStaff && !$ownsReservation && !staff_group_visibility_reservation_visible($reservation, $currentUser, $restrictReservationsToSameGroup)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if ($action === 'cancel_pending') {
    if (strtolower((string)($reservation['status'] ?? '')) !== 'pending') {
        http_response_code(409);
        echo 'Only pending reservations can be cancelled from this action.';
        exit;
    }

    $deletePermanently = $isAdmin && !empty($_POST['delete_permanently']);
    if (!$deletePermanently) {
        $stmt = $pdo->prepare("
            UPDATE reservations
               SET status = 'cancelled'
             WHERE id = :id
               AND status = 'pending'
        ");
        $stmt->execute([':id' => $resId]);
        if ($stmt->rowCount() !== 1) {
            http_response_code(409);
            echo 'Reservation could not be cancelled because its status has changed.';
            exit;
        }

        activity_log_event('reservation_cancelled', 'Reservation cancelled by staff', [
            'subject_type' => 'reservation',
            'subject_id' => $resId,
            'metadata' => [
                'cancelled_by' => (string)($currentUser['email'] ?? ''),
            ],
        ]);

        $redirect = $returnToHistory
            ? 'reservations.php?tab=history&cancelled=' . $resId
            : 'staff_reservations.php?cancelled=' . $resId;
        header('Location: ' . $redirect);
        exit;
    }
}

if ($action === 'delete_completed') {
    if (strtolower((string)($reservation['status'] ?? '')) !== 'completed') {
        http_response_code(409);
        echo 'Only completed reservations can use this deletion action.';
        exit;
    }
    if (($_POST['acknowledge_checked_out_risk'] ?? '') !== '1') {
        http_response_code(400);
        echo 'You must acknowledge the Snipe-IT checked-out item warning before deleting this reservation.';
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // 🔴 ADJUST TABLE NAMES HERE IF NEEDED
    // First delete child items
    $stmt = $pdo->prepare("DELETE FROM reservation_items WHERE reservation_id = :id");
    $stmt->execute([':id' => $resId]);

    // Then delete the reservation itself
    $stmt = $pdo->prepare("DELETE FROM reservations WHERE id = :id");
    $stmt->execute([':id' => $resId]);

    $pdo->commit();

    activity_log_event('reservation_deleted', 'Reservation deleted', [
        'subject_type' => 'reservation',
        'subject_id'   => $resId,
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo 'Error deleting reservation: ' . htmlspecialchars($e->getMessage());
    exit;
}

// Redirect back with a “deleted” flag
$redirect = $isStaff
    ? ($returnToHistory
        ? 'reservations.php?tab=history&deleted=' . $resId
        : 'staff_reservations.php?deleted=' . $resId)
    : 'my_bookings.php?deleted=' . $resId;

header('Location: ' . $redirect);
exit;
