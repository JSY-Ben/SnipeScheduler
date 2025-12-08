<?php
// reservation_detail.php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/footer.php';

$isStaff = !empty($currentUser['is_admin']);

/**
 * Convert YYYY-MM-DD → DD/MM/YYYY.
 */
function uk_date(?string $isoDate): string
{
    if (!$isoDate) {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $isoDate);
    return $dt ? $dt->format('d/m/Y') : $isoDate;
}

/**
 * Convert YYYY-MM-DD HH:MM:SS → DD/MM/YYYY.
 */
function uk_datetime(?string $isoDatetime): string
{
    if (!$isoDatetime) {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $isoDatetime);
    return $dt ? $dt->format('d/m/Y') : $isoDatetime;
}

if (empty($currentUser['is_admin'])) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
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

// Load items via shared helper
$items = get_reservation_items_with_names($pdo, $id);

$active  = 'staff_reservations.php'; // Treat detail view as part of booking history.
$isStaff = !empty($currentUser['is_admin']);
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
    <?= reserveit_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= reserveit_logo_tag() ?>
        <div class="page-header">
            <h1>Booking #<?= (int)$id ?> – Details</h1>
            <div class="page-subtitle">
                Full details for this booking.
            </div>
        </div>

        <!-- App navigation -->
        <?= reserveit_render_nav($active, $isStaff) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="staff_reservations.php" class="btn btn-outline-secondary btn-sm">Back to all bookings</a>
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
                    <?= uk_datetime($reservation['start_datetime'] ?? '') ?><br>

                    <strong>End:</strong>
                    <?= uk_datetime($reservation['end_datetime'] ?? '') ?><br>

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
<?php reserveit_footer(); ?>
</body>
</html>
