<?php
// staff_checkout.php
//
// Staff-only page that:
// 1) Shows today's bookings from the booking app.
// 2) Provides a bulk checkout panel that uses the Snipe-IT API to
//    check out scanned asset tags to a Snipe-IT user.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/booking_helpers.php';
require_once __DIR__ . '/snipeit_client.php';

$config   = require __DIR__ . '/config.php';
$timezone = $config['app']['timezone'] ?? 'Europe/Jersey';
$active   = basename($_SERVER['PHP_SELF']);
$isStaff  = !empty($currentUser['is_admin']);
$tz       = new DateTimeZone($timezone);
$now      = new DateTime('now', $tz);
$todayStr = $now->format('Y-m-d');

// Only staff/admin allowed
if (empty($currentUser['is_admin'])) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// ---------------------------------------------------------------------
// AJAX: user search for autocomplete
// ---------------------------------------------------------------------
if (($_GET['ajax'] ?? '') === 'user_search') {
    header('Content-Type: application/json');

    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        $data = snipeit_request('GET', 'users', [
            'search' => $q,
            'limit'  => 10,
        ]);

        $rows = $data['rows'] ?? [];
        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id'       => $row['id'] ?? null,
                'name'     => $row['name'] ?? '',
                'email'    => $row['email'] ?? '',
                'username' => $row['username'] ?? '',
            ];
        }

        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ---------------------------------------------------------------------
// Helper: UK date/time display from Y-m-d H:i:s
// ---------------------------------------------------------------------
function uk_datetime_display(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $iso);
    if (!$dt) {
        return $iso;
    }
    return $dt->format('d/m/Y H:i');
}

/**
 * Check if a model is booked in another reservation overlapping the window.
 */
function model_booked_elsewhere(PDO $pdo, int $modelId, string $start, string $end, ?int $excludeReservationId = null): bool
{
    if ($modelId <= 0 || $start === '' || $end === '') {
        return false;
    }

    $sql = "
        SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
        FROM reservation_items ri
        JOIN reservations r ON r.id = ri.reservation_id
        WHERE ri.model_id = :model_id
          AND r.start_datetime < :end
          AND r.end_datetime > :start
          AND r.status IN ('pending', 'confirmed', 'completed')
    ";

    $params = [
        ':model_id' => $modelId,
        ':start'    => $start,
        ':end'      => $end,
    ];

    if ($excludeReservationId) {
        $sql .= " AND r.id <> :exclude_id";
        $params[':exclude_id'] = $excludeReservationId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return ((int)($row['booked_qty'] ?? 0)) > 0;
}

// ---------------------------------------------------------------------
// Load today's bookings from reservations table
// ---------------------------------------------------------------------
$todayBookings = [];
$todayError    = '';

try {
    $sql = "
        SELECT *
        FROM reservations
        WHERE DATE(start_datetime) = :today
        ORDER BY start_datetime ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':today' => $todayStr]);
    $todayBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $todayBookings = [];
    $todayError    = $e->getMessage();
}

// ---------------------------------------------------------------------
// Bulk checkout session basket
// ---------------------------------------------------------------------
if (!isset($_SESSION['bulk_checkout_assets'])) {
    $_SESSION['bulk_checkout_assets'] = [];
}
$checkoutAssets = &$_SESSION['bulk_checkout_assets'];

// Selected reservation for checkout (today only)
$selectedReservationId = isset($_SESSION['selected_reservation_id'])
    ? (int)$_SESSION['selected_reservation_id']
    : null;

// Messages
$checkoutMessages = [];
$checkoutErrors   = [];

// Current counts per model already in checkout list (for quota enforcement)
$currentModelCounts = [];
foreach ($checkoutAssets as $existing) {
    $mid = isset($existing['model_id']) ? (int)$existing['model_id'] : 0;
    if ($mid > 0) {
        $currentModelCounts[$mid] = ($currentModelCounts[$mid] ?? 0) + 1;
    }
}

// Handle reservation selection (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'select_reservation') {
    $selectedReservationId = (int)($_POST['reservation_id'] ?? 0);
    if ($selectedReservationId > 0) {
        $_SESSION['selected_reservation_id'] = $selectedReservationId;
    } else {
        unset($_SESSION['selected_reservation_id']);
        $selectedReservationId = null;
    }
    // Reset checkout basket when changing reservation
    $checkoutAssets = [];
    header('Location: staff_checkout.php');
    exit;
}

// Remove single asset from checkout list via GET ?remove=ID
if (isset($_GET['remove'])) {
    $removeId = (int)$_GET['remove'];
    if ($removeId > 0 && isset($checkoutAssets[$removeId])) {
        unset($checkoutAssets[$removeId]);
    }
    header('Location: staff_checkout.php');
    exit;
}

// ---------------------------------------------------------------------
// Selected reservation details (today only)
// ---------------------------------------------------------------------
$selectedReservation = null;
$selectedItems       = [];
$modelLimits         = [];
$selectedStart       = '';
$selectedEnd         = '';

if ($selectedReservationId) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM reservations
        WHERE id = :id
          AND DATE(start_datetime) = :today
    ");
    $stmt->execute([
        ':id'    => $selectedReservationId,
        ':today' => $todayStr,
    ]);
    $selectedReservation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($selectedReservation) {
        $selectedStart = $selectedReservation['start_datetime'] ?? '';
        $selectedEnd   = $selectedReservation['end_datetime'] ?? '';
        $selectedItems = get_reservation_items_with_names($pdo, $selectedReservationId);
        foreach ($selectedItems as $item) {
            $mid          = (int)($item['model_id'] ?? 0);
            $qty          = (int)($item['qty'] ?? 0);
            if ($mid > 0 && $qty > 0) {
                $modelLimits[$mid] = $qty;
            }
        }
    } else {
        unset($_SESSION['selected_reservation_id']);
        $selectedReservationId = null;
    }
}

// ---------------------------------------------------------------------
// Handle POST actions: add_asset or checkout
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';

    if ($mode === 'add_asset') {
        $tag = trim($_POST['asset_tag'] ?? '');
        if (!$selectedReservation) {
            $checkoutErrors[] = 'Please select a reservation for today before adding assets.';
        } elseif ($tag === '') {
            $checkoutErrors[] = 'Please scan or enter an asset tag.';
        } else {
            try {
                $asset = find_asset_by_tag($tag);

                $assetId   = (int)($asset['id'] ?? 0);
                $assetTag  = $asset['asset_tag'] ?? '';
                $assetName = $asset['name'] ?? '';
                $modelName = $asset['model']['name'] ?? '';
                $modelId   = (int)($asset['model']['id'] ?? 0);
                $status    = $asset['status_label'] ?? '';

                // Normalise status label to a string (API may return array/object)
                if (is_array($status)) {
                    $status = $status['name'] ?? $status['status_meta'] ?? $status['label'] ?? '';
                }

                if ($assetId <= 0 || $assetTag === '') {
                    throw new Exception('Asset record from Snipe-IT is missing id/asset_tag.');
                }
                if ($modelId <= 0) {
                    throw new Exception('Asset record from Snipe-IT is missing model information.');
                }

                // Enforce that the asset's model is in the selected reservation and within quantity.
                $allowedQty   = $modelLimits[$modelId] ?? 0;
                $alreadyAdded = $currentModelCounts[$modelId] ?? 0;

                if ($allowedQty > 0 && $alreadyAdded >= $allowedQty) {
                    throw new Exception("Reservation allows {$allowedQty} of this model; you already added {$alreadyAdded}.");
                }

                if ($allowedQty === 0 && $selectedStart && $selectedEnd) {
                    // Not part of reservation: only allow if model isn't booked elsewhere for this window
                    $bookedElsewhere = model_booked_elsewhere($pdo, $modelId, $selectedStart, $selectedEnd, $selectedReservationId);
                    if ($bookedElsewhere) {
                        throw new Exception('This model is booked in another reservation for this time window.');
                    }
                }

                // Avoid duplicates: overwrite existing entry for same asset id
                $checkoutAssets[$assetId] = [
                    'id'         => $assetId,
                    'asset_tag'  => $assetTag,
                    'name'       => $assetName,
                    'model'      => $modelName,
                    'model_id'   => $modelId,
                    'status'     => $status,
                ];
                $currentModelCounts[$modelId] = ($currentModelCounts[$modelId] ?? 0) + 1;

                $checkoutMessages[] = "Added asset {$assetTag} ({$assetName}) to checkout list.";
            } catch (Throwable $e) {
                $checkoutErrors[] = 'Could not add asset: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'checkout') {
        $checkoutTo = trim($_POST['checkout_to'] ?? '');
        $note       = trim($_POST['note'] ?? '');

        if (!$selectedReservation) {
            $checkoutErrors[] = 'Please select a reservation for today before checking out.';
        } elseif ($checkoutTo === '') {
            $checkoutErrors[] = 'Please enter the Snipe-IT user (email or name) to check out to.';
        } elseif (empty($checkoutAssets)) {
            $checkoutErrors[] = 'There are no assets in the checkout list.';
        } else {
            try {
                // Find a single Snipe-IT user by email or name
                $user = find_single_user_by_email_or_name($checkoutTo);
                $userId   = (int)($user['id'] ?? 0);
                $userName = $user['name'] ?? ($user['username'] ?? $checkoutTo);

                if ($userId <= 0) {
                    throw new Exception('Matched user has no valid ID.');
                }

                // Attempt to check out each asset
                foreach ($checkoutAssets as $asset) {
                    $assetId  = (int)$asset['id'];
                    $assetTag = $asset['asset_tag'] ?? '';
                    $modelId  = isset($asset['model_id']) ? (int)$asset['model_id'] : 0;

                    // Re-check quotas before checkout
                    if ($modelId > 0 && isset($modelLimits[$modelId])) {
                        $allowed = $modelLimits[$modelId];
                        $countForModel = 0;
                        foreach ($checkoutAssets as $a2) {
                            if ((int)($a2['model_id'] ?? 0) === $modelId) {
                                $countForModel++;
                            }
                        }
                        if ($countForModel > $allowed) {
                            throw new Exception("Too many assets of model {$asset['model']} for this reservation (allowed {$allowed}).");
                        }
                    } elseif ($modelId > 0 && $selectedStart && $selectedEnd) {
                        if (model_booked_elsewhere($pdo, $modelId, $selectedStart, $selectedEnd, $selectedReservationId)) {
                            throw new Exception("Model {$asset['model']} is booked in another reservation for this window.");
                        }
                    }

                    try {
                        checkout_asset_to_user($assetId, $userId, $note);
                        $checkoutMessages[] = "Checked out asset {$assetTag} to {$userName}.";
                    } catch (Throwable $e) {
                        $checkoutErrors[] = "Failed to check out {$assetTag}: " . $e->getMessage();
                    }
                }

                // If no errors, clear the list
                if (empty($checkoutErrors)) {
                    $checkoutAssets = [];
                }
            } catch (Throwable $e) {
                $checkoutErrors[] = 'Could not find user in Snipe-IT: ' . $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------------------
// View data
// ---------------------------------------------------------------------
$active  = basename($_SERVER['PHP_SELF']);
$isStaff = !empty($currentUser['is_admin']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Staff checkout – Book Equipment</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <div class="page-header">
            <h1>Staff checkout</h1>
            <div class="page-subtitle">
                View today’s bookings and perform bulk checkouts via Snipe-IT.
            </div>
        </div>

        <!-- App navigation -->
        <nav class="app-nav">
            <a href="index.php"
               class="app-nav-link <?= $active === 'index.php' ? 'active' : '' ?>">Dashboard</a>
            <a href="catalogue.php"
               class="app-nav-link <?= $active === 'catalogue.php' ? 'active' : '' ?>">Catalogue</a>
            <a href="my_bookings.php"
               class="app-nav-link <?= $active === 'my_bookings.php' ? 'active' : '' ?>">My bookings</a>
            <a href="staff_reservations.php"
               class="app-nav-link <?= $active === 'staff_reservations.php' ? 'active' : '' ?>">Admin</a>
            <a href="staff_checkout.php"
               class="app-nav-link <?= $active === 'staff_checkout.php' ? 'active' : '' ?>">Checkout</a>
        </nav>

        <!-- Top bar -->
        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim($currentUser['first_name'] . ' ' . $currentUser['last_name'])) ?></strong>
                (<?= h($currentUser['email']) ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <!-- Reservation selector (today only) -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="post" class="row g-3 align-items-end">
                    <input type="hidden" name="mode" value="select_reservation">
                    <div class="col-md-8">
                        <label class="form-label">Select today’s reservation to check out</label>
                        <select name="reservation_id" class="form-select">
                            <option value="0">-- No reservation selected --</option>
                            <?php foreach ($todayBookings as $res): ?>
                                <?php
                                $resId   = (int)$res['id'];
                                $items   = get_reservation_items_with_names($pdo, $resId);
                                $summary = build_items_summary_text($items);
                                $start   = uk_datetime_display($res['start_datetime'] ?? '');
                                $end     = uk_datetime_display($res['end_datetime'] ?? '');
                                ?>
                                <option value="<?= $resId ?>" <?= $resId === $selectedReservationId ? 'selected' : '' ?>>
                                    #<?= $resId ?> – <?= h($res['student_name'] ?? '') ?> (<?= h($start) ?> → <?= h($end) ?>): <?= h($summary) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Use reservation</button>
                        <button type="submit" name="reservation_id" value="0" class="btn btn-outline-secondary">Clear</button>
                    </div>
                </form>

                <?php if ($selectedReservation): ?>
                    <div class="mt-3 alert alert-info mb-0">
                        <div><strong>Selected:</strong> #<?= (int)$selectedReservation['id'] ?> – <?= h($selectedReservation['student_name'] ?? '') ?></div>
                        <div>When: <?= h(uk_datetime_display($selectedReservation['start_datetime'] ?? '')) ?> → <?= h(uk_datetime_display($selectedReservation['end_datetime'] ?? '')) ?></div>
                        <?php if (!empty($selectedItems)): ?>
                            <div>Models &amp; quantities: <?= h(build_items_summary_text($selectedItems)) ?></div>
                        <?php else: ?>
                            <div>This reservation has no items recorded.</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Feedback messages -->
        <?php if (!empty($checkoutMessages)): ?>
            <div class="alert alert-success">
                <ul class="mb-0">
                    <?php foreach ($checkoutMessages as $m): ?>
                        <li><?= h($m) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($checkoutErrors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($checkoutErrors as $e): ?>
                        <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Today’s bookings -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title">Today’s bookings (reference)</h5>
                <p class="card-text">
                    These are bookings from the app that start today. Use this as a guide when
                    deciding what to hand out.
                </p>

                <?php if ($todayError): ?>
                    <div class="alert alert-danger">
                        Could not load today’s bookings: <?= h($todayError) ?>
                    </div>
                <?php endif; ?>

                <?php if (empty($todayBookings) && !$todayError): ?>
                    <div class="alert alert-info mb-0">
                        There are no bookings starting today.
                    </div>
                <?php elseif (!empty($todayBookings)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Student</th>
                                    <th>Items</th>
                                    <th>Start</th>
                                    <th>End</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($todayBookings as $res): ?>
                                    <?php
                                    $resId = (int)$res['id'];
                                    $items = get_reservation_items_with_names($pdo, $resId);
                                    $summary = build_items_summary_text($items);
                                    ?>
                                    <tr>
                                        <td>#<?= $resId ?></td>
                                        <td><?= h($res['student_name'] ?? '(Unknown)') ?></td>
                                        <td><?= h($summary) ?></td>
                                        <td><?= h(uk_datetime_display($res['start_datetime'] ?? '')) ?></td>
                                        <td><?= h(uk_datetime_display($res['end_datetime'] ?? '')) ?></td>
                                        <td><?= h($res['status'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bulk checkout panel -->
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Bulk checkout (via Snipe-IT)</h5>
                <p class="card-text">
                    Scan or type asset tags to add them to the checkout list. When ready, enter
                    the Snipe-IT user (email or name) and check out all items in one go.
                </p>

                <!-- Scan/add asset form -->
                <form method="post" class="row g-2 mb-3">
                    <input type="hidden" name="mode" value="add_asset">
                    <div class="col-md-6">
                        <label class="form-label">Asset tag</label>
                        <input type="text"
                               name="asset_tag"
                               class="form-control"
                               placeholder="Scan or type asset tag..."
                               autofocus>
                    </div>
                    <div class="col-md-3 d-grid align-items-end">
                        <button type="submit" class="btn btn-outline-primary mt-4 mt-md-0">
                            Add to checkout list
                        </button>
                    </div>
                </form>

                <!-- Current checkout list -->
                <?php if (empty($checkoutAssets)): ?>
                    <div class="alert alert-secondary">
                        No assets in the checkout list yet. Scan or enter an asset tag above.
                    </div>
                <?php else: ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Asset Tag</th>
                                    <th>Name</th>
                                    <th>Model</th>
                                    <th>Status (from Snipe-IT)</th>
                                    <th style="width: 80px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($checkoutAssets as $asset): ?>
                                    <tr>
                                        <td><?= h($asset['asset_tag']) ?></td>
                                        <td><?= h($asset['name']) ?></td>
                                        <td><?= h($asset['model']) ?></td>
                                        <?php
                                            $statusText = $asset['status'] ?? '';
                                            if (is_array($statusText)) {
                                                $statusText = $statusText['name'] ?? $statusText['status_meta'] ?? $statusText['label'] ?? '';
                                            }
                                        ?>
                                        <td><?= h((string)$statusText) ?></td>
                                        <td>
                                            <a href="staff_checkout.php?remove=<?= (int)$asset['id'] ?>"
                                               class="btn btn-sm btn-outline-danger">
                                                Remove
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Checkout form -->
                    <form method="post" class="border-top pt-3">
                        <input type="hidden" name="mode" value="checkout">

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">
                                    Check out to (Snipe-IT user email or name)
                                </label>
                                <div class="position-relative">
                                    <input type="text"
                                           id="checkout_to"
                                           name="checkout_to"
                                           class="form-control"
                                           autocomplete="off"
                                           placeholder="Start typing email or name">
                                    <div id="userSuggestions"
                                         class="list-group position-absolute w-100"
                                         style="z-index: 1050; max-height: 220px; overflow-y: auto; display: none;">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Note (optional)</label>
                                <input type="text"
                                       name="note"
                                       class="form-control"
                                       placeholder="Optional note to store with checkout">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            Check out all listed assets
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
(function () {
    const input = document.getElementById('checkout_to');
    const list = document.getElementById('userSuggestions');
    if (!input || !list) return;

    let timer = null;
    let lastQuery = '';

    input.addEventListener('input', () => {
        const q = input.value.trim();
        if (q.length < 2) {
            hideSuggestions();
            return;
        }
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => fetchSuggestions(q), 250);
    });

    input.addEventListener('blur', () => {
        setTimeout(hideSuggestions, 150); // allow click
    });

    function fetchSuggestions(q) {
        lastQuery = q;
        fetch('staff_checkout.php?ajax=user_search&q=' + encodeURIComponent(q), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then((res) => res.ok ? res.json() : Promise.reject())
            .then((data) => {
                if (lastQuery !== q) return; // stale
                renderSuggestions(data.results || []);
            })
            .catch(() => {
                renderSuggestions([]);
            });
    }

    function renderSuggestions(items) {
        list.innerHTML = '';
        if (!items || !items.length) {
            hideSuggestions();
            return;
        }

        items.forEach((item) => {
            const email = item.email || '';
            const name = item.name || item.username || email;
            const label = (name && email && name !== email) ? `${name} (${email})` : (name || email);
            const value = email || name;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action';
            btn.textContent = label;
            btn.dataset.value = value;

            btn.addEventListener('click', () => {
                input.value = btn.dataset.value;
                hideSuggestions();
                input.focus();
            });

            list.appendChild(btn);
        });

        list.style.display = 'block';
    }

    function hideSuggestions() {
        list.style.display = 'none';
        list.innerHTML = '';
    }
})();
</script>
</body>
</html>
