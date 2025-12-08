<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/footer.php';

$active    = basename($_SERVER['PHP_SELF']);
$isStaff   = !empty($currentUser['is_admin']);
$embedded  = defined('RESERVATIONS_EMBED');
$pageBase  = $embedded ? 'reservations.php' : 'staff_reservations.php';
$baseQuery = $embedded ? ['tab' => 'history'] : [];

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

// Filters
$qRaw    = trim($_GET['q'] ?? '');
$fromRaw = trim($_GET['from'] ?? '');
$toRaw   = trim($_GET['to'] ?? '');

$q        = $qRaw !== '' ? $qRaw : null;
$dateFrom = $fromRaw !== '' ? $fromRaw : null;
$dateTo   = $toRaw !== '' ? $toRaw : null;

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
    <?= reserveit_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= reserveit_logo_tag() ?>
<?php endif; ?>
        <div class="page-header">
        <h1>Reservation History</h1>
            <div class="page-subtitle">
                View, filter, and delete any past, present or future reservation.
            </div>
        </div>

        <!-- App navigation -->
        <?php if (!$embedded): ?>
            <?= reserveit_render_nav($active, $isStaff) ?>
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
                                $itemsText  = build_items_summary_text($items);
                                if (!empty($r['asset_name_cache'])) {
                                    $extra = 'Assets: ' . $r['asset_name_cache'];
                                    $itemsText = $itemsText ? $itemsText . ' | ' . $extra : $extra;
                                }
                            ?>
                            <tr>
                                <td>#<?= (int)$r['id'] ?></td>
                                <td><?= h($r['user_name'] ?? '(Unknown)') ?></td>
                                <td><?= h($itemsText) ?></td>
                                <td><?= uk_datetime($r['start_datetime'] ?? '') ?></td>
                                <td><?= uk_datetime($r['end_datetime'] ?? '') ?></td>
                                <td><?= h($r['status'] ?? '') ?></td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <a href="reservation_detail.php?id=<?= (int)$r['id'] ?>"
                                           class="btn btn-sm btn-outline-secondary">
                                            View
                                        </a>
                                        <form method="post"
                                              action="delete_reservation.php"
                                              onsubmit="return confirm('Delete this reservation and all its items? This cannot be undone.');">
                                            <input type="hidden"
                                                   name="reservation_id"
                                                   value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">
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
<?php reserveit_footer(); ?>
</body>
</html>
<?php endif; ?>
