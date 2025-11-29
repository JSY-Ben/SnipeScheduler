<?php
require 'auth.php';
require 'snipeit_client.php';
require 'db.php';

// Basket: model_id => quantity
$basket = $_SESSION['basket'] ?? [];

// Preview availability dates (from GET)
$previewStartRaw = $_GET['start_datetime'] ?? '';
$previewEndRaw   = $_GET['end_datetime'] ?? '';

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

            $models[] = [
                'id'    => $modelId,
                'data'  => get_model($modelId),
                'qty'   => $qty,
            ];
            $totalItems     += $qty;
            $distinctModels += 1;
        }

        // If we have valid preview dates, compute availability per model for that window
        if ($previewStart && $previewEnd) {
            foreach ($models as $entry) {
                $mid = (int)$entry['id'];

                // How many units already booked in that time range?
                $sql = "
                    SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
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
                $row = $stmt->fetch();
                $booked = $row ? (int)$row['booked_qty'] : 0;

                // Total physical units in Snipe-IT
                $totalHardware = get_model_hardware_count($mid);

                if ($totalHardware > 0) {
                    $free = max(0, $totalHardware - $booked);
                } else {
                    $free = null; // unknown
                }

                $availability[$mid] = [
                    'total'  => $totalHardware,
                    'booked' => $booked,
                    'free'   => $free,
                ];
            }
        }

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}

$active  = basename($_SERVER['PHP_SELF']);
$isStaff = !empty($currentUser['is_admin']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Basket – Book Equipment</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <div class="page-header">
            <h1>Your basket</h1>
            <div class="page-subtitle">
                Review models and quantities, check date-specific availability, and confirm your booking.
            </div>
        </div>

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
            <?php endif; ?>
        </nav>

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
                Error talking to Snipe-IT: <?= htmlspecialchars($errorMsg) ?>
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
                                    $availText = 'Availability unknown (no total count from Snipe-IT)';
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
            <form method="get" action="basket.php" class="mb-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Start date &amp; time</label>
                        <input type="datetime-local" name="start_datetime"
                               class="form-control"
                               value="<?= htmlspecialchars($previewStartRaw) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">End date &amp; time</label>
                        <input type="datetime-local" name="end_datetime"
                               class="form-control"
                               value="<?= htmlspecialchars($previewEndRaw) ?>">
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-outline-primary w-100 mt-3 mt-md-0" type="submit">
                            Check availability
                        </button>
                    </div>
                </div>
            </form>

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

                <button class="btn btn-primary"
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
</body>
</html>
