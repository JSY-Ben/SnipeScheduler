<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/reservation_policy.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$bookingOverride = $_SESSION['booking_user_override'] ?? null;
$bookingTargetUser = $bookingOverride ?: $currentUser;
$overrideEmail = strtolower(trim((string)($bookingOverride['email'] ?? '')));
$currentEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
$isOnBehalfBooking = is_array($bookingOverride) && $overrideEmail !== '' && $overrideEmail !== $currentEmail;
$reservationPolicy = reservation_policy_get($config);

// Basket: model_id => quantity
$basket = $_SESSION['basket'] ?? [];

// Preview availability dates (from GET) with sensible defaults
$windowTz = app_get_timezone($config);
$now = $windowTz ? new DateTime('now', $windowTz) : new DateTime('now');
$defaultStart = $now->format('Y-m-d\TH:i');
$defaultEndDt = clone $now;
$defaultEndDt->modify('+1 day');
$defaultEndDt->setTime(9, 0, 0);
$defaultEnd   = $defaultEndDt->format('Y-m-d\TH:i');

$previewStartRaw = $_GET['start_datetime'] ?? '';
$previewEndRaw   = $_GET['end_datetime'] ?? '';
if ($previewStartRaw === '' && $previewEndRaw === '') {
    $sessionStart = trim((string)($_SESSION['reservation_window_start'] ?? ''));
    $sessionEnd   = trim((string)($_SESSION['reservation_window_end'] ?? ''));
    if ($sessionStart !== '' && $sessionEnd !== '') {
        $previewStartRaw = $sessionStart;
        $previewEndRaw   = $sessionEnd;
    }
}

if (trim($previewStartRaw) === '') {
    $previewStartRaw = $defaultStart;
}

if (trim($previewEndRaw) === '') {
    $previewEndRaw = $defaultEnd;
}

$previewStart = null;
$previewEnd   = null;
$previewError = '';
$previewStartTs = false;
$previewEndTs = false;
$policyViolations = [];

if ($previewStartRaw && $previewEndRaw) {
    $previewStartTs = strtotime($previewStartRaw);
    $previewEndTs   = strtotime($previewEndRaw);

    if ($previewStartTs === false || $previewEndTs === false) {
        $previewError = 'Invalid date/time for availability preview.';
    } elseif ($previewEndTs <= $previewStartTs) {
        $previewError = 'End time must be after start time for availability preview.';
    } else {
        $previewStart = date('Y-m-d H:i:s', $previewStartTs);
        $previewEnd   = date('Y-m-d H:i:s', $previewEndTs);
        $_SESSION['reservation_window_start'] = $previewStartRaw;
        $_SESSION['reservation_window_end']   = $previewEndRaw;
    }
}

if ($previewStart && $previewEnd) {
    $policyViolations = reservation_policy_validate_booking($pdo, $reservationPolicy, [
        'start_ts' => (int)$previewStartTs,
        'end_ts' => (int)$previewEndTs,
        'target_user_id' => (string)($bookingTargetUser['id'] ?? ''),
        'target_user_email' => (string)($bookingTargetUser['email'] ?? ''),
        'is_admin' => $isAdmin,
        'is_staff' => $isStaff,
        'is_on_behalf' => $isOnBehalfBooking,
    ]);
}

$catalogueBackUrl = 'catalogue.php';
if ($previewStartRaw !== '' && $previewEndRaw !== '') {
    $catalogueBackUrl .= '?' . http_build_query([
        'start_datetime' => $previewStartRaw,
        'end_datetime'   => $previewEndRaw,
        'prefetch'       => 1,
    ]);
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

            // Count requestable assets for limits/availability
            $requestableCount = null;
            try {
                $requestableCount = count_requestable_assets_by_model($modelId);
            } catch (Throwable $e) {
                $requestableCount = null;
            }

            $models[] = [
                'id'                => $modelId,
                'data'              => get_model($modelId),
                'qty'               => $qty,
                'requestable_count' => $requestableCount,
            ];
            $totalItems     += $qty;
            $distinctModels += 1;
        }

        // If we have valid preview dates, compute availability per model for that window
        if ($previewStart && $previewEnd) {
            foreach ($models as $entry) {
                $mid = (int)$entry['id'];
                $requestableTotal = $entry['requestable_count'] ?? null;

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

                // Total requestable units in Snipe-IT
                if ($requestableTotal === null) {
                    try {
                        $requestableTotal = count_requestable_assets_by_model($mid);
                    } catch (Throwable $e) {
                        $requestableTotal = 0;
                    }
                }

                if ($requestableTotal > 0) {
                    $free = max(0, $requestableTotal - $booked);
                } else {
                    $free = null; // unknown
                }

                $availability[$mid] = [
                    'total'  => $requestableTotal,
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
<body class="p-4"
      data-date-format="<?= h(app_get_date_format()) ?>"
      data-time-format="<?= h(app_get_time_format()) ?>">
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
                <a href="<?= h($catalogueBackUrl) ?>" class="btn btn-outline-primary">
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
                        <?= h(app_format_datetime($previewStart)) ?>
                        &ndash;
                        <?= h(app_format_datetime($previewEnd)) ?>
                    </strong>
                </div>
            <?php else: ?>
                <div class="alert alert-secondary">
                    Choose a start and end date below to automatically refresh availability.
                </div>
            <?php endif; ?>
            <?php if (!empty($policyViolations)): ?>
                <div class="alert alert-danger">
                    <div class="fw-semibold mb-2">Reservation window not allowed:</div>
                    <ul class="mb-0">
                        <?php foreach ($policyViolations as $violation): ?>
                            <li><?= h($violation) ?></li>
                        <?php endforeach; ?>
                    </ul>
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
            <div class="availability-box mb-4">
                <div class="d-flex align-items-center mb-3 flex-wrap gap-2">
                    <div class="availability-pill">Select reservation window</div>
                    <div class="text-muted small">Start defaults to now, end to tomorrow at 09:00</div>
                </div>
                <form method="get" action="basket.php" id="basket-window-form">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Start date &amp; time</label>
                            <input type="datetime-local" name="start_datetime"
                                   id="basket_start_datetime"
                                   class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($previewStartRaw) ?>">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">End date &amp; time</label>
                            <input type="datetime-local" name="end_datetime"
                                   id="basket_end_datetime"
                                   class="form-control form-control-lg"
                                   value="<?= htmlspecialchars($previewEndRaw) ?>">
                        </div>
                        <div class="col-md-2 d-grid align-items-end">
                            <button class="btn btn-primary btn-lg w-100 flex-md-fill mt-3 mt-md-0 reservation-window-btn"
                                    type="button"
                                    id="basket-today-btn">
                                Today
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
                        <?= (!$previewStart || !$previewEnd || !empty($policyViolations)) ? 'disabled' : '' ?>>
                    Confirm booking for all items
                </button>
                <?php if (!$previewStart || !$previewEnd): ?>
                    <span class="ms-2 text-danger small">
                        Please choose a valid reservation window.
                    </span>
                <?php elseif (!empty($policyViolations)): ?>
                    <span class="ms-2 text-danger small">
                        Please resolve the reservation rule violations above.
                    </span>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const windowForm = document.getElementById('basket-window-form');
    const startInput = document.getElementById('basket_start_datetime');
    const endInput = document.getElementById('basket_end_datetime');
    const todayBtn = document.getElementById('basket-today-btn');
    let windowSubmitInFlight = false;
    let lastSubmittedWindow = (startInput && endInput)
        ? (startInput.value.trim() + '|' + endInput.value.trim())
        : '';

    function toLocalDatetimeValue(date) {
        const pad = function (n) { return String(n).padStart(2, '0'); };
        return date.getFullYear()
            + '-' + pad(date.getMonth() + 1)
            + '-' + pad(date.getDate())
            + 'T' + pad(date.getHours())
            + ':' + pad(date.getMinutes());
    }

    function setDatetimeInputValue(input, value) {
        if (!input) return;
        if (input._flatpickr) {
            input._flatpickr.setDate(value, true, input._flatpickr.config.dateFormat);
            return;
        }
        input.value = value;
    }

    function normalizeWindowEnd() {
        if (!startInput || !endInput) return;
        const startVal = startInput.value.trim();
        const endVal = endInput.value.trim();
        if (startVal === '' || endVal === '') return;
        const startMs = Date.parse(startVal);
        const endMs = Date.parse(endVal);
        if (Number.isNaN(startMs) || Number.isNaN(endMs)) return;
        if (endMs <= startMs) {
            const startDate = new Date(startMs);
            const nextDay = new Date(startDate);
            nextDay.setDate(startDate.getDate() + 1);
            nextDay.setHours(9, 0, 0, 0);
            setDatetimeInputValue(endInput, toLocalDatetimeValue(nextDay));
        }
    }

    function maybeSubmitWindow() {
        if (windowSubmitInFlight || !windowForm || !startInput || !endInput) return;
        const startVal = startInput.value.trim();
        const endVal = endInput.value.trim();
        if (startVal === '' || endVal === '') return;
        const startMs = Date.parse(startVal);
        const endMs = Date.parse(endVal);
        if (Number.isNaN(startMs) || Number.isNaN(endMs) || endMs <= startMs) return;
        const windowKey = startVal + '|' + endVal;
        if (windowKey === lastSubmittedWindow) return;
        lastSubmittedWindow = windowKey;
        windowSubmitInFlight = true;
        windowForm.submit();
    }

    function setTodayWindow() {
        if (!startInput || !endInput) return;
        const now = new Date();
        const tomorrow = new Date(now);
        tomorrow.setDate(now.getDate() + 1);
        tomorrow.setHours(9, 0, 0, 0);
        setDatetimeInputValue(startInput, toLocalDatetimeValue(now));
        setDatetimeInputValue(endInput, toLocalDatetimeValue(tomorrow));
        maybeSubmitWindow();
    }

    function bindFlatpickrApplySubmit(input) {
        if (!input || !input._flatpickr || !input._flatpickr.calendarContainer) return;
        const confirmButton = input._flatpickr.calendarContainer.querySelector('.flatpickr-confirm');
        if (!confirmButton || confirmButton.dataset.windowApplyBound === '1') return;
        confirmButton.dataset.windowApplyBound = '1';
        confirmButton.addEventListener('click', function () {
            normalizeWindowEnd();
            maybeSubmitWindow();
        });
    }

    if (windowForm) {
        windowForm.addEventListener('submit', function () {
            if (startInput && endInput) {
                lastSubmittedWindow = startInput.value.trim() + '|' + endInput.value.trim();
            }
            windowSubmitInFlight = true;
        });
    }

    if (startInput && endInput) {
        startInput.addEventListener('change', function () {
            normalizeWindowEnd();
        });
        endInput.addEventListener('change', function () {
            normalizeWindowEnd();
        });
        bindFlatpickrApplySubmit(startInput);
        bindFlatpickrApplySubmit(endInput);
    }

    if (todayBtn) {
        todayBtn.addEventListener('click', setTodayWindow);
    }
});
</script>
