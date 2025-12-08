<?php
// quick_checkout.php
// Standalone bulk checkout page (ad-hoc, not tied to reservations).

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/footer.php';

$active  = basename($_SERVER['PHP_SELF']);
$isStaff = !empty($currentUser['is_admin']);

if (empty($currentUser['is_admin'])) {
    http_response_code(403);
    echo 'Access denied.';
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
                    'status'     => $status,
                ];
                $messages[] = "Added asset {$assetTag} ({$assetName}) to checkout list.";
            } catch (Throwable $e) {
                $errors[] = 'Could not add asset: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'checkout') {
        $checkoutTo = trim($_POST['checkout_to'] ?? '');
        $note       = trim($_POST['note'] ?? '');

        if ($checkoutTo === '') {
            $errors[] = 'Please enter the Snipe-IT user (email or name) to check out to.';
        } elseif (empty($checkoutAssets)) {
            $errors[] = 'There are no assets in the checkout list.';
        } else {
            try {
                $user = find_single_user_by_email_or_name($checkoutTo);
                $userId   = (int)($user['id'] ?? 0);
                $userName = $user['name'] ?? ($user['username'] ?? $checkoutTo);

                if ($userId <= 0) {
                    throw new Exception('Matched user has no valid ID.');
                }

                foreach ($checkoutAssets as $asset) {
                    $assetId  = (int)$asset['id'];
                    $assetTag = $asset['asset_tag'] ?? '';
                    try {
                        checkout_asset_to_user($assetId, $userId, $note, null);
                        $messages[] = "Checked out asset {$assetTag} to {$userName}.";
                    } catch (Throwable $e) {
                        $errors[] = "Failed to check out {$assetTag}: " . $e->getMessage();
                    }
                }

                if (empty($errors)) {
                    // Email notifications
                    $userEmail  = $user['email'] ?? '';
                    $staffEmail = $currentUser['email'] ?? '';
                    $staffName  = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
                    $assetList  = array_map(function ($a) {
                        $tag   = $a['asset_tag'] ?? '';
                        $model = $a['model'] ?? '';
                        return $model !== '' ? "{$tag} ({$model})" : $tag;
                    }, $checkoutAssets);
                    $assetLines = implode(', ', array_filter($assetList));
                    $bodyLines = [
                        'Assets checked out:',
                        $assetLines,
                        $note !== '' ? "Note: {$note}" : '',
                    ];
                    if ($userEmail !== '') {
                        reserveit_send_notification($userEmail, $userName, 'Assets checked out', $bodyLines);
                    }
                    if ($staffEmail !== '') {
                        reserveit_send_notification($staffEmail, $staffName !== '' ? $staffName : $staffEmail, 'You checked out assets', $bodyLines);
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
    <title>Quick Checkout – ReserveIT</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= reserveit_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= reserveit_logo_tag() ?>
        <div class="page-header">
            <h1>Quick Checkout</h1>
            <div class="page-subtitle">
                Ad-hoc bulk checkout via Snipe-IT (not tied to a reservation).
            </div>
        </div>

        <?= reserveit_render_nav($active, $isStaff) ?>

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
<?php reserveit_footer(); ?>
</body>
</html>
