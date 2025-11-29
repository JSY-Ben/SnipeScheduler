<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_helpers.php';

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

$active  = basename($_SERVER['PHP_SELF']);
$isStaff = !empty($currentUser['is_admin']);

$studentName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));

// Load this user's reservations
try {
    $sql = "
        SELECT *
        FROM reservations
        WHERE student_name = :student_name
        ORDER BY start_datetime DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':student_name' => $studentName]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reservations = [];
    $loadError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>My bookings</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <div class="page-header">
            <h1>My bookings</h1>
            <div class="page-subtitle">
                View all your past, current and future bookings.
            </div>
        </div>

        <!-- App navigation -->
        <nav class="app-nav">
            <a href="index.php"
               class="app-nav-link <?= $active === 'index.php' ? 'active' : '' ?>">Dashboard</a>
            <a href="catalogue.php"
               class="app-nav-link <?= $active === 'catalogue.php' ? 'active' : '' ?>">Catalogue</a>
            <a href="my_bookings.php"
               class="app-nav-link <?= $active === 'my_bookings.php' ? 'active' : '' ?>">My bookings</a>
            <?php if ($isStaff): ?>
                <a href="staff_reservations.php"
                   class="app-nav-link <?= $active === 'staff_reservations.php' ? 'active' : '' ?>">Admin</a>
                <a href="staff_checkout.php"
                   class="app-nav-link <?= $active === 'staff_checkout.php' ? 'active' : '' ?>">Checkout</a>
            <?php endif; ?>
        </nav>

        <!-- Top bar -->
        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h($studentName) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if (!empty($loadError ?? '')): ?>
            <div class="alert alert-danger">
                Error loading your bookings: <?= htmlspecialchars($loadError) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($reservations)): ?>
            <div class="alert alert-info">
                You don’t have any bookings yet.
            </div>
        <?php else: ?>
            <?php foreach ($reservations as $res): ?>
                <?php
                    $resId   = (int)$res['id'];
                    $items   = get_reservation_items_with_names($pdo, $resId);
                    $summary = build_items_summary_text($items);
                ?>
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title">
                            Booking #<?= $resId ?>
                        </h5>
                        <p class="card-text">
                            <strong>Student Name:</strong>
                            <?= h($res['student_name'] ?? $studentName) ?><br>

                            <strong>Start:</strong>
                            <?= uk_datetime($res['start_datetime'] ?? '') ?><br>

                            <strong>End:</strong>
                            <?= uk_datetime($res['end_datetime'] ?? '') ?><br>

                            <strong>Status:</strong>
                            <?= h($res['status'] ?? '') ?><br>

                            <?php if ($summary !== ''): ?>
                                <strong>Items:</strong>
                                <?= h($summary) ?>
                            <?php endif; ?>
                        </p>

                        <?php if (!empty($items)): ?>
                            <h6>Items in this booking</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th style="width: 80px;">Qty</th>
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
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
