<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/footer.php';

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

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));

// Load this user's reservations
try {
    $sql = "
        SELECT *
        FROM reservations
        WHERE user_name = :user_name
        ORDER BY start_datetime DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_name' => $userName]);
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Reservations</title>

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
            <h1>My Reservations</h1>
            <div class="page-subtitle">
                View all your past, current and future reservations.
            </div>
        </div>

        <!-- App navigation -->
        <?= reserveit_render_nav($active, $isStaff) ?>

        <!-- Top bar -->
        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h($userName) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if (!empty($loadError ?? '')): ?>
            <div class="alert alert-danger">
                Error loading your reservations: <?= htmlspecialchars($loadError) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($reservations)): ?>
            <div class="alert alert-info">
                You don’t have any reservations yet.
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
                            Reservation #<?= $resId ?>
                        </h5>
                        <p class="card-text">
                            <strong>User Name:</strong>
                            <?= h($res['user_name'] ?? $userName) ?><br>

                            <strong>Start:</strong>
                            <?= uk_datetime($res['start_datetime'] ?? '') ?><br>

                            <strong>End:</strong>
                            <?= uk_datetime($res['end_datetime'] ?? '') ?><br>

                            <strong>Status:</strong>
                            <?= h($res['status'] ?? '') ?><br>

                            <?php if ($summary !== ''): ?>
                                <strong>Items:</strong>
                                <?= h($summary) ?><br>
                            <?php endif; ?>

                            <?php if (!empty($res['asset_name_cache'])): ?>
                                <strong>Checked-out assets:</strong>
                                <?= h($res['asset_name_cache']) ?>
                            <?php endif; ?>
                        </p>

                        <?php if (!empty($items)): ?>
                            <h6>Items in this reservation</h6>
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
<?php reserveit_footer(); ?>
</body>
</html>
