<?php
// quick_checkin.php
// Standalone bulk check-in page (quick scan style).

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/layout.php';

$active  = basename($_SERVER['PHP_SELF']);
$isStaff = !empty($currentUser['is_admin']);

if (empty($currentUser['is_admin'])) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (!isset($_SESSION['quick_checkin_assets'])) {
    $_SESSION['quick_checkin_assets'] = [];
}
$checkinAssets = &$_SESSION['quick_checkin_assets'];

$messages = [];
$errors   = [];

// Remove single asset
if (isset($_GET['remove'])) {
    $rid = (int)$_GET['remove'];
    if ($rid > 0 && isset($checkinAssets[$rid])) {
        unset($checkinAssets[$rid]);
    }
    header('Location: quick_checkin.php');
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
                if (is_array($status)) {
                    $status = $status['name'] ?? $status['status_meta'] ?? $status['label'] ?? '';
                }

                if ($assetId <= 0 || $assetTag === '') {
                    throw new Exception('Asset record from Snipe-IT is missing id/asset_tag.');
                }

                $assigned = $asset['assigned_to'] ?? ($asset['assigned_to_fullname'] ?? []);
                $assignedEmail = '';
                $assignedName  = '';
                if (is_array($assigned)) {
                    $assignedEmail = $assigned['email'] ?? ($assigned['username'] ?? '');
                    $assignedName  = $assigned['name'] ?? ($assigned['username'] ?? ($assigned['email'] ?? ''));
                } elseif (is_string($assigned)) {
                    $assignedName = $assigned;
                }

                $checkinAssets[$assetId] = [
                    'id'         => $assetId,
                    'asset_tag'  => $assetTag,
                    'name'       => $assetName,
                    'model'      => $modelName,
                    'status'     => $status,
                    'assigned_email' => $assignedEmail,
                    'assigned_name'  => $assignedName,
                ];
                $messages[] = "Added asset {$assetTag} ({$assetName}) to check-in list.";
            } catch (Throwable $e) {
                $errors[] = 'Could not add asset: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'checkin') {
        $note = trim($_POST['note'] ?? '');

        if (empty($checkinAssets)) {
            $errors[] = 'There are no assets in the check-in list.';
        } else {
            $staffEmail = $currentUser['email'] ?? '';
            $staffName  = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
            $staffDisplayName = $staffName !== '' ? $staffName : ($currentUser['email'] ?? 'Staff');
            $assetTags  = [];
            $userBuckets = [];
            $userLookupCache = [];

            foreach ($checkinAssets as $asset) {
                $assetId  = (int)$asset['id'];
                $assetTag = $asset['asset_tag'] ?? '';
                try {
                    checkin_asset($assetId, $note);
                    $messages[] = "Checked in asset {$assetTag}.";
                    $model = $asset['model'] ?? '';
                    $formatted = $model !== '' ? ($assetTag . ' (' . $model . ')') : $assetTag;
                    $assetTags[] = $formatted;

                    $assignedEmail = $asset['assigned_email'] ?? '';
                    $assignedName  = $asset['assigned_name'] ?? '';
                    if ($assignedEmail === '' && $assignedName !== '') {
                        $cacheKey = strtolower(trim($assignedName));
                        if (isset($userLookupCache[$cacheKey])) {
                            $assignedEmail = $userLookupCache[$cacheKey];
                        } else {
                            try {
                                $matchedUser = find_single_user_by_email_or_name($assignedName);
                                $matchedEmail = $matchedUser['email'] ?? ($matchedUser['username'] ?? '');
                                if ($matchedEmail !== '') {
                                    $assignedEmail = $matchedEmail;
                                    $userLookupCache[$cacheKey] = $matchedEmail;
                                }
                            } catch (Throwable $e) {
                                // Skip lookup failure; user email may be unavailable.
                            }
                        }
                    }

                    if ($assignedEmail !== '') {
                        if (!isset($userBuckets[$assignedEmail])) {
                            $displayName = $assignedName !== '' ? $assignedName : $assignedEmail;
                            $userBuckets[$assignedEmail] = [
                                'name' => $displayName,
                                'assets' => [],
                            ];
                        }
                        $userBuckets[$assignedEmail]['assets'][] = $formatted;
                    }
                } catch (Throwable $e) {
                    $errors[] = "Failed to check in {$assetTag}: " . $e->getMessage();
                }
            }
            if (empty($errors)) {
                $assetLineItems = array_map(static function (string $item): string {
                    return '- ' . $item;
                }, array_values(array_filter($assetTags, static function (string $item): bool {
                    return $item !== '';
                })));

                // Notify original users
                foreach ($userBuckets as $email => $info) {
                    $userAssetLines = array_map(static function (string $item): string {
                        return '- ' . $item;
                    }, array_values(array_filter($info['assets'], static function (string $item): bool {
                        return $item !== '';
                    })));
                    $bodyLines = array_merge(
                        ['The following assets have been checked in:'],
                        $userAssetLines,
                        $staffDisplayName !== '' ? ["Checked in by: {$staffDisplayName}"] : [],
                        $note !== '' ? ["Note: {$note}"] : []
                    );
                    layout_send_notification($email, $info['name'], 'Assets checked in', $bodyLines);
                }
                // Notify staff performing check-in
                if ($staffEmail !== '' && !empty($assetTags)) {
                    // Build per-user summary for staff so they can see who had the assets
                    $perUserSummary = [];
                    foreach ($userBuckets as $email => $info) {
                        $label = ($info['name'] ?? $email);
                        if ($label !== $email) {
                            $label .= " <{$email}>";
                        }
                        $perUserSummary[] = '- ' . $label . ': ' . implode(', ', $info['assets']);
                    }

                    $bodyLines = [];
                    $bodyLines[] = 'You checked in the following assets:';
                    $bodyLines = array_merge($bodyLines, $assetLineItems);
                    if (!empty($perUserSummary)) {
                        $bodyLines[] = 'By user:';
                        $bodyLines = array_merge($bodyLines, $perUserSummary);
                    }
                    if ($note !== '') {
                        $bodyLines[] = "Note: {$note}";
                    }
                    layout_send_notification($staffEmail, $staffDisplayName, 'Assets checked in', $bodyLines);
                }

                $checkinAssets = [];
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
    <title>Quick Checkin – SnipeScheduler</title>
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
            <h1>Quick Checkin</h1>
            <div class="page-subtitle">
                Scan or type asset tags to check items back in via Snipe-IT.
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
                <h5 class="card-title">Bulk check-in</h5>
                <p class="card-text">
                    Scan or type asset tags to add them to the check-in list. When ready, click check in.
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
                            Add to check-in list
                        </button>
                    </div>
                </form>

                <?php if (empty($checkinAssets)): ?>
                    <div class="alert alert-secondary">
                        No assets in the check-in list yet. Scan or enter an asset tag above.
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
                                <?php foreach ($checkinAssets as $asset): ?>
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
                                            <a href="quick_checkin.php?remove=<?= (int)$asset['id'] ?>"
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
                        <input type="hidden" name="mode" value="checkin">

                        <div class="row g-3 mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Note (optional)</label>
                                <input type="text"
                                       name="note"
                                       class="form-control"
                                       placeholder="Optional note to store with check-in">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            Check in all listed assets
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
