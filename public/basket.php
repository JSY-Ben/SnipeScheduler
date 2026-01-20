<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/inventory_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

// Basket: model_id => quantity
$basket = $_SESSION['basket'] ?? [];

// Preview availability dates (from GET) with sensible defaults
$now = new DateTime();
$defaultStart = $now->format('Y-m-d\TH:i');
$defaultEnd   = (new DateTime('tomorrow 9:00'))->format('Y-m-d\TH:i');

$previewStartRaw = $_GET['start_datetime'] ?? '';
$previewEndRaw   = $_GET['end_datetime'] ?? '';

if (trim($previewStartRaw) === '') {
    $previewStartRaw = $defaultStart;
}

if (trim($previewEndRaw) === '') {
    $previewEndRaw = $defaultEnd;
}

$previewStart = null;
$previewEnd   = null;
$previewError = '';

if ($previewStartRaw && $previewEndRaw) {
    $startTs = strtotime($previewStartRaw);
    $endTs   = strtotime($previewEndRaw);

    if ($startTs === false || $endTs === false) {
        $previewError = 'Invalid date/time for availability preview.';
    } elseif ($endTs <= $startTs) {
        $previewError = 'End time must be after start time for availability preview.';
    } else {
        $previewStart = date('Y-m-d H:i:s', $startTs);
        $previewEnd   = date('Y-m-d H:i:s', $endTs);
    }
}

$models   = [];
$errorMsg = '';

$totalItems      = 0;
$distinctModels  = 0;

// Availability per model for preview: model_id => ['total' => X, 'booked' => Y, 'free' => Z]
$availability = [];

if (!empty($basket)) {
    try {
        // Load model data and tally basic counts
        foreach ($basket as $modelId => $qty) {
            $modelId = (int)$modelId;
            $qty     = (int)$qty;

            // Count assets for limits/availability
            $assetCount = null;
            try {
                $assetCount = count_assets_by_model($modelId);
            } catch (Throwable $e) {
                $assetCount = null;
            }

            $models[] = [
                'id'                => $modelId,
                'data'              => get_model($modelId),
                'qty'               => $qty,
                'asset_count' => $assetCount,
            ];
            $totalItems     += $qty;
            $distinctModels += 1;
        }

        // If we have valid preview dates, compute availability per model for that window
        if ($previewStart && $previewEnd) {
            foreach ($models as $entry) {
                $mid = (int)$entry['id'];
                $assetTotal = $entry['asset_count'] ?? null;

                // How many units already booked in that time range?
                $sql = "
                    SELECT
                        COALESCE(SUM(CASE WHEN r.status IN ('pending','confirmed') THEN ri.quantity END), 0) AS pending_qty,
                        COALESCE(SUM(CASE WHEN r.status = 'completed' THEN ri.quantity END), 0) AS completed_qty
                    FROM reservation_items ri
                    JOIN reservations r ON r.id = ri.reservation_id
                    WHERE ri.model_id = :model_id
                      AND r.status IN ('pending', 'confirmed', 'completed')
                      AND (r.start_datetime < :end AND r.end_datetime > :start)
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':model_id' => $mid,
                    ':start'    => $previewStart,
                    ':end'      => $previewEnd,
                ]);
                $row          = $stmt->fetch();
                $pendingQty   = $row ? (int)$row['pending_qty'] : 0;

                // Checked-out assets from local cache
                $activeCheckedOut = count_checked_out_assets_by_model($mid);
                $booked = $pendingQty + $activeCheckedOut;

                // Total units in local inventory
                if ($assetTotal === null) {
                    try {
                        $assetTotal = count_assets_by_model($mid);
                    } catch (Throwable $e) {
                        $assetTotal = 0;
                    }
                }

                if ($assetTotal > 0) {
                    $free = max(0, $assetTotal - $booked);
                } else {
                    $free = null; // unknown
                }

                $availability[$mid] = [
                    'total'  => $assetTotal,
                    'booked' => $booked,
                    'free'   => $free,
                ];
            }
        }

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Basket – Book Equipment</title>
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
            <h1>Your basket</h1>
            <div class="page-subtitle">
                Review models and quantities, check date-specific availability, and confirm your booking.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="catalogue.php" class="btn btn-outline-primary">
                    Back to catalogue
                </a>
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if ($errorMsg): ?>
            <div class="alert alert-danger">
                Error loading inventory data: <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($basket)): ?>
            <div class="alert alert-info">
                Your basket is empty. Add models from the <a href="catalogue.php">catalogue</a>.
            </div>
        <?php else: ?>
            <div class="mb-3">
                <span class="badge-summary">
                    <?= $distinctModels ?> model(s), <?= $totalItems ?> item(s) total
                </span>
            </div>

            <?php if ($previewError): ?>
                <div class="alert alert-warning">
                    <?= htmlspecialchars($previewError) ?>
                </div>
            <?php elseif ($previewStart && $previewEnd): ?>
                <div class="alert alert-info">
                    Showing availability for:
                    <strong>
                        <?= htmlspecialchars(date('d/m/Y H:i', strtotime($previewStart))) ?>
                        &ndash;
                        <?= htmlspecialchars(date('d/m/Y H:i', strtotime($previewEnd))) ?>
                    </strong>
                </div>
            <?php else: ?>
                <div class="alert alert-secondary">
                    Choose a start and end date below and click
                    <strong>Check availability</strong> to see how many units are free for your dates.
                </div>
            <?php endif; ?>

            <div class="table-responsive mb-4">
                <table class="table table-striped table-bookings align-middle">
                    <thead>
                        <tr>
                            <th>Model</th>
                            <th>Manufacturer</th>
                            <th>Category</th>
                            <th>Requested qty</th>
                            <th>Availability (for chosen dates)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($models as $entry): ?>
                        <?php
                            $model = $entry['data'];
                            $mid   = (int)$entry['id'];
                            $qty   = (int)$entry['qty'];

                            $availText = 'Not calculated yet';
                            $warnClass = '';

                            if ($previewStart && $previewEnd && isset($availability[$mid])) {
                                $a = $availability[$mid];
                                if ($a['total'] > 0 && $a['free'] !== null) {
                                    $availText = $a['free'] . ' of ' . $a['total'] . ' units free';
                                    if ($qty > $a['free']) {
                                        $warnClass = 'text-danger fw-semibold';
                                        $availText .= ' – not enough for requested quantity';
                                    }
                                } elseif ($a['total'] > 0) {
                                    $availText = $a['total'] . ' units total (unable to compute free units)';
                                } else {
                                    $availText = 'Availability unknown (no total count available)';
                                }
                            }
                        ?>
                        <tr>
                            <td><?= h($model['name'] ?? 'Model') ?></td>
                            <td><?= h($model['manufacturer']['name'] ?? '') ?></td>
                            <td><?= h($model['category']['name'] ?? '') ?></td>
                            <td><?= $qty ?></td>
                            <td class="<?= $warnClass ?>"><?= htmlspecialchars($availText) ?></td>
                            <td>
                                <a href="basket_remove.php?model_id=<?= (int)$model['id'] ?>"
                                   class="btn btn-sm btn-outline-danger">
                                    Remove
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Form to preview availability for chosen dates -->
            <div class="availability-box mb-4">
                <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
                    <div class="availability-pill">Select reservation window</div>
                    <div class="text-muted small">Start defaults to now, end to tomorrow at 09:00</div>
                </div>
                <form method="get" action="basket.php">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Start date &amp; time</label>
                            <input type="datetime-local" name="start_datetime"
                                   class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($previewStartRaw) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">End date &amp; time</label>
                            <input type="datetime-local" name="end_datetime"
                                   class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($previewEndRaw) ?>">
                        </div>
                        <div class="col-md-4 d-grid">
                            <button class="btn btn-outline-primary mt-3 mt-md-0" type="submit">
                                Check availability
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Final checkout form (uses the same dates, if provided) -->
            <form method="post" action="basket_checkout.php">
                <input type="hidden" name="start_datetime"
                       value="<?= htmlspecialchars($previewStartRaw) ?>">
                <input type="hidden" name="end_datetime"
                       value="<?= htmlspecialchars($previewEndRaw) ?>">

                <p class="mb-2 text-muted">
                    When you click <strong>Confirm booking</strong>, the system will re-check availability
                    and reject the booking if another user has taken items in the meantime.
                </p>

                <button class="btn btn-primary btn-lg px-4"
                        type="submit"
                        <?= (!$previewStart || !$previewEnd) ? 'disabled' : '' ?>>
                    Confirm booking for all items
                </button>
                <?php if (!$previewStart || !$previewEnd): ?>
                    <span class="ms-2 text-danger small">
                        Please check availability first.
                    </span>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
