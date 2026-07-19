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

$qRaw = trim((string)($_GET['q'] ?? ''));
$fromRaw = trim((string)($_GET['from'] ?? ''));
$toRaw = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPageOptions = [10, 25, 50, 100];
$perPageRaw = (int)($_GET['per_page'] ?? 10);
$perPage = in_array($perPageRaw, $perPageOptions, true) ? $perPageRaw : 10;
$sortOptions = [
    'start_desc' => 'start_datetime DESC',
    'start_asc' => 'start_datetime ASC',
    'end_desc' => 'end_datetime DESC',
    'end_asc' => 'end_datetime ASC',
    'status_asc' => 'status ASC',
    'status_desc' => 'status DESC',
    'id_desc' => 'id DESC',
    'id_asc' => 'id ASC',
    'items_asc' => '(SELECT MIN(ri_sort.item_name_cache) FROM reservation_items ri_sort WHERE ri_sort.reservation_id = reservations.id) ASC',
    'items_desc' => '(SELECT MIN(ri_sort.item_name_cache) FROM reservation_items ri_sort WHERE ri_sort.reservation_id = reservations.id) DESC',
];
$sortRaw = trim((string)($_GET['sort'] ?? ''));
$sort = array_key_exists($sortRaw, $sortOptions) ? $sortRaw : 'start_desc';
$totalRows = 0;
$totalPages = 1;

// Load this user's reservations
try {
    $where = ['user_id = :user_id'];
    $params = [':user_id' => $currentUserId];
    if ($qRaw !== '') {
        $where[] = "(
            CAST(id AS CHAR) LIKE :q_id
            OR asset_name_cache LIKE :q_assets
            OR reservation_note LIKE :q_reservation_note
            OR EXISTS (
                SELECT 1
                  FROM reservation_items ri
                 WHERE ri.reservation_id = reservations.id
                   AND (ri.item_name_cache LIKE :q_item OR ri.model_name_cache LIKE :q_model)
            )
        )";
        $likeQuery = '%' . $qRaw . '%';
        $params[':q_id'] = $likeQuery;
        $params[':q_assets'] = $likeQuery;
        $params[':q_reservation_note'] = $likeQuery;
        $params[':q_item'] = $likeQuery;
        $params[':q_model'] = $likeQuery;
    }
    if ($fromRaw !== '') {
        $where[] = 'start_datetime >= :from_date';
        $params[':from_date'] = $fromRaw . ' 00:00:00';
    }
    if ($toRaw !== '') {
        $where[] = 'end_datetime <= :to_date';
        $params[':to_date'] = $toRaw . ' 23:59:59';
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM reservations' . $whereSql);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT * FROM reservations' . $whereSql
        . ' ORDER BY ' . $sortOptions[$sort]
        . ' LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reservations = [];
    $loadError = $e->getMessage();
    $totalRows = 0;
    $totalPages = 1;
}

$checkedOutItems = [];
$checkedOutError = '';
if ($tab === 'checked_out') {
    try {
        $email = strtolower(trim($currentUser['email'] ?? ''));
        $username = strtolower(trim($currentUser['username'] ?? ''));
        $name = strtolower(trim($userName));

        $stmt = $pdo->prepare("
            SELECT checked_out_asset_cache.*,
                   catalogue_model_cache.image_path AS model_image_path
              FROM checked_out_asset_cache
              LEFT JOIN catalogue_model_cache
                ON catalogue_model_cache.model_id = checked_out_asset_cache.model_id
             WHERE (checked_out_asset_cache.assigned_to_email IS NOT NULL AND LOWER(checked_out_asset_cache.assigned_to_email) = :email)
                OR (checked_out_asset_cache.assigned_to_username IS NOT NULL AND LOWER(checked_out_asset_cache.assigned_to_username) = :username)
                OR (checked_out_asset_cache.assigned_to_name IS NOT NULL AND LOWER(checked_out_asset_cache.assigned_to_name) = :name)
             ORDER BY
                CASE WHEN checked_out_asset_cache.expected_checkin IS NULL OR checked_out_asset_cache.expected_checkin = '' THEN 1 ELSE 0 END,
                checked_out_asset_cache.expected_checkin ASC,
                checked_out_asset_cache.last_checkout DESC
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
                <?= layout_user_identity($currentUser, true) ?>
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
                                <?php
                                    $checkedOutName = trim((string)($row['asset_name'] ?? ''));
                                    $checkedOutImagePath = trim((string)($row['model_image_path'] ?? ''));
                                    $checkedOutImageUrl = $checkedOutImagePath !== ''
                                        ? 'image_proxy.php?src=' . urlencode($checkedOutImagePath)
                                        : '';
                                ?>
                                <tr>
                                    <td><?= h($row['asset_tag'] ?? '') ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($checkedOutImageUrl !== ''): ?>
                                                <img src="<?= h($checkedOutImageUrl) ?>"
                                                     alt="<?= h(($checkedOutName !== '' ? $checkedOutName : (string)($row['model_name'] ?? 'Asset')) . ' thumbnail') ?>"
                                                     loading="lazy"
                                                     class="checked-out-item-thumb">
                                            <?php else: ?>
                                                <div class="checked-out-item-thumb checked-out-item-thumb--placeholder" aria-label="<?= _('No image available') ?>">
                                                    <?= _('No image') ?>
                                                </div>
                                            <?php endif; ?>
                                            <span><?= h($checkedOutName) ?></span>
                                        </div>
                                    </td>
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
            <div class="border rounded-3 p-4 mb-4">
                <form class="row g-2 mb-0 align-items-end" method="get" action="my_bookings.php" id="my-reservations-filter-form">
                    <input type="hidden" name="tab" value="reservations">
                    <div class="col-12 col-lg">
                        <label class="form-label" for="my_reservations_search"><?= _('Search') ?></label>
                        <input type="text"
                               id="my_reservations_search"
                               name="q"
                               class="form-control form-control-lg"
                               placeholder="<?= _('Search by reservation ID, items, assets, or reservation notes...') ?>"
                               value="<?= h($qRaw) ?>">
                    </div>
                    <div class="col-auto">
                        <label class="form-label" for="my_reservations_from"><?= _('From') ?></label>
                        <input type="date" id="my_reservations_from" name="from" class="form-control form-control-lg" style="min-width: 160px;" value="<?= h($fromRaw) ?>">
                    </div>
                    <div class="col-auto">
                        <label class="form-label" for="my_reservations_to"><?= _('To') ?></label>
                        <input type="date" id="my_reservations_to" name="to" class="form-control form-control-lg" style="min-width: 160px;" value="<?= h($toRaw) ?>">
                    </div>
                    <div class="col-auto">
                        <label class="form-label" for="my_reservations_sort"><?= _('Sort') ?></label>
                        <select id="my_reservations_sort" name="sort" class="form-select form-select-lg" style="min-width: 220px;">
                            <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>><?= _('Start (newest first)') ?></option>
                            <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>><?= _('Start (oldest first)') ?></option>
                            <option value="end_desc" <?= $sort === 'end_desc' ? 'selected' : '' ?>><?= _('End (latest first)') ?></option>
                            <option value="end_asc" <?= $sort === 'end_asc' ? 'selected' : '' ?>><?= _('End (soonest first)') ?></option>
                            <option value="status_asc" <?= $sort === 'status_asc' ? 'selected' : '' ?>><?= _('Status (A–Z)') ?></option>
                            <option value="status_desc" <?= $sort === 'status_desc' ? 'selected' : '' ?>><?= _('Status (Z–A)') ?></option>
                            <option value="id_desc" <?= $sort === 'id_desc' ? 'selected' : '' ?>><?= _('Reservation ID (high → low)') ?></option>
                            <option value="id_asc" <?= $sort === 'id_asc' ? 'selected' : '' ?>><?= _('Reservation ID (low → high)') ?></option>
                            <option value="items_asc" <?= $sort === 'items_asc' ? 'selected' : '' ?>><?= _('Items (A–Z)') ?></option>
                            <option value="items_desc" <?= $sort === 'items_desc' ? 'selected' : '' ?>><?= _('Items (Z–A)') ?></option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <label class="form-label" for="my_reservations_per_page"><?= _('Page size') ?></label>
                        <select id="my_reservations_per_page" name="per_page" class="form-select form-select-lg" style="min-width: 150px;">
                            <?php foreach ($perPageOptions as $option): ?>
                                <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?> <?= _('per page') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><?= _('Filter') ?></button>
                        <a href="my_bookings.php?tab=reservations" class="btn btn-outline-secondary"><?= _('Clear') ?></a>
                    </div>
                </form>
            </div>

            <?php if (empty($reservations)): ?>
                <div class="alert alert-info">
                    <?= ($qRaw !== '' || $fromRaw !== '' || $toRaw !== '')
                        ? _('There are no reservations matching your filters.')
                        : _('You don’t have any reservations yet.') ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle reservation-history-table my-reservations-history-table">
                        <thead>
                            <tr>
                                <th><?= layout_sortable_column_header(_('ID'), 'id_asc', 'id_desc', $sort) ?></th>
                                <th><?= layout_sortable_column_header(_('Items Reserved'), 'items_asc', 'items_desc', $sort) ?></th>
                                <th><?= layout_sortable_column_header(_('Start'), 'start_asc', 'start_desc', $sort) ?></th>
                                <th><?= layout_sortable_column_header(_('End'), 'end_asc', 'end_desc', $sort) ?></th>
                                <th><?= layout_sortable_column_header(_('Status'), 'status_asc', 'status_desc', $sort) ?></th>
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
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                    <?php
                        $pagerQuery = [
                            'tab' => 'reservations',
                            'q' => $qRaw,
                            'from' => $fromRaw,
                            'to' => $toRaw,
                            'sort' => $sort,
                            'per_page' => $perPage,
                        ];
                    ?>
                    <nav class="mt-3" aria-label="<?= _('Reservation pages') ?>">
                        <ul class="pagination justify-content-center">
                            <?php $pagerQuery['page'] = max(1, $page - 1); ?>
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="my_bookings.php?<?= h(http_build_query($pagerQuery)) ?>"><?= _('Previous') ?></a>
                            </li>
                            <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                                <?php $pagerQuery['page'] = $pageNumber; ?>
                                <li class="page-item <?= $pageNumber === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="my_bookings.php?<?= h(http_build_query($pagerQuery)) ?>"><?= $pageNumber ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php $pagerQuery['page'] = min($totalPages, $page + 1); ?>
                            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="my_bookings.php?<?= h(http_build_query($pagerQuery)) ?>"><?= _('Next') ?></a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
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
