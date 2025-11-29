<?php
// delete_reservation.php
// Deletes a reservation and its items (admin only).

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

// Only staff/admin allowed
if (empty($currentUser['is_admin'])) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

$resId = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;
if ($resId <= 0) {
    http_response_code(400);
    echo 'Invalid reservation ID.';
    exit;
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
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo 'Error deleting reservation: ' . htmlspecialchars($e->getMessage());
    exit;
}

// Redirect back to admin list with a “deleted” flag
header('Location: staff_reservations.php?deleted=' . $resId);
exit;
