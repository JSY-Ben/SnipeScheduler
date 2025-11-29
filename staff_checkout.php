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

// ---------------------------------------------------------------------
// Load today's bookings from reservations table
// ---------------------------------------------------------------------
$todayBookings = [];
$todayError    = '';

try {
    $tz = new DateTimeZone($timezone);
    $now = new DateTime('now', $tz);
    $todayStr = $now->format('Y-m-d');

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

// Messages
$checkoutMessages = [];
$checkoutErrors   = [];

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
// Handle POST actions: add_asset or checkout
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';

    if ($mode === 'add_asset') {
        $tag = trim($_POST['asset_tag'] ?? '');
        if ($tag === '') {
            $checkoutErrors[] = 'Please scan or enter an asset tag.';
        } else {
            try {
                $asset = find_asset_by_tag($tag);

                $assetId   = (int)($asset['id'] ?? 0);
                $assetTag  = $asset['asset_tag'] ?? '';
                $assetName = $asset['name'] ?? '';
                $modelName = $asset['model']['name'] ?? '';
                $status    = $asset['status_label'] ?? '';

                // Normalise status label to a string (API may return array/object)
                if (is_array($status)) {
                    $status = $status['name'] ?? $status['status_meta'] ?? $status['label'] ?? '';
                }

                if ($assetId <= 0 || $assetTag === '') {
                    throw new Exception('Asset record from Snipe-IT is missing id/asset_tag.');
                }

                // Avoid duplicates: overwrite existing entry for same asset id
                $checkoutAssets[$assetId] = [
                    'id'         => $assetId,
                    'asset_tag'  => $assetTag,
                    'name'       => $assetName,
                    'model'      => $modelName,
                    'status'     => $status,
                ];

                $checkoutMessages[] = "Added asset {$assetTag} ({$assetName}) to checkout list.";
            } catch (Throwable $e) {
                $checkoutErrors[] = 'Could not add asset: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'checkout') {
        $checkoutTo = trim($_POST['checkout_to'] ?? '');
        $note       = trim($_POST['note'] ?? '');

        if ($checkoutTo === '') {
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
                <strong><?= htmlspecialchars(trim($currentUser['first_name'] . ' ' . $currentUser['last_name'])) ?></strong>
                (<?= htmlspecialchars($currentUser['email']) ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <!-- Feedback messages -->
        <?php if (!empty($checkoutMessages)): ?>
            <div class="alert alert-success">
                <ul class="mb-0">
                    <?php foreach ($checkoutMessages as $m): ?>
                        <li><?= htmlspecialchars($m) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($checkoutErrors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($checkoutErrors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
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
                        Could not load today’s bookings: <?= htmlspecialchars($todayError) ?>
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
                                        <td><?= htmlspecialchars($res['student_name'] ?? '(Unknown)') ?></td>
                                        <td><?= htmlspecialchars($summary) ?></td>
                                        <td><?= htmlspecialchars(uk_datetime_display($res['start_datetime'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars(uk_datetime_display($res['end_datetime'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($res['status'] ?? '') ?></td>
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
                                        <td><?= htmlspecialchars($asset['asset_tag']) ?></td>
                                        <td><?= htmlspecialchars($asset['name']) ?></td>
                                        <td><?= htmlspecialchars($asset['model']) ?></td>
                                        <?php
                                            $statusText = $asset['status'] ?? '';
                                            if (is_array($statusText)) {
                                                $statusText = $statusText['name'] ?? $statusText['status_meta'] ?? $statusText['label'] ?? '';
                                            }
                                        ?>
                                        <td><?= htmlspecialchars((string)$statusText) ?></td>
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
