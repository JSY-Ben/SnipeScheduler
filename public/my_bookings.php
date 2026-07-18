<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/layout.php';

function display_date(?string $isoDate): string
{
    return app_format_date($isoDate);
}

function display_datetime(?string $isoDatetime): string
{
    return app_format_datetime($isoDatetime);
}

$active        = basename($_SERVER['PHP_SELF']);
$isAdmin       = !empty($currentUser['is_admin']);
$isStaff       = !empty($currentUser['is_staff']) || $isAdmin;
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

$cancelledMsg = '';
if (!empty($_GET['cancelled'])) {
    $cancelledMsg = _('Reservation #') . (int)$_GET['cancelled'] . ' ' . _('has been cancelled.');
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= _('My Reservations') ?></title>

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
            <h1><?= _('My Reservations') ?></h1>
            <div class="page-subtitle">
                <?= _('View all your past, current and future reservations.') ?>
            </div>
        </div>

        <!-- App navigation -->
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <!-- Top bar -->
        <div class="top-bar mb-3">
            <div class="top-bar-user">
                <?= _('Logged in as:') ?>
                <strong><?= h($userName) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm"><?= _('Log out') ?></a>
            </div>
        </div>

        <?php if (!empty($cancelledMsg)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($cancelledMsg) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($loadError ?? '')): ?>
            <div class="alert alert-danger">
                <?= _('Error loading your reservations:') ?> <?= htmlspecialchars($loadError) ?>
            </div>
        <?php endif; ?>

        <?php
            $reservationsUrl = 'my_bookings.php?tab=reservations';
            $checkedOutUrl = 'my_bookings.php?tab=checked_out';
        ?>
        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'reservations' ? 'active' : '' ?>"
                   href="<?= h($reservationsUrl) ?>"><?= _('My Reservations') ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'checked_out' ? 'active' : '' ?>"
                   href="<?= h($checkedOutUrl) ?>"><?= _('My Checked Out Items') ?></a>
            </li>
        </ul>

        <?php if ($tab === 'checked_out'): ?>
            <?php if (!empty($checkedOutError)): ?>
                <div class="alert alert-danger">
                    <?= _('Error loading checked-out items:') ?> <?= htmlspecialchars($checkedOutError) ?>
                </div>
            <?php elseif (empty($checkedOutItems)): ?>
                <div class="alert alert-info">
                    <?= _('You don’t have any checked-out items right now.') ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th><?= _('Asset Tag') ?></th>
                                <th><?= _('Name') ?></th>
                                <th><?= _('Model') ?></th>
                                <th><?= _('Assigned Since') ?></th>
                                <th><?= _('Expected Check-in') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checkedOutItems as $row): ?>
                                <tr>
                                    <td><?= h($row['asset_tag'] ?? '') ?></td>
                                    <td><?= h($row['asset_name'] ?? '') ?></td>
                                    <td><?= h($row['model_name'] ?? '') ?></td>
                                    <td><?= h(display_datetime($row['last_checkout'] ?? '')) ?></td>
                                    <td><?= h(display_date($row['expected_checkin'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?php if (empty($reservations)): ?>
                <div class="alert alert-info">
                    <?= _('You don’t have any reservations yet.') ?>
                </div>
            <?php else: ?>
                <?php foreach ($reservations as $res): ?>
                    <?php
                        $resId   = (int)$res['id'];
                        $canEditReservation = booking_reservation_contains_only_models($pdo, $resId);
                        $items   = get_reservation_items_with_names($pdo, $resId);
                        $summary = build_items_summary_text($items);
                        $status  = strtolower((string)($res['status'] ?? ''));
                    ?>
                    <div class="card mb-3 my-reservation-card">
                        <div class="card-body p-0">
                            <div class="my-reservation-header">
                                <div>
                                    <div class="my-reservation-eyebrow"><?= _('Reservation') ?></div>
                                    <h2 class="h4 mb-0">#<?= $resId ?></h2>
                                </div>
                                <span class="my-reservation-status my-reservation-status--<?= h(in_array($status, ['pending', 'confirmed', 'completed', 'cancelled', 'missed'], true) ? $status : 'default') ?>">
                                    <?= h(ucfirst((string)($res['status'] ?? ''))) ?>
                                </span>
                            </div>

                            <div class="my-reservation-content">
                                <div class="my-reservation-meta">
                                    <div class="my-reservation-meta-item">
                                        <span class="my-reservation-label"><?= _('Reserved for') ?></span>
                                        <strong><?= h($res['user_name'] ?? $userName) ?></strong>
                                    </div>
                                    <div class="my-reservation-meta-item">
                                        <span class="my-reservation-label"><?= _('Starts') ?></span>
                                        <strong><?= display_datetime($res['start_datetime'] ?? '') ?></strong>
                                    </div>
                                    <div class="my-reservation-meta-item">
                                        <span class="my-reservation-label"><?= _('Returns') ?></span>
                                        <strong><?= display_datetime($res['end_datetime'] ?? '') ?></strong>
                                    </div>
                                </div>

                                <?php if (trim((string)($res['reservation_note'] ?? '')) !== '' || trim((string)($res['checkout_note'] ?? '')) !== ''): ?>
                                    <div class="my-reservation-notes">
                                        <?php if (trim((string)($res['reservation_note'] ?? '')) !== ''): ?>
                                            <div class="my-reservation-note my-reservation-note--booking">
                                                <span class="my-reservation-label"><?= _('Reservation note') ?></span>
                                                <div><?= h($res['reservation_note']) ?></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (trim((string)($res['checkout_note'] ?? '')) !== ''): ?>
                                            <div class="my-reservation-note my-reservation-note--checkout">
                                                <span class="my-reservation-label"><?= _('Checkout note') ?></span>
                                                <div><?= h($res['checkout_note']) ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($items)): ?>
                                    <div class="my-reservation-section">
                                        <h3 class="h6 mb-2"><?= _('Items in this reservation') ?></h3>
                                        <div class="table-responsive">
                                            <table class="table table-sm align-middle mb-0 my-reservation-items">
                                        <thead>
                                            <tr>
                                                <th><?= _('Item') ?></th>
                                                <th style="width: 80px;"><?= _('Qty') ?></th>
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
                                    </div>
                                <?php elseif ($summary !== ''): ?>
                                    <div class="my-reservation-section">
                                        <span class="my-reservation-label"><?= _('Items') ?></span>
                                        <div><?= h($summary) ?></div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($res['asset_name_cache'])): ?>
                                    <div class="my-reservation-assets">
                                        <span class="my-reservation-label"><?= _('Checked-out assets') ?></span>
                                        <div><?= h($res['asset_name_cache']) ?></div>
                                    </div>
                                <?php endif; ?>

                            <div class="d-flex justify-content-end gap-2 mt-3 my-reservation-actions">
                                <?php if ($status === 'pending' && $canEditReservation): ?>
                                    <a href="reservation_edit.php?id=<?= $resId ?>&from=my_bookings"
                                       class="btn btn-outline-primary btn-sm btn-action">
                                        <?= _('Edit') ?>
                                    </a>
                                <?php endif; ?>
                                <?php if (in_array($status, ['pending', 'confirmed'], true)): ?>
                                    <form method="post"
                                          action="cancel_reservation.php"
                                          onsubmit="return confirm('<?= _('Cancel this reservation? It will remain in your reservation history.') ?>');">
                                        <input type="hidden" name="reservation_id" value="<?= $resId ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <?= _('Cancel Reservation') ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
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
