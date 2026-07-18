<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/staff_group_visibility.php';
require_once SRC_PATH . '/layout.php';

$active    = basename($_SERVER['PHP_SELF']);
$isAdmin   = !empty($currentUser['is_admin']);
$isStaff   = !empty($currentUser['is_staff']) || $isAdmin;
$config    = load_config();
$restrictReservationsToSameGroup = staff_group_visibility_restriction_enabled($config, $currentUser);
$embedded  = defined('RESERVATIONS_EMBED');
$pageBase  = $embedded ? 'reservations.php' : 'staff_reservations.php';
$baseQuery = $embedded ? ['tab' => 'history'] : [];
$editSuffix = $embedded ? '&from=reservations' : '';

function display_date(?string $isoDate): string
{
    return app_format_date($isoDate);
}

function display_datetime(?string $isoDatetime): string
{
    return app_format_datetime($isoDatetime);
}

// Only staff/admin allowed
if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$deletedMsg = '';
if (!empty($_GET['deleted'])) {
    $deletedMsg = 'Reservation #' . (int)$_GET['deleted'] . ' has been deleted.';
}
$updatedMsg = '';
if (!empty($_GET['updated'])) {
    $updatedMsg = 'Reservation #' . (int)$_GET['updated'] . ' has been updated.';
}
$cancelledMsg = '';
if (!empty($_GET['cancelled'])) {
    $cancelledMsg = 'Reservation #' . (int)$_GET['cancelled'] . ' has been cancelled.';
}
$restoredMsg = '';
$restoreError = '';

// Filters
$qRaw    = trim($_GET['q'] ?? '');
$fromRaw = trim($_GET['from'] ?? '');
$toRaw   = trim($_GET['to'] ?? '');
$pageRaw = (int)($_GET['page'] ?? 1);
$perPageRaw = (int)($_GET['per_page'] ?? 10);
$sortRaw = trim($_GET['sort'] ?? '');

$q        = $qRaw !== '' ? $qRaw : null;
$dateFrom = $fromRaw !== '' ? $fromRaw : null;
$dateTo   = $toRaw !== '' ? $toRaw : null;
$page     = $pageRaw > 0 ? $pageRaw : 1;
$perPageOptions = [10, 25, 50, 100];
$perPage = in_array($perPageRaw, $perPageOptions, true) ? $perPageRaw : 10;
$sortOptions = [
    'start_desc' => 'start_datetime DESC',
    'start_asc' => 'start_datetime ASC',
    'end_desc' => 'end_datetime DESC',
    'end_asc' => 'end_datetime ASC',
    'user_asc' => 'user_name ASC',
    'user_desc' => 'user_name DESC',
    'status_asc' => 'status ASC',
    'status_desc' => 'status DESC',
    'id_desc' => 'id DESC',
    'id_asc' => 'id ASC',
];
$sort = array_key_exists($sortRaw, $sortOptions) ? $sortRaw : 'start_desc';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore_missed') {
    $restoreId = (int)($_POST['reservation_id'] ?? 0);
    if ($restoreId <= 0) {
        $restoreError = 'Invalid reservation selected for restore.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = :id');
            $stmt->execute([':id' => $restoreId]);
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$reservation || ($reservation['status'] ?? '') !== 'missed') {
                throw new Exception('Reservation is not in a missed state.');
            }
            if (!staff_group_visibility_reservation_visible($reservation, $currentUser, $restrictReservationsToSameGroup)) {
                throw new Exception('You do not have access to that reservation.');
            }

            $start = $reservation['start_datetime'] ?? '';
            $end   = $reservation['end_datetime'] ?? '';
            $nowDt = new DateTime();
            $newStart = $nowDt->format('Y-m-d H:i:s');
            $newEnd = $end;
            if ($start !== '' && $end !== '') {
                $oldStartDt = new DateTime($start);
                $oldEndDt = new DateTime($end);
                $durationSeconds = max(0, $oldEndDt->getTimestamp() - $oldStartDt->getTimestamp());
                if ($durationSeconds > 0) {
                    $newEnd = date('Y-m-d H:i:s', $nowDt->getTimestamp() + $durationSeconds);
                }
            }
            if ($newEnd === '' || strtotime($newEnd) <= strtotime($newStart)) {
                $startDt = new DateTime($newStart);
                $fallbackEnd = (clone $startDt)->modify('+1 day')->setTime(9, 0, 0);
                $newEnd = $fallbackEnd->format('Y-m-d H:i:s');
            }

            $itemsStmt = $pdo->prepare("
                SELECT " . booking_reservation_item_select_sql($pdo) . "
                  FROM reservation_items
                 WHERE reservation_id = :id
            ");
            $itemsStmt->execute([':id' => $restoreId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) {
                throw new Exception('This reservation has no items to restore.');
            }

            foreach ($items as $item) {
                $itemType = booking_normalize_item_type((string)($item['item_type'] ?? 'model'));
                $itemId = (int)($item['item_id'] ?? 0);
                $qty = (int)($item['quantity'] ?? 0);
                $itemName = trim((string)($item['item_name_cache'] ?? ''));
                if ($itemName === '') {
                    $itemName = ucfirst($itemType) . ' #' . $itemId;
                }

                if ($itemId <= 0 || $qty <= 0) {
                    continue;
                }

                $existingBooked = booking_count_reserved_item_quantity(
                    $pdo,
                    $itemType,
                    $itemId,
                    $newStart,
                    $newEnd,
                    ['pending', 'confirmed']
                );
                $totalRequestable = booking_get_requestable_total_for_item($itemType, $itemId);
                $activeCheckedOut = booking_count_effective_checked_out_for_item($itemType, $itemId, $config);
                $availableNow = $totalRequestable > 0 ? max(0, $totalRequestable - $activeCheckedOut) : 0;

                if ($totalRequestable > 0 && $existingBooked + $qty > $availableNow) {
                    throw new Exception('Not enough units available for "' . $itemName . '" in that time period.');
                }
            }

            $upd = $pdo->prepare("
                UPDATE reservations
                SET status = 'pending',
                    start_datetime = :start,
                    end_datetime = :end
                WHERE id = :id
            ");
            $upd->execute([
                ':id'    => $restoreId,
                ':start' => $newStart,
                ':end'   => $newEnd,
            ]);
            $restoredMsg = 'Reservation #' . $restoreId . ' has been re-enabled.';

            $assetLabels = [];
            foreach ($items as $item) {
                $name = trim((string)($item['item_name_cache'] ?? ''));
                $qty = (int)($item['quantity'] ?? 0);
                if ($name === '') {
                    $name = 'Item';
                }
                $assetLabels[] = $qty > 1 ? ($name . ' (x' . $qty . ')') : $name;
            }

            activity_log_event('reservation_restored', 'Reservation restored from missed', [
                'subject_type' => 'reservation',
                'subject_id'   => $restoreId,
                'metadata'     => [
                    'assets' => $assetLabels,
                    'start' => $newStart,
                    'end'   => $newEnd,
                ],
            ]);
        } catch (Exception $e) {
            $restoreError = 'Unable to restore reservation: ' . $e->getMessage();
        }
    }
}

// Load filtered reservations (paginated)
try {
    $where  = [];
    $params = [];

    if ($q !== null) {
        $where[] = '(user_name LIKE :q OR asset_name_cache LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    if ($dateFrom !== null) {
        $where[] = 'start_datetime >= :from';
        $params[':from'] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== null) {
        $where[] = 'end_datetime <= :to';
        $params[':to'] = $dateTo . ' 23:59:59';
    }

    if ($restrictReservationsToSameGroup) {
        $cachedVisibleEmails = staff_group_visibility_cached_visible_emails_for_current_user(
            $currentUser,
            $restrictReservationsToSameGroup
        );
        $fastVisibleEmails = is_array($cachedVisibleEmails)
            ? $cachedVisibleEmails
            : staff_group_visibility_visible_user_emails_for_current_user($currentUser, true);
        $visibleEmails = [];

        if (is_array($fastVisibleEmails)) {
            foreach ($fastVisibleEmails as $email) {
                $email = strtolower(trim((string)$email));
                if ($email !== '') {
                    $visibleEmails[$email] = $email;
                }
            }
        }

        if (!is_array($cachedVisibleEmails)) {
            $distinctEmailSql = "SELECT DISTINCT LOWER(user_email) FROM reservations";
            if (!empty($where)) {
                $distinctEmailSql .= ' WHERE ' . implode(' AND ', $where);
            }
            $emailStmt = $pdo->prepare($distinctEmailSql);
            foreach ($params as $key => $value) {
                $emailStmt->bindValue($key, $value);
            }
            $emailStmt->execute();

            foreach ($emailStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $email) {
                $email = strtolower(trim((string)$email));
                if ($email === '' || isset($visibleEmails[$email])) {
                    continue;
                }
                if (staff_group_visibility_user_can_see_email($currentUser, $email, $restrictReservationsToSameGroup)) {
                    $visibleEmails[$email] = $email;
                }
            }
        }

        if (empty($visibleEmails)) {
            $where[] = '1 = 0';
        } else {
            $emailPlaceholders = [];
            foreach (array_values($visibleEmails) as $idx => $email) {
                $paramName = ':visible_email_' . $idx;
                $emailPlaceholders[] = $paramName;
                $params[$paramName] = $email;
            }
            $where[] = 'LOWER(user_email) IN (' . implode(',', $emailPlaceholders) . ')';
        }
    }

    $sql = "SELECT * FROM reservations";

    $countSql = "SELECT COUNT(*) FROM reservations";
    if (!empty($where)) {
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $sql .= $whereSql;
        $countSql .= $whereSql;
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql .= ' ORDER BY ' . $sortOptions[$sort] . ' LIMIT :limit OFFSET :offset';
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
?>
<?php if (!$embedded): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>All Reservations – Admin</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
<?php endif; ?>
        <div class="page-header">
        <h1>All Reservations</h1>
            <div class="page-subtitle">
                View, filter, and delete any past, present or future reservation.
                <?php if ($restrictReservationsToSameGroup): ?>
                    Only reservations for users in one of your Snipe-IT groups are shown.
                <?php endif; ?>
            </div>
        </div>

        <!-- App navigation -->
        <?php if (!$embedded): ?>
            <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <?php endif; ?>

        <!-- Top bar -->
        <?php if (!$embedded): ?>
            <div class="top-bar mb-3">
                <div class="top-bar-user">
                    Logged in as:
                    <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                    (<?= h($currentUser['email'] ?? '') ?>)
                </div>
                <div class="top-bar-actions">
                    <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($deletedMsg)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($deletedMsg) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($updatedMsg)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($updatedMsg) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($cancelledMsg)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($cancelledMsg) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($restoredMsg)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($restoredMsg) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($restoreError)): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($restoreError) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($loadError ?? '')): ?>
            <div class="alert alert-danger">
                Error loading reservations: <?= htmlspecialchars($loadError) ?>
            </div>
        <?php endif; ?>

        <?php
            $actionUrl = $pageBase;
            if (!empty($baseQuery)) {
                $actionUrl .= '?' . http_build_query($baseQuery);
            }
        ?>
        <!-- Filters -->
        <div class="border rounded-3 p-4 mb-4">
            <form class="row g-2 mb-0 align-items-end" method="get" action="<?= h($actionUrl) ?>" id="reservation-history-filter-form">
                <?php foreach ($baseQuery as $k => $v): ?>
                    <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                <?php endforeach; ?>
                <div class="col-12 col-lg">
                    <input type="text"
                           name="q"
                           class="form-control form-control-lg"
                           placeholder="Search by user or items..."
                           value="<?= htmlspecialchars($qRaw) ?>">
                </div>
                <div class="col-auto">
                    <input type="date"
                           name="from"
                           class="form-control form-control-lg"
                           style="min-width: 160px;"
                           value="<?= htmlspecialchars($fromRaw) ?>"
                           placeholder="From date">
                </div>
                <div class="col-auto">
                    <input type="date"
                           name="to"
                           class="form-control form-control-lg"
                           style="min-width: 160px;"
                           value="<?= htmlspecialchars($toRaw) ?>"
                           placeholder="To date">
                </div>
                <div class="col-auto">
                    <select name="sort" class="form-select form-select-lg" aria-label="Sort reservations" style="min-width: 240px;">
                        <option value="start_desc" <?= $sort === 'start_desc' ? 'selected' : '' ?>>Start (newest first)</option>
                        <option value="start_asc" <?= $sort === 'start_asc' ? 'selected' : '' ?>>Start (oldest first)</option>
                        <option value="end_desc" <?= $sort === 'end_desc' ? 'selected' : '' ?>>End (latest first)</option>
                        <option value="end_asc" <?= $sort === 'end_asc' ? 'selected' : '' ?>>End (soonest first)</option>
                        <option value="user_asc" <?= $sort === 'user_asc' ? 'selected' : '' ?>>User (A–Z)</option>
                        <option value="user_desc" <?= $sort === 'user_desc' ? 'selected' : '' ?>>User (Z–A)</option>
                        <option value="status_asc" <?= $sort === 'status_asc' ? 'selected' : '' ?>>Status (A–Z)</option>
                        <option value="status_desc" <?= $sort === 'status_desc' ? 'selected' : '' ?>>Status (Z–A)</option>
                        <option value="id_desc" <?= $sort === 'id_desc' ? 'selected' : '' ?>>Reservation ID (high → low)</option>
                        <option value="id_asc" <?= $sort === 'id_asc' ? 'selected' : '' ?>>Reservation ID (low → high)</option>
                    </select>
                </div>
                <div class="col-auto">
                    <select name="per_page" class="form-select form-select-lg" style="min-width: 180px;">
                        <?php foreach ($perPageOptions as $opt): ?>
                            <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>>
                                <?= $opt ?> per page
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 d-flex gap-2">
                    <button class="btn btn-primary w-100" type="submit">Filter</button>
                </div>
                <div class="col-md-1 d-flex gap-2">
                    <?php
                        $clearUrl = $pageBase;
                        if (!empty($baseQuery)) {
                            $clearUrl .= '?' . http_build_query($baseQuery);
                        }
                    ?>
                <a href="<?= h($clearUrl) ?>" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
        </form>
        </div>

        <?php if (empty($reservations)): ?>
            <div class="alert alert-info">
                There are no reservations matching your filters.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle reservation-history-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User Name</th>
                            <th>Items Reserved</th>
                            <th>Notes</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Status</th>
                            <th style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $r): ?>
                            <?php
                                $canEditReservation = booking_reservation_contains_only_models($pdo, (int)$r['id']);
                                $items      = get_reservation_items_with_names($pdo, (int)$r['id']);
                                $itemsLines = [];
                                foreach ($items as $item) {
                                    $name = $item['name'] ?? '';
                                    $qty = isset($item['qty']) ? (int)$item['qty'] : 0;
                                    if ($name === '' || $qty <= 0) {
                                        continue;
                                    }
                                    $itemsLines[] = $qty > 1
                                        ? sprintf('%s (%d)', $name, $qty)
                                        : $name;
                                }
                                $itemsText = $itemsLines ? '<ul class="items-list"><li>' . implode('</li><li>', array_map('h', $itemsLines)) . '</li></ul>' : '';
                                $modelsHtml = '';
                                $status     = strtolower((string)($r['status'] ?? ''));
                                if ($itemsText !== '') {
                                    $modelsHtml = '<details class="items-section">'
                                        . '<summary><strong>Items Reserved:</strong></summary>'
                                        . '<div class="items-section-body items-section-body--scroll">' . $itemsText . '</div>'
                                        . '</details>';
                                }
                                $assetsHtml = '';
                                if (!empty($r['asset_name_cache'])) {
                                    $assetRaw = (string)$r['asset_name_cache'];
                                    $assetParts = preg_split('/,(?![^()]*\\))/', $assetRaw);
                                    $assetParts = array_values(array_filter(array_map('trim', $assetParts), 'strlen'));
                                    $assetLis = [];
                                    $sourceParts = $assetParts ?: [$assetRaw];
                                    foreach ($sourceParts as $part) {
                                        $part = trim($part);
                                        if ($part === '') {
                                            continue;
                                        }
                                        $openPos = strpos($part, '(');
                                        if ($openPos !== false) {
                                            $tag = trim(substr($part, 0, $openPos));
                                            $rest = trim(substr($part, $openPos));
                                            $assetLis[] = '<strong>' . h($tag) . '</strong> ' . h($rest);
                                        } else {
                                            $assetLis[] = '<strong>' . h($part) . '</strong>';
                                        }
                                    }
                                    $assetLines = $assetLis
                                        ? '<ul class="items-list"><li>' . implode('</li><li>', $assetLis) . '</li></ul>'
                                        : '';
                                    $assetsHtml = '<details class="items-section mt-2">'
                                        . '<summary><strong>Assets Assigned:</strong></summary>'
                                        . '<div class="items-section-body">' . $assetLines . '</div>'
                                        . '</details>';
                                }
                                $itemsText = $modelsHtml . $assetsHtml;
                            ?>
                            <tr>
                                <td data-label="ID">#<?= (int)$r['id'] ?></td>
                                <td data-label="User Name"><?= h($r['user_name'] ?? '(Unknown)') ?></td>
                                <td data-label="Items Reserved" class="items-cell">
                                    <?= $itemsText !== '' ? '<div class="items-cell-content">' . $itemsText . '</div>' : '' ?>
                                </td>
                                <td data-label="Notes">
                                    <?php
                                        $reservationNote = trim((string)($r['reservation_note'] ?? ''));
                                        $checkoutNote = trim((string)($r['checkout_note'] ?? ''));
                                    ?>
                                    <div class="d-grid gap-2 reservation-note-actions">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary js-view-reservation-note"
                                                data-note-title="Reservation #<?= (int)$r['id'] ?> — Reservation Notes"
                                                data-note="<?= h((string)json_encode($reservationNote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                            <?= $reservationNote === '' ? ' disabled aria-disabled="true"' : '' ?>>
                                            View Reservation Notes
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary js-view-reservation-note"
                                                data-note-title="Reservation #<?= (int)$r['id'] ?> — Checkout Notes"
                                                data-note="<?= h((string)json_encode($checkoutNote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
                                            <?= $checkoutNote === '' ? ' disabled aria-disabled="true"' : '' ?>>
                                            View Checkout Notes
                                        </button>
                                    </div>
                                </td>
                                <td data-label="Start"><?= display_datetime($r['start_datetime'] ?? '') ?></td>
                                <td data-label="End"><?= display_datetime($r['end_datetime'] ?? '') ?></td>
                                <td data-label="Status"><?= h($r['status'] ?? '') ?></td>
                                <td data-label="Actions" class="actions-cell">
                                    <div class="d-flex gap-2">
                                        <a href="reservation_detail.php?id=<?= (int)$r['id'] ?>"
                                           class="btn btn-sm btn-outline-secondary btn-action">
                                            View
                                        </a>
                                        <?php if ($status === 'pending' && $canEditReservation): ?>
                                            <a href="reservation_edit.php?id=<?= (int)$r['id'] ?><?= h($editSuffix) ?>"
                                               class="btn btn-sm btn-outline-primary btn-action">
                                                Edit
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($status === 'missed'): ?>
                                            <form method="post" action="<?= h($actionUrl) ?>">
                                                <input type="hidden" name="action" value="restore_missed">
                                                <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                                                <?php if ($qRaw !== ''): ?>
                                                    <input type="hidden" name="q" value="<?= h($qRaw) ?>">
                                                <?php endif; ?>
                                                <?php if ($fromRaw !== ''): ?>
                                                    <input type="hidden" name="from" value="<?= h($fromRaw) ?>">
                                                <?php endif; ?>
                                                <?php if ($toRaw !== ''): ?>
                                                    <input type="hidden" name="to" value="<?= h($toRaw) ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                                                <input type="hidden" name="page" value="<?= (int)$page ?>">
                                                <input type="hidden" name="sort" value="<?= h($sort) ?>">
                                                <?php foreach ($baseQuery as $k => $v): ?>
                                                    <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                                                <?php endforeach; ?>
                                                <button class="btn btn-sm btn-outline-success btn-action" type="submit">
                                                    Restore
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($status === 'pending'): ?>
                                            <button class="btn btn-sm btn-outline-danger btn-action js-cancel-pending-reservation"
                                                    type="button"
                                                    data-reservation-id="<?= (int)$r['id'] ?>">
                                                Cancel
                                            </button>
                                        <?php elseif ($status === 'completed'): ?>
                                            <button class="btn btn-sm btn-outline-danger btn-action js-delete-completed-reservation"
                                                    type="button"
                                                    data-reservation-id="<?= (int)$r['id'] ?>">
                                                Delete
                                            </button>
                                        <?php else: ?>
                                            <form method="post"
                                                  action="delete_reservation.php"
                                                  onsubmit="return confirm('Delete this reservation and all its items? This cannot be undone.');">
                                                <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                                                <?php if ($embedded): ?>
                                                    <input type="hidden" name="return_to_history" value="1">
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-danger btn-action" type="submit">
                                                    Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <?php
                    $pagerBase = $pageBase;
                    $pagerQuery = array_merge($baseQuery, [
                        'q' => $qRaw,
                        'from' => $fromRaw,
                        'to' => $toRaw,
                        'per_page' => $perPage,
                        'sort' => $sort,
                    ]);
                ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php
                            $prevPage = max(1, $page - 1);
                            $nextPage = min($totalPages, $page + 1);
                            $pagerQuery['page'] = $prevPage;
                            $prevUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                            $pagerQuery['page'] = $nextPage;
                            $nextUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                        ?>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($prevUrl) ?>">Previous</a>
                        </li>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php
                                $pagerQuery['page'] = $p;
                                $pageUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                            ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= h($pageUrl) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($nextUrl) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

        <dialog id="reservation-note-dialog" class="border-0 rounded-3 shadow p-0" style="max-width: 620px; width: calc(100% - 2rem);">
            <div class="p-4">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <h5 id="reservation-note-dialog-title" class="mb-0">Reservation Notes</h5>
                    <button type="button" class="btn-close" id="close-reservation-note-dialog" aria-label="Close"></button>
                </div>
                <div id="reservation-note-dialog-content" class="reservation-note-dialog-content"></div>
            </div>
        </dialog>

        <dialog id="cancel-pending-reservation-dialog" class="border-0 rounded-3 shadow p-0" style="max-width: 520px; width: calc(100% - 2rem);">
            <form method="post" action="delete_reservation.php" class="p-4">
                <input type="hidden" name="action" value="cancel_pending">
                <input type="hidden" name="reservation_id" id="cancel-pending-reservation-id" value="">
                <?php if ($embedded): ?>
                    <input type="hidden" name="return_to_history" value="1">
                <?php endif; ?>
                <h5>Cancel pending reservation?</h5>
                <p class="text-muted">The reservation will remain in the history with a cancelled status and will no longer affect availability.</p>
                <?php if ($isAdmin): ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="delete_permanently" value="1" id="delete-pending-reservation-permanently">
                        <label class="form-check-label" for="delete-pending-reservation-permanently">
                            Permanently delete this reservation and all its items instead
                        </label>
                    </div>
                <?php endif; ?>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="close-cancel-pending-reservation-dialog">Keep reservation</button>
                    <button type="submit" class="btn btn-danger">Confirm</button>
                </div>
            </form>
        </dialog>

        <dialog id="delete-completed-reservation-dialog" class="border-0 rounded-3 shadow p-0" style="max-width: 560px; width: calc(100% - 2rem);">
            <form method="post" action="delete_reservation.php" class="p-4">
                <input type="hidden" name="action" value="delete_completed">
                <input type="hidden" name="reservation_id" id="delete-completed-reservation-id" value="">
                <?php if ($embedded): ?>
                    <input type="hidden" name="return_to_history" value="1">
                <?php endif; ?>
                <h5>Delete completed reservation?</h5>
                <div class="alert alert-warning">
                    Deleting a completed reservation may cause issues in Snipe-IT if any equipment from it is still checked out. This removes only the scheduler’s reservation history; it does not check items back into Snipe-IT.
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input"
                           type="checkbox"
                           name="acknowledge_checked_out_risk"
                           value="1"
                           id="acknowledge-completed-reservation-risk"
                           required>
                    <label class="form-check-label" for="acknowledge-completed-reservation-risk">
                        I understand that items may still be checked out in Snipe-IT and want to permanently delete this reservation.
                    </label>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="close-delete-completed-reservation-dialog">Keep reservation</button>
                    <button type="submit" class="btn btn-danger">Delete permanently</button>
                </div>
            </form>
        </dialog>

        <script>
        (function () {
            const noteDialog = document.getElementById('reservation-note-dialog');
            const noteTitle = document.getElementById('reservation-note-dialog-title');
            const noteContent = document.getElementById('reservation-note-dialog-content');
            if (noteDialog && noteTitle && noteContent) {
                document.querySelectorAll('.js-view-reservation-note:not([disabled])').forEach(function (button) {
                    button.addEventListener('click', function () {
                        noteTitle.textContent = button.dataset.noteTitle || 'Reservation Notes';
                        try {
                            noteContent.textContent = JSON.parse(button.dataset.note || '""');
                        } catch (_) {
                            noteContent.textContent = '';
                        }
                        noteDialog.showModal();
                    });
                });

                const closeNoteButton = document.getElementById('close-reservation-note-dialog');
                if (closeNoteButton) {
                    closeNoteButton.addEventListener('click', function () {
                        noteDialog.close();
                    });
                }
            }

            const dialog = document.getElementById('cancel-pending-reservation-dialog');
            const idInput = document.getElementById('cancel-pending-reservation-id');
            const deleteCheckbox = document.getElementById('delete-pending-reservation-permanently');
            if (!dialog || !idInput) return;

            document.querySelectorAll('.js-cancel-pending-reservation').forEach(function (button) {
                button.addEventListener('click', function () {
                    idInput.value = button.dataset.reservationId || '';
                    if (deleteCheckbox) deleteCheckbox.checked = false;
                    dialog.showModal();
                });
            });

            const closeButton = document.getElementById('close-cancel-pending-reservation-dialog');
            if (closeButton) {
                closeButton.addEventListener('click', function () {
                    dialog.close();
                });
            }

            const completedDialog = document.getElementById('delete-completed-reservation-dialog');
            const completedIdInput = document.getElementById('delete-completed-reservation-id');
            const riskCheckbox = document.getElementById('acknowledge-completed-reservation-risk');
            if (completedDialog && completedIdInput) {
                document.querySelectorAll('.js-delete-completed-reservation').forEach(function (button) {
                    button.addEventListener('click', function () {
                        completedIdInput.value = button.dataset.reservationId || '';
                        if (riskCheckbox) riskCheckbox.checked = false;
                        completedDialog.showModal();
                    });
                });

                const closeCompletedButton = document.getElementById('close-delete-completed-reservation-dialog');
                if (closeCompletedButton) {
                    closeCompletedButton.addEventListener('click', function () {
                        completedDialog.close();
                    });
                }
            }
        }());
        </script>

<?php if (!$embedded): ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
<?php endif; ?>
<?php if ($embedded): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('reservation-history-filter-form');
    const sortSelect = form ? form.querySelector('select[name="sort"]') : null;
    if (form && sortSelect) {
        sortSelect.addEventListener('change', function () {
            form.submit();
        });
    }
});
</script>
<?php endif; ?>
