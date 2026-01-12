<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/layout.php';

$active    = basename($_SERVER['PHP_SELF']);
$isStaff   = !empty($currentUser['is_admin']);
$embedded  = defined('RESERVATIONS_EMBED');
$pageBase  = $embedded ? 'reservations.php' : 'staff_reservations.php';
$baseQuery = $embedded ? ['tab' => 'history'] : [];
$editSuffix = $embedded ? '&from=reservations' : '';

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

// Only staff/admin allowed
if (empty($currentUser['is_admin'])) {
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
$restoredMsg = '';
$restoreError = '';

// Filters
$qRaw    = trim($_GET['q'] ?? '');
$fromRaw = trim($_GET['from'] ?? '');
$toRaw   = trim($_GET['to'] ?? '');

$q        = $qRaw !== '' ? $qRaw : null;
$dateFrom = $fromRaw !== '' ? $fromRaw : null;
$dateTo   = $toRaw !== '' ? $toRaw : null;

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

            $itemsStmt = $pdo->prepare('
                SELECT model_id, quantity, model_name_cache
                FROM reservation_items
                WHERE reservation_id = :id
            ');
            $itemsStmt->execute([':id' => $restoreId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) {
                throw new Exception('This reservation has no items to restore.');
            }

            foreach ($items as $item) {
                $mid = (int)($item['model_id'] ?? 0);
                $qty = (int)($item['quantity'] ?? 0);
                $modelName = $item['model_name_cache'] ?? ('Model #' . $mid);

                if ($mid <= 0 || $qty <= 0) {
                    continue;
                }

                $sql = "
                    SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
                    FROM reservation_items ri
                    JOIN reservations r ON r.id = ri.reservation_id
                    WHERE ri.model_id = :model_id
                      AND r.status IN ('pending','confirmed')
                      AND (r.start_datetime < :end AND r.end_datetime > :start)
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':model_id' => $mid,
                    ':start'    => $newStart,
                    ':end'      => $newEnd,
                ]);
                $row = $stmt->fetch();
                $existingBooked = $row ? (int)$row['booked_qty'] : 0;

                $totalRequestable = count_requestable_assets_by_model($mid);
                $activeCheckedOut = count_checked_out_assets_by_model($mid);
                $availableNow = $totalRequestable > 0 ? max(0, $totalRequestable - $activeCheckedOut) : 0;

                if ($totalRequestable > 0 && $existingBooked + $qty > $availableNow) {
                    throw new Exception('Not enough units available for "' . $modelName . '" in that time period.');
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
        } catch (Exception $e) {
            $restoreError = 'Unable to restore reservation: ' . $e->getMessage();
        }
    }
}

// Load filtered reservations
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

    $sql = "SELECT * FROM reservations";

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY start_datetime DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reservations = [];
    $loadError = $e->getMessage();
}
?>
<?php if (!$embedded): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservation History – Admin</title>

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
        <h1>Reservation History</h1>
            <div class="page-subtitle">
                View, filter, and delete any past, present or future reservation.
            </div>
        </div>

        <!-- App navigation -->
        <?php if (!$embedded): ?>
            <?= layout_render_nav($active, $isStaff) ?>
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
        <form class="row g-2 mb-3" method="get" action="<?= h($actionUrl) ?>">
            <?php foreach ($baseQuery as $k => $v): ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
            <?php endforeach; ?>
            <div class="col-md-4">
                <input type="text"
                       name="q"
                       class="form-control"
                       placeholder="Search by user or items..."
                       value="<?= htmlspecialchars($qRaw) ?>">
            </div>
            <div class="col-md-2">
                <input type="date"
                       name="from"
                       class="form-control"
                       value="<?= htmlspecialchars($fromRaw) ?>"
                       placeholder="From date">
            </div>
            <div class="col-md-2">
                <input type="date"
                       name="to"
                       class="form-control"
                       value="<?= htmlspecialchars($toRaw) ?>"
                       placeholder="To date">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary w-100" type="submit">Filter</button>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <?php
                    $clearUrl = $pageBase;
                    if (!empty($baseQuery)) {
                        $clearUrl .= '?' . http_build_query($baseQuery);
                    }
                ?>
                <a href="<?= h($clearUrl) ?>" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
        </form>

        <?php if (empty($reservations)): ?>
            <div class="alert alert-info">
                There are no reservations matching your filters.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User Name</th>
                            <th>Items Reserved</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Status</th>
                            <th style="width: 180px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reservations as $r): ?>
                            <?php
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
                                $itemsText = $itemsLines ? implode('<br>', array_map('h', $itemsLines)) : '';
                                $modelsHtml = '';
                                $status     = strtolower((string)($r['status'] ?? ''));
                                if ($itemsText !== '') {
                                    $modelsHtml = '<details class="items-section">'
                                        . '<summary><strong>Models Reserved:</strong></summary>'
                                        . '<div class="mt-1">' . $itemsText . '</div>'
                                        . '</details>';
                                }
                                $assetsHtml = '';
                                if (!empty($r['asset_name_cache'])) {
                                    $assetRaw = (string)$r['asset_name_cache'];
                                    $assetParts = array_values(array_filter(array_map('trim', explode(',', $assetRaw)), 'strlen'));
                                    $assetLines = $assetParts ? implode('<br>', array_map('h', $assetParts)) : h($assetRaw);
                                    $assetsHtml = '<details class="items-section mt-2">'
                                        . '<summary><strong>Assets Assigned:</strong></summary>'
                                        . '<div class="mt-1">' . $assetLines . '</div>'
                                        . '</details>';
                                }
                                $itemsText = $modelsHtml . $assetsHtml;
                            ?>
                            <tr>
                                <td>#<?= (int)$r['id'] ?></td>
                                <td><?= h($r['user_name'] ?? '(Unknown)') ?></td>
                                <td><?= $itemsText !== '' ? $itemsText : '' ?></td>
                                <td><?= uk_datetime($r['start_datetime'] ?? '') ?></td>
                                <td><?= uk_datetime($r['end_datetime'] ?? '') ?></td>
                                <td><?= h($r['status'] ?? '') ?></td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a href="reservation_detail.php?id=<?= (int)$r['id'] ?>"
                                           class="btn btn-sm btn-outline-secondary btn-action">
                                            View
                                        </a>
                                        <?php if ($status === 'pending'): ?>
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
                                                <?php foreach ($baseQuery as $k => $v): ?>
                                                    <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                                                <?php endforeach; ?>
                                                <button class="btn btn-sm btn-outline-success btn-action" type="submit">
                                                    Restore
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post"
                                              action="delete_reservation.php"
                                              onsubmit="return confirm('Delete this reservation and all its items? This cannot be undone.');">
                                            <input type="hidden"
                                                   name="reservation_id"
                                                   value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger btn-action" type="submit">
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

<?php if (!$embedded): ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
<?php endif; ?>
