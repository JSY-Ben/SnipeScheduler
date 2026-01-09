<?php
// quick_checkout.php
// Standalone bulk checkout page (ad-hoc, not tied to reservations).

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/layout.php';

$now = new DateTime();
$defaultStart = $now->format('Y-m-d\TH:i');
$defaultEnd   = (new DateTime('tomorrow 9:00'))->format('Y-m-d\TH:i');

$startRaw = $_POST['start_datetime'] ?? $defaultStart;
$endRaw   = $_POST['end_datetime'] ?? $defaultEnd;

$reservationConflicts = [];

// Helpers
function qc_format_uk(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $iso);
    return $dt ? $dt->format('d/m/Y H:i') : $iso;
}

/**
 * Return current reservations (pending/confirmed) for a given model that overlap "now".
 */
function qc_current_reservations_for_model(PDO $pdo, int $modelId): array
{
    if ($modelId <= 0) {
        return [];
    }

    $now = (new DateTime())->format('Y-m-d H:i:s');
    $sql = "
        SELECT r.id,
               r.user_name,
               r.user_email,
               r.start_datetime,
               r.end_datetime,
               r.status,
               ri.quantity
          FROM reservation_items ri
          JOIN reservations r ON r.id = ri.reservation_id
         WHERE ri.model_id = :model_id
           AND r.status IN ('pending','confirmed')
           AND r.start_datetime <= :now
           AND r.end_datetime   >= :now
         ORDER BY r.start_datetime ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':model_id' => $modelId,
        ':now'      => $now,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$active  = basename($_SERVER['PHP_SELF']);
$isStaff = !empty($currentUser['is_admin']);

if (empty($currentUser['is_admin'])) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (($_GET['ajax'] ?? '') === 'asset_search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        $rows = search_assets($q, 20, true);
        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'asset_tag' => $row['asset_tag'] ?? '',
                'name'      => $row['name'] ?? '',
                'model'     => $row['model']['name'] ?? '',
            ];
        }
        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Asset search failed.']);
    }
    exit;
}

if (!isset($_SESSION['quick_checkout_assets'])) {
    $_SESSION['quick_checkout_assets'] = [];
}
$checkoutAssets = &$_SESSION['quick_checkout_assets'];

$messages = [];
$errors   = [];

// Remove single asset
if (isset($_GET['remove'])) {
    $rid = (int)$_GET['remove'];
    if ($rid > 0 && isset($checkoutAssets[$rid])) {
        unset($checkoutAssets[$rid]);
    }
    header('Location: quick_checkout.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';

    if ($mode === 'add_asset') {
        $tag = trim($_POST['asset_tag'] ?? '');
        if ($tag === '') {
            $errors[] = 'Please scan or enter an asset tag.';
        } else {
            try {
                $asset = find_asset_by_tag($tag);
                $assetId   = (int)($asset['id'] ?? 0);
                $assetTag  = $asset['asset_tag'] ?? '';
                $assetName = $asset['name'] ?? '';
                $modelName = $asset['model']['name'] ?? '';
                $status    = $asset['status_label'] ?? '';
                $isRequestable = !empty($asset['requestable']);
                if (is_array($status)) {
                    $status = $status['name'] ?? $status['status_meta'] ?? $status['label'] ?? '';
                }

                if ($assetId <= 0 || $assetTag === '') {
                    throw new Exception('Asset record from Snipe-IT is missing id/asset_tag.');
                }
                if (!$isRequestable) {
                    throw new Exception('This asset is not requestable in Snipe-IT.');
                }

                $checkoutAssets[$assetId] = [
                    'id'         => $assetId,
                    'asset_tag'  => $assetTag,
                    'name'       => $assetName,
                    'model'      => $modelName,
                    'model_id'   => (int)($asset['model']['id'] ?? 0),
                    'status'     => $status,
                ];
                $messages[] = "Added asset {$assetTag} ({$assetName}) to checkout list.";
            } catch (Throwable $e) {
                $errors[] = 'Could not add asset: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'checkout') {
        $checkoutTo      = trim($_POST['checkout_to'] ?? '');
        $note            = trim($_POST['note'] ?? '');
        $overrideAllowed = isset($_POST['override_conflicts']) && $_POST['override_conflicts'] === '1';
        $startRaw        = trim($_POST['start_datetime'] ?? $startRaw);
        $endRaw          = trim($_POST['end_datetime'] ?? $endRaw);

        $startTs = strtotime($startRaw);
        $endTs   = strtotime($endRaw);

        if ($checkoutTo === '') {
            $errors[] = 'Please enter the Snipe-IT user (email or name) to check out to.';
        } elseif (empty($checkoutAssets)) {
            $errors[] = 'There are no assets in the checkout list.';
        } elseif ($startTs === false || $endTs === false) {
            $errors[] = 'Invalid start or end date/time.';
        } elseif ($endTs <= $startTs) {
            $errors[] = 'End date/time must be after start date/time.';
        } else {
            try {
                $user = find_single_user_by_email_or_name($checkoutTo);
                $userId   = (int)($user['id'] ?? 0);
                $userName = $user['name'] ?? ($user['username'] ?? $checkoutTo);

                if ($userId <= 0) {
                    throw new Exception('Matched user has no valid ID.');
                }

                // Check for active reservations on these models right now
                $reservationConflicts = [];
                foreach ($checkoutAssets as $asset) {
                    $mid = (int)($asset['model_id'] ?? 0);
                    if ($mid > 0) {
                        $conf = qc_current_reservations_for_model($pdo, $mid);
                        if (!empty($conf)) {
                            $reservationConflicts[$asset['id']] = $conf;
                        }
                    }
                }

                if (!empty($reservationConflicts) && !$overrideAllowed) {
                    $errors[] = 'Some assets are reserved for this time. Review who reserved them below or tick "Override" to proceed anyway.';
                } else {
                    $expectedCheckinIso = date('Y-m-d H:i:s', $endTs);

                    foreach ($checkoutAssets as $asset) {
                        $assetId  = (int)$asset['id'];
                        $assetTag = $asset['asset_tag'] ?? '';
                        try {
                            checkout_asset_to_user($assetId, $userId, $note, $expectedCheckinIso);
                            $messages[] = "Checked out asset {$assetTag} to {$userName}." . (!empty($reservationConflicts[$assetId]) ? ' (Override used)' : '');
                        } catch (Throwable $e) {
                            $errors[] = "Failed to check out {$assetTag}: " . $e->getMessage();
                        }
                    }
                }

                if (empty($errors)) {
                    // Email notifications
                    $userEmail  = $user['email'] ?? '';
                    $staffEmail = $currentUser['email'] ?? '';
                    $staffName  = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
                    $staffDisplayName = $staffName !== '' ? $staffName : ($currentUser['email'] ?? 'Staff');
                    $assetList  = array_map(function ($a) {
                        $tag   = $a['asset_tag'] ?? '';
                        $model = $a['model'] ?? '';
                        return $model !== '' ? "{$tag} ({$model})" : $tag;
                    }, $checkoutAssets);
                    $assetLines = implode(', ', array_filter($assetList));
                    $dueDisplay = date('d/m/Y h:i A', $endTs);
                    $bodyLines = [
                        'Assets checked out:',
                        $assetLines,
                        "Return by: {$dueDisplay}",
                        $note !== '' ? "Note: {$note}" : '',
                    ];
                    if ($userEmail !== '') {
                        layout_send_notification($userEmail, $userName, 'Assets checked out', $bodyLines);
                    }
                    if ($staffEmail !== '') {
                        $staffBody = array_merge(
                            [
                                "You checked out assets for {$userName}"
                            ],
                            $bodyLines
                        );
                        layout_send_notification($staffEmail, $staffDisplayName, 'You checked out assets', $staffBody);
                    }

                    $checkoutAssets = [];
                }
            } catch (Throwable $e) {
                $errors[] = 'Could not find user in Snipe-IT: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quick Checkout – SnipeScheduler</title>
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
            <h1>Quick Checkout</h1>
            <div class="page-subtitle">
                Ad-hoc bulk checkout via Snipe-IT (not tied to a reservation).
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff) ?>

        <?php if (!empty($messages)): ?>
            <div class="alert alert-success">
                <ul class="mb-0">
                    <?php foreach ($messages as $m): ?>
                        <li><?= h($m) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Bulk checkout (via Snipe-IT)</h5>
                <p class="card-text">
                    Scan or type asset tags to add them to the checkout list. When ready, enter
                    the Snipe-IT user (email or name) and check out all items in one go.
                </p>

                <form method="post" class="row g-2 mb-3">
                    <input type="hidden" name="mode" value="add_asset">
                    <div class="col-md-6">
                        <label class="form-label">Asset tag</label>
                        <div class="position-relative asset-autocomplete-wrapper">
                            <input type="text"
                                   name="asset_tag"
                                   class="form-control asset-autocomplete"
                                   autocomplete="off"
                                   placeholder="Scan or type asset tag..."
                                   autofocus>
                            <div class="list-group position-absolute w-100"
                                 data-asset-suggestions
                                 style="z-index: 1050; max-height: 220px; overflow-y: auto; display: none;"></div>
                        </div>
                    </div>
                    <div class="col-md-3 d-grid align-items-end">
                        <button type="submit" class="btn btn-outline-primary mt-4 mt-md-0">
                            Add to checkout list
                        </button>
                    </div>
                </form>

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
                                            <a href="quick_checkout.php?remove=<?= (int)$asset['id'] ?>"
                                               class="btn btn-sm btn-outline-danger">
                                                Remove
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if (!empty($reservationConflicts)): ?>
                        <div class="alert alert-warning">
                            <div class="fw-semibold mb-1">Some assets are reserved right now.</div>
                            <div class="small mb-2">Review who has them reserved for the current window before overriding.</div>
                            <ul class="mb-0">
                                <?php foreach ($checkoutAssets as $asset): ?>
                                    <?php if (empty($reservationConflicts[$asset['id']])) continue; ?>
                                    <li class="mb-1">
                                        <strong><?= h($asset['asset_tag']) ?></strong>
                                        <?php if (!empty($asset['model'])): ?>
                                            (<?= h($asset['model']) ?>)
                                        <?php endif; ?>
                                        <div class="small text-muted">
                                            <?php foreach ($reservationConflicts[$asset['id']] as $conf): ?>
                                                Reserved by <?= h($conf['user_name'] ?? 'Unknown') ?>
                                                (<?= h($conf['user_email'] ?? '') ?>)
                                                from <?= h(qc_format_uk($conf['start_datetime'] ?? '')) ?>
                                                to <?= h(qc_format_uk($conf['end_datetime'] ?? '')) ?>.
                                                Quantity: <?= (int)($conf['quantity'] ?? 0) ?>.
                                                <br>
                                            <?php endforeach; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="border-top pt-3">
                        <input type="hidden" name="mode" value="checkout">

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">
                                    Check out to (Snipe-IT user email or name)
                                </label>
                                <div class="position-relative user-autocomplete-wrapper">
                                    <input type="text"
                                           name="checkout_to"
                                           class="form-control user-autocomplete"
                                           autocomplete="off"
                                           placeholder="Start typing email or name">
                                    <div class="list-group position-absolute w-100"
                                         data-suggestions
                                         style="z-index: 1050; max-height: 220px; overflow-y: auto; display: none;"></div>
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

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Start date &amp; time</label>
                                <input type="datetime-local"
                                       name="start_datetime"
                                       class="form-control"
                                       value="<?= h($startRaw) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">End date &amp; time</label>
                                <input type="datetime-local"
                                       name="end_datetime"
                                       class="form-control"
                                       value="<?= h($endRaw) ?>">
                                <div class="form-text">Defaults to tomorrow at 09:00.</div>
                            </div>
                        </div>

                        <?php if (!empty($reservationConflicts)): ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" value="1" id="override_conflicts" name="override_conflicts">
                                <label class="form-check-label" for="override_conflicts">
                                    Override current reservations and check out anyway
                                </label>
                            </div>
                        <?php endif; ?>

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
    const assetWrappers = document.querySelectorAll('.asset-autocomplete-wrapper');
    assetWrappers.forEach((wrapper) => {
        const input = wrapper.querySelector('.asset-autocomplete');
        const list  = wrapper.querySelector('[data-asset-suggestions]');
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
            timer = setTimeout(() => fetchSuggestions(q), 200);
        });

        input.addEventListener('blur', () => {
            setTimeout(hideSuggestions, 150);
        });

        function fetchSuggestions(q) {
            lastQuery = q;
            fetch('quick_checkout.php?ajax=asset_search&q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then((res) => res.ok ? res.json() : Promise.reject())
                .then((data) => {
                    if (lastQuery !== q) return;
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
                const tag = item.asset_tag || '';
                const model = item.model || '';
                const label = model !== '' ? `${tag} [${model}]` : tag;

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action';
                btn.textContent = label;
                btn.dataset.value = tag;

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
    });

    const wrappers = document.querySelectorAll('.user-autocomplete-wrapper');
    wrappers.forEach((wrapper) => {
        const input = wrapper.querySelector('.user-autocomplete');
        const list  = wrapper.querySelector('[data-suggestions]');
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
            setTimeout(hideSuggestions, 150);
        });

        function fetchSuggestions(q) {
            lastQuery = q;
            fetch('staff_checkout.php?ajax=user_search&q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then((res) => res.ok ? res.json() : Promise.reject())
                .then((data) => {
                    if (lastQuery !== q) return;
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
    });
})();
</script>
<?php layout_footer(); ?>
</body>
</html>
