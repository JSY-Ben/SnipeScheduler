<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/layout.php';

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

$active        = basename($_SERVER['PHP_SELF']);
$isStaff       = !empty($currentUser['is_admin']);
$currentUserId = (string)($currentUser['id'] ?? '');

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$tabRaw = $_GET['tab'] ?? 'reservations';
$tab = $tabRaw === 'checked_out' ? 'checked_out' : 'reservations';

// Load this user's reservations
try {
    $sql = "
        SELECT *
        FROM reservations
        WHERE user_id = :user_id
        ORDER BY start_datetime DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $currentUserId]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reservations = [];
    $loadError = $e->getMessage();
}

$checkedOutItems = [];
$checkedOutError = '';
if ($tab === 'checked_out') {
    try {
        $email = strtolower(trim($currentUser['email'] ?? ''));
        $username = strtolower(trim($currentUser['username'] ?? ''));
        $name = strtolower(trim($userName));

        $stmt = $pdo->prepare("
            SELECT *
              FROM checked_out_asset_cache
             WHERE (assigned_to_email IS NOT NULL AND LOWER(assigned_to_email) = :email)
                OR (assigned_to_username IS NOT NULL AND LOWER(assigned_to_username) = :username)
                OR (assigned_to_name IS NOT NULL AND LOWER(assigned_to_name) = :name)
             ORDER BY
                CASE WHEN expected_checkin IS NULL OR expected_checkin = '' THEN 1 ELSE 0 END,
                expected_checkin ASC,
                last_checkout DESC
        ");
        $stmt->execute([
            ':email' => $email,
            ':username' => $username,
            ':name' => $name,
        ]);
        $checkedOutItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $checkedOutItems = [];
        $checkedOutError = $e->getMessage();
    }
}

$deletedMsg = '';
if (!empty($_GET['deleted'])) {
    $deletedMsg = 'Reservation #' . (int)$_GET['deleted'] . ' has been deleted.';
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
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>My Reservations</h1>
            <div class="page-subtitle">
                View all your past, current and future reservations.
            </div>
        </div>

        <!-- App navigation -->
        <?= layout_render_nav($active, $isStaff) ?>

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

        <?php if (!empty($deletedMsg)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($deletedMsg) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($loadError ?? '')): ?>
            <div class="alert alert-danger">
                Error loading your reservations: <?= htmlspecialchars($loadError) ?>
            </div>
        <?php endif; ?>

        <?php
            $reservationsUrl = 'my_bookings.php?tab=reservations';
            $checkedOutUrl = 'my_bookings.php?tab=checked_out';
        ?>
        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'reservations' ? 'active' : '' ?>"
                   href="<?= h($reservationsUrl) ?>">My Reservations</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'checked_out' ? 'active' : '' ?>"
                   href="<?= h($checkedOutUrl) ?>">My Checked Out Items</a>
            </li>
        </ul>

        <?php if ($tab === 'checked_out'): ?>
            <?php if (!empty($checkedOutError)): ?>
                <div class="alert alert-danger">
                    Error loading checked-out items: <?= htmlspecialchars($checkedOutError) ?>
                </div>
            <?php elseif (empty($checkedOutItems)): ?>
                <div class="alert alert-info">
                    You don’t have any checked-out items right now.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Asset Tag</th>
                                <th>Name</th>
                                <th>Model</th>
                                <th>Assigned Since</th>
                                <th>Expected Check-in</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checkedOutItems as $row): ?>
                                <tr>
                                    <td><?= h($row['asset_tag'] ?? '') ?></td>
                                    <td><?= h($row['asset_name'] ?? '') ?></td>
                                    <td><?= h($row['model_name'] ?? '') ?></td>
                                    <td><?= h(uk_datetime($row['last_checkout'] ?? '')) ?></td>
                                    <td><?= h(uk_date($row['expected_checkin'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
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
                        $status  = strtolower((string)($res['status'] ?? ''));
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

                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <?php if ($status === 'pending'): ?>
                                    <a href="reservation_edit.php?id=<?= $resId ?>&from=my_bookings"
                                       class="btn btn-outline-primary btn-sm btn-action">
                                        Edit
                                    </a>
                                <?php endif; ?>
                                <form method="post"
                                      action="delete_reservation.php"
                                      onsubmit="return confirm('Delete this reservation and all its items? This cannot be undone.');">
                                    <input type="hidden" name="reservation_id" value="<?= $resId ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">
                                        Delete reservation
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
