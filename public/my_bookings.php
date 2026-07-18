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
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle reservation-history-table my-reservations-history-table">
                        <thead>
                            <tr>
                                <th><?= _('ID') ?></th>
                                <th><?= _('Items Reserved') ?></th>
                                <th><?= _('Start') ?></th>
                                <th><?= _('End') ?></th>
                                <th><?= _('Status') ?></th>
                                <th><?= _('Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $res): ?>
                                <?php
                                    $resId = (int)$res['id'];
                                    $canEditReservation = booking_reservation_contains_only_models($pdo, $resId);
                                    $items = get_reservation_items_with_names($pdo, $resId);
                                    $status = strtolower((string)($res['status'] ?? ''));
                                    $reservationNote = trim((string)($res['reservation_note'] ?? ''));
                                    $checkoutNote = trim((string)($res['checkout_note'] ?? ''));
                                    $assignedAssets = array_values(array_filter(array_map('trim', preg_split('/,(?![^()]*\))/', (string)($res['asset_name_cache'] ?? '')) ?: []), 'strlen'));
                                ?>
                                <tr>
                                    <td data-label="<?= _('ID') ?>">#<?= $resId ?></td>
                                    <td data-label="<?= _('Items Reserved') ?>" class="items-cell">
                                        <?php if (!empty($items)): ?>
                                            <details class="items-section">
                                                <summary><strong><?= _('Items Reserved:') ?></strong></summary>
                                                <div class="items-section-body items-section-body--scroll">
                                                    <ul class="items-list">
                                                        <?php foreach ($items as $item): ?>
                                                            <li><?= h($item['name'] ?? '') ?><?= (int)($item['qty'] ?? 0) > 1 ? ' (' . (int)$item['qty'] . ')' : '' ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                        <?php if (!empty($assignedAssets)): ?>
                                            <details class="items-section mt-2">
                                                <summary><strong><?= _('Assets Assigned:') ?></strong></summary>
                                                <div class="items-section-body">
                                                    <ul class="items-list">
                                                        <?php foreach ($assignedAssets as $assetLabel): ?>
                                                            <li><?= h($assetLabel) ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="<?= _('Start') ?>"><?= display_datetime($res['start_datetime'] ?? '') ?></td>
                                    <td data-label="<?= _('End') ?>"><?= display_datetime($res['end_datetime'] ?? '') ?></td>
                                    <td data-label="<?= _('Status') ?>"><?= h($res['status'] ?? '') ?></td>
                                    <td data-label="<?= _('Actions') ?>" class="actions-cell">
                                        <div class="d-flex gap-2">
                                            <a href="reservation_detail.php?id=<?= $resId ?>" class="btn btn-sm btn-outline-secondary btn-action"><?= _('View') ?></a>
                                            <?php if ($status === 'pending' && $canEditReservation): ?>
                                                <a href="reservation_edit.php?id=<?= $resId ?>&from=my_bookings" class="btn btn-outline-primary btn-sm btn-action"><?= _('Edit') ?></a>
                                            <?php endif; ?>
                                            <?php if (in_array($status, ['pending', 'confirmed'], true)): ?>
                                                <form method="post" action="cancel_reservation.php" onsubmit="return confirm('<?= _('Cancel this reservation? It will remain in your reservation history.') ?>');">
                                                    <input type="hidden" name="reservation_id" value="<?= $resId ?>">
                                                    <button type="submit" class="btn btn-outline-danger btn-sm btn-action"><?= _('Cancel') ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-grid gap-2 mt-2 reservation-note-actions">
                                            <button type="button" class="btn btn-sm btn-outline-primary js-view-my-reservation-note"
                                                    data-note-title="<?= h(_('Reservation') . ' #' . $resId . ' — ' . _('Reservation Notes')) ?>"
                                                    data-note="<?= h((string)json_encode($reservationNote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                                <?= $reservationNote === '' ? ' disabled aria-disabled="true"' : '' ?>>
                                                <?= _('View Reservation Notes') ?>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary js-view-my-reservation-note"
                                                    data-note-title="<?= h(_('Reservation') . ' #' . $resId . ' — ' . _('Checkout Notes')) ?>"
                                                    data-note="<?= h((string)json_encode($checkoutNote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                                <?= $checkoutNote === '' ? ' disabled aria-disabled="true"' : '' ?>>
                                                <?= _('View Checkout Notes') ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <dialog id="my-reservation-note-dialog" class="border-0 rounded-3 shadow p-0" style="max-width: 620px; width: calc(100% - 2rem);">
            <div class="p-4">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <h5 id="my-reservation-note-dialog-title" class="mb-0"><?= _('Reservation Notes') ?></h5>
                    <button type="button" class="btn-close" id="close-my-reservation-note-dialog" aria-label="<?= _('Close') ?>"></button>
                </div>
                <div id="my-reservation-note-dialog-content" class="reservation-note-dialog-content"></div>
            </div>
        </dialog>

    </div>
</div>
<script>
(function () {
    const dialog = document.getElementById('my-reservation-note-dialog');
    const title = document.getElementById('my-reservation-note-dialog-title');
    const content = document.getElementById('my-reservation-note-dialog-content');
    if (!dialog || !title || !content) return;

    document.querySelectorAll('.js-view-my-reservation-note:not([disabled])').forEach(function (button) {
        button.addEventListener('click', function () {
            title.textContent = button.dataset.noteTitle || <?= json_encode(_('Reservation Notes'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            try {
                content.textContent = JSON.parse(button.dataset.note || '""');
            } catch (_) {
                content.textContent = '';
            }
            dialog.showModal();
        });
    });

    const closeButton = document.getElementById('close-my-reservation-note-dialog');
    if (closeButton) {
        closeButton.addEventListener('click', function () {
            dialog.close();
        });
    }
}());
</script>
<?php layout_footer(); ?>
</body>
</html>
