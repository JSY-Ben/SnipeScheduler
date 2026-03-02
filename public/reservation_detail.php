<?php
// reservation_detail.php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/layout.php';

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$currentUserId = (string)($currentUser['id'] ?? '');

function display_date(?string $isoDate): string
{
    return app_format_date($isoDate);
}

function display_datetime(?string $isoDatetime): string
{
    return app_format_datetime($isoDatetime);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid reservation ID.';
    exit;
}

// Load reservation
try {
    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    http_response_code(500);
    echo 'Error loading reservation: ' . htmlspecialchars($e->getMessage());
    exit;
}

if (!$reservation) {
    http_response_code(404);
    echo 'Reservation not found.';
    exit;
}

$ownsReservation = $currentUserId !== ''
    && isset($reservation['user_id'])
    && (string)($reservation['user_id'] ?? '') === $currentUserId;

if (!$isStaff && !$ownsReservation) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// Load items via shared helper
$items = get_reservation_items_with_names($pdo, $id);

$showStaffView = $isStaff;
$active = $showStaffView ? 'staff_reservations.php' : 'my_bookings.php';
$backHref = 'staff_reservations.php';
$backLabel = 'Back to all bookings';
if (!$showStaffView) {
    $status = strtolower(trim((string)($reservation['status'] ?? '')));
    $tab = $status === 'checked_out' ? 'checked_out' : 'reservations';
    $backHref = 'my_bookings.php?tab=' . rawurlencode($tab);
    $backLabel = 'Back to my bookings';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking #<?= (int)$id ?> – Details</title>

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
            <h1>Booking #<?= (int)$id ?> – Details</h1>
            <div class="page-subtitle">
                Full details for this booking.
            </div>
        </div>

        <!-- App navigation -->
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="<?= h($backHref) ?>" class="btn btn-outline-secondary btn-sm"><?= h($backLabel) ?></a>
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title">Booking information</h5>
                <p class="card-text">
                    <strong>User Name:</strong>
                    <?= h($reservation['user_name'] ?? '(Unknown)') ?><br>

                    <strong>Start:</strong>
                    <?= display_datetime($reservation['start_datetime'] ?? '') ?><br>

                    <strong>End:</strong>
                    <?= display_datetime($reservation['end_datetime'] ?? '') ?><br>

                    <strong>Status:</strong>
                    <?= h($reservation['status'] ?? '') ?><br>

                    <?php if (!empty($reservation['asset_name_cache'])): ?>
                        <strong>Checked-out assets:</strong>
                        <?= h($reservation['asset_name_cache']) ?><br>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <h5>Items reserved</h5>

        <?php if (empty($items)): ?>
            <div class="alert alert-info">
                No item records found for this booking.
            </div>
        <?php else: ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th style="width: 80px;">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= h($item['name'] ?? '') ?></td>
                                <td><?= (int)$item['qty'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <form method="post"
              action="delete_reservation.php"
              onsubmit="return confirm('Delete this booking and all its items? This cannot be undone.');">
            <input type="hidden" name="reservation_id" value="<?= (int)$id ?>">
            <button class="btn btn-outline-danger" type="submit">
                Delete this booking
            </button>
        </form>

    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
