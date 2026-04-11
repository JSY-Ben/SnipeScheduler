<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/booking_helpers.php';
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

// Basket: typed catalogue item key => quantity
$basket = booking_session_basket_items($_SESSION['basket'] ?? []);

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
$catalogueBackTab = strtolower(trim((string)($_SESSION['catalogue_active_tab'] ?? 'models')));
$catalogueBackTab = in_array($catalogueBackTab, ['models', 'accessories', 'kits'], true) ? $catalogueBackTab : 'models';
if ($previewStartRaw !== '' && $previewEndRaw !== '') {
    $catalogueBackUrl .= '?' . http_build_query([
        'tab' => $catalogueBackTab,
        'start_datetime' => $previewStartRaw,
        'end_datetime'   => $previewEndRaw,
        'prefetch'       => 1,
    ]);
} elseif ($catalogueBackTab !== 'models') {
    $catalogueBackUrl .= '?' . http_build_query([
        'tab' => $catalogueBackTab,
        'prefetch' => 1,
    ]);
}

$items    = [];
$errorMsg = '';

$totalItems      = 0;
$distinctItems   = 0;

// Availability per item for preview: type:id => ['total' => X, 'booked' => Y, 'free' => Z]
$availability = [];

if (!empty($basket)) {
    try {
        foreach ($basket as $basketItem) {
            $itemType = booking_normalize_item_type((string)($basketItem['type'] ?? 'model'));
            $itemId = (int)($basketItem['id'] ?? 0);
            $qty = (int)($basketItem['qty'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }

            $requestableCount = null;
            try {
                $requestableCount = booking_get_requestable_total_for_item($itemType, $itemId);
            } catch (Throwable $e) {
                $requestableCount = null;
            }

            $items[] = [
                'key'               => booking_catalogue_item_key($itemType, $itemId),
                'type'              => $itemType,
                'id'                => $itemId,
                'data'              => booking_fetch_catalogue_item_record($itemType, $itemId),
                'qty'               => $qty,
                'requestable_count' => $requestableCount,
            ];
            $totalItems     += $qty;
            $distinctItems += 1;
        }

        // If we have valid preview dates, compute availability per item for that window
        if ($previewStart && $previewEnd) {
            foreach ($items as $entry) {
                $itemType = booking_normalize_item_type((string)($entry['type'] ?? 'model'));
                $itemId = (int)($entry['id'] ?? 0);
                $requestableTotal = $entry['requestable_count'] ?? null;
                $pendingQty = booking_count_reserved_item_quantity(
                    $pdo,
                    $itemType,
                    $itemId,
                    $previewStart,
                    $previewEnd,
                    booking_blocking_reservation_statuses()
                );
                $activeCheckedOut = booking_count_effective_checked_out_for_item(
                    $itemType,
                    $itemId,
                    $config,
                    (int)$previewStartTs
                );
                $booked = $pendingQty + $activeCheckedOut;

                if ($requestableTotal === null) {
                    try {
                        $requestableTotal = booking_get_requestable_total_for_item($itemType, $itemId);
                    } catch (Throwable $e) {
                        $requestableTotal = 0;
                    }
                }

                if ($requestableTotal > 0) {
                    $free = max(0, $requestableTotal - $booked);
                } else {
                    $free = null; // unknown
                }

                $availability[booking_catalogue_item_key($itemType, $itemId)] = [
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
                Review reserved items and quantities, check date-specific availability, and confirm your booking.
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
                Your basket is empty. Add items from the <a href="catalogue.php">catalogue</a>.
            </div>
        <?php else: ?>
            <div class="mb-3">
                <span class="badge-summary">
                    <?= $distinctItems ?> line item(s), <?= $totalItems ?> item(s) total
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
                            <th>Item</th>
                            <th>Type</th>
                            <th>Manufacturer</th>
                            <th>Category</th>
                            <th>Requested qty</th>
                            <th>Availability (for chosen dates)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($items as $entry): ?>
                        <?php
                            $record = is_array($entry['data']) ? $entry['data'] : [];
                            $itemType = booking_normalize_item_type((string)($entry['type'] ?? 'model'));
                            $itemId = (int)($entry['id'] ?? 0);
                            $qty = (int)($entry['qty'] ?? 0);
                            $itemKey = (string)($entry['key'] ?? booking_catalogue_item_key($itemType, $itemId));

                            $availText = 'Not calculated yet';
                            $warnClass = '';

                            if ($previewStart && $previewEnd && isset($availability[$itemKey])) {
                                $a = $availability[$itemKey];
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

                            $manufacturerName = '';
                            $categoryName = '';
                            if ($itemType === 'model') {
                                $manufacturerName = (string)($record['manufacturer']['name'] ?? '');
                                $categoryName = (string)($record['category']['name'] ?? '');
                            } else {
                                $manufacturerName = is_array($record['manufacturer'] ?? null)
                                    ? (string)($record['manufacturer']['name'] ?? '')
                                    : (string)($record['manufacturer_name'] ?? '');
                                $categoryName = is_array($record['category'] ?? null)
                                    ? (string)($record['category']['name'] ?? '')
                                    : (string)($record['category_name'] ?? '');
                            }
                        ?>
                        <tr>
                            <td><?= h($record['name'] ?? 'Item') ?></td>
                            <td><?= h(ucfirst($itemType)) ?></td>
                            <td><?= h($manufacturerName) ?></td>
                            <td><?= h($categoryName) ?></td>
                            <td>
                                <form method="post"
                                      action="basket_quantity.php"
                                      class="basket-qty-form"
                                      aria-label="Adjust quantity for <?= h($record['name'] ?? 'item') ?>">
                                    <input type="hidden" name="item_type" value="<?= h($itemType) ?>">
                                    <input type="hidden" name="item_id" value="<?= $itemId ?>">
                                    <button type="submit"
                                            name="direction"
                                            value="down"
                                            class="btn btn-sm btn-outline-secondary basket-qty-btn"
                                            aria-label="Decrease requested quantity"
                                            <?= $qty <= 1 ? 'disabled' : '' ?>>
                                        -
                                    </button>
                                    <span class="basket-qty-value" aria-live="polite"><?= $qty ?></span>
                                    <button type="submit"
                                            name="direction"
                                            value="up"
                                            class="btn btn-sm btn-outline-secondary basket-qty-btn"
                                            aria-label="Increase requested quantity">
                                        +
                                    </button>
                                </form>
                            </td>
                            <td class="<?= $warnClass ?>"><?= htmlspecialchars($availText) ?></td>
                            <td>
                                <a href="basket_remove.php?item_type=<?= rawurlencode($itemType) ?>&item_id=<?= $itemId ?>"
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
    const windowScrollRestoreKey = 'snipeScheduler:basketWindowScrollY';
    let windowSubmitInFlight = false;
    let lastSubmittedWindow = (startInput && endInput)
        ? (startInput.value.trim() + '|' + endInput.value.trim())
        : '';
    let nativeWindowDirty = false;
    let nativeWindowBlurTimer = null;

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

    function saveWindowScrollPosition() {
        try {
            const y = Math.max(0, Math.round(window.scrollY || window.pageYOffset || 0));
            window.sessionStorage.setItem(windowScrollRestoreKey, String(y));
        } catch (e) {
            // Ignore storage errors (private mode / blocked storage).
        }
    }

    function restoreWindowScrollPosition() {
        try {
            const raw = window.sessionStorage.getItem(windowScrollRestoreKey);
            if (raw === null) return;
            window.sessionStorage.removeItem(windowScrollRestoreKey);
            const target = parseInt(raw, 10);
            if (!Number.isFinite(target)) return;

            const scrollToSavedY = function () {
                const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
                const maxY = Math.max(0, document.documentElement.scrollHeight - Math.max(1, viewportHeight));
                const nextY = Math.max(0, Math.min(maxY, target));
                window.scrollTo(0, nextY);
            };

            window.requestAnimationFrame(function () {
                scrollToSavedY();
                window.setTimeout(scrollToSavedY, 120);
            });
        } catch (e) {
            // Ignore storage errors (private mode / blocked storage).
        }
    }

    restoreWindowScrollPosition();

    function centerCalendarInstance(instance) {
        if (!instance || !instance.calendarContainer) return;

        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        if (viewportHeight <= 0) return;

        const calendar = instance.calendarContainer;
        const topPadding = 16;
        const bottomPadding = 16;
        const availableHeight = Math.max(1, viewportHeight - topPadding - bottomPadding);

        const rect = calendar.getBoundingClientRect();
        if (rect.width <= 0 || rect.height <= 0) return;

        let targetTop = topPadding;
        if (rect.height < availableHeight) {
            targetTop = topPadding + ((availableHeight - rect.height) / 2);
        }

        const desiredY = window.scrollY + (rect.top - targetTop);
        const maxScrollY = Math.max(0, document.documentElement.scrollHeight - viewportHeight);
        const nextY = Math.max(0, Math.min(maxScrollY, desiredY));
        if (Math.abs(nextY - window.scrollY) < 2) return;

        const reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({
            top: nextY,
            behavior: reduceMotion ? 'auto' : 'smooth',
        });
    }

    function bindPickerCentering(input) {
        if (!input || !input._flatpickr || input._flatpickr.__basketCenterBound) return;
        input._flatpickr.__basketCenterBound = true;
        input._flatpickr.config.onOpen.push(function (_selectedDates, _dateStr, instance) {
            window.requestAnimationFrame(function () {
                centerCalendarInstance(instance);
                window.setTimeout(function () {
                    centerCalendarInstance(instance);
                }, 90);
            });
        });
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
        saveWindowScrollPosition();
        windowForm.submit();
    }

    function isNativeWindowMode() {
        return !!(startInput && endInput && !startInput._flatpickr && !endInput._flatpickr);
    }

    function maybeSubmitNativeWindowOnBlur() {
        if (!isNativeWindowMode() || !nativeWindowDirty) return;
        if (nativeWindowBlurTimer) {
            clearTimeout(nativeWindowBlurTimer);
        }
        nativeWindowBlurTimer = window.setTimeout(function () {
            const activeElement = document.activeElement;
            if (activeElement === startInput || activeElement === endInput) {
                return;
            }
            nativeWindowDirty = false;
            maybeSubmitWindow();
        }, 120);
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
            saveWindowScrollPosition();
        });
    }

    if (startInput && endInput) {
        startInput.addEventListener('change', function () {
            normalizeWindowEnd();
            if (isNativeWindowMode()) {
                nativeWindowDirty = true;
                maybeSubmitNativeWindowOnBlur();
            }
        });
        endInput.addEventListener('change', function () {
            normalizeWindowEnd();
            if (isNativeWindowMode()) {
                nativeWindowDirty = true;
                maybeSubmitNativeWindowOnBlur();
            }
        });
        startInput.addEventListener('blur', maybeSubmitNativeWindowOnBlur);
        endInput.addEventListener('blur', maybeSubmitNativeWindowOnBlur);
        bindFlatpickrApplySubmit(startInput);
        bindFlatpickrApplySubmit(endInput);
        bindPickerCentering(startInput);
        bindPickerCentering(endInput);
    }

    if (todayBtn) {
        todayBtn.addEventListener('click', setTodayWindow);
    }
});
</script>
