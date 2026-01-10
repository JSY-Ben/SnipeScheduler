<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

function format_display_date($val): string
{
    if (is_array($val)) {
        $val = $val['datetime'] ?? ($val['date'] ?? '');
    }
    if (empty($val)) {
        return '';
    }
    try {
        $dt = new DateTime($val);
        return $dt->format('d/m/Y');
    } catch (Throwable $e) {
        return $val;
    }
}

function format_display_datetime($val): string
{
    if (is_array($val)) {
        $val = $val['datetime'] ?? ($val['date'] ?? '');
    }
    if (empty($val)) {
        return '';
    }
    try {
        $dt = new DateTime($val);
        return $dt->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $val;
    }
}

$active    = basename($_SERVER['PHP_SELF']);
$isStaff   = !empty($currentUser['is_admin']);
$embedded  = defined('RESERVATIONS_EMBED');
$pageBase  = $embedded ? 'reservations.php' : 'checked_out_assets.php';
$baseQuery = $embedded ? ['tab' => 'checked_out'] : [];
$messages  = [];

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$viewRaw = $_REQUEST['view'] ?? ($_REQUEST['tab'] ?? 'all');
$view    = $viewRaw === 'overdue' ? 'overdue' : 'all';
$error   = '';
$assets  = [];
$search  = trim($_GET['q'] ?? '');
$forceRefresh = isset($_REQUEST['refresh']) && $_REQUEST['refresh'] === '1';
if ($forceRefresh) {
    // Disable cached Snipe-IT responses for this request
    if (isset($cacheTtl)) {
        $GLOBALS['_layout_prev_cache_ttl'] = $cacheTtl;
    }
    $cacheTtl = 0;
}

// Handle renew actions (overdue tab only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $view === 'overdue') {
    // Renew single
    if (isset($_POST['renew_asset_id'])) {
        $renewId = (int)$_POST['renew_asset_id'];
        if ($renewId > 0) {
            try {
                $tomorrowDt = new DateTime('tomorrow');
                $tomorrow   = $tomorrowDt->format('Y-m-d');
                update_asset_expected_checkin($renewId, $tomorrow);
                $messages[] = "Extended expected check-in to " . $tomorrowDt->format('d/m/Y') . " for asset #{$renewId}.";
            } catch (Throwable $e) {
                $error = 'Could not renew asset: ' . $e->getMessage();
            }
        } else {
            $error = 'Invalid asset selected for renewal.';
        }
    }

    // Renew all visible (filtered) items
    if (isset($_POST['renew_all']) && $_POST['renew_all'] === '1') {
        try {
            // Ensure we apply the same filter when renewing all
            $assetsToRenew = list_checked_out_assets(true);
            if ($search !== '') {
                $q = mb_strtolower($search);
                $assetsToRenew = array_values(array_filter($assetsToRenew, function ($row) use ($q) {
                    $fields = [
                        $row['asset_tag'] ?? '',
            $row['name'] ?? '',
            $row['model']['name'] ?? '',
            $row['assigned_to'] ?? ($row['assigned_to_fullname'] ?? ''),
        ];
                    foreach ($fields as $f) {
                        if (is_array($f)) {
                            $f = implode(' ', $f);
                        }
                        if (mb_stripos((string)$f, $q) !== false) {
                            return true;
                        }
                    }
                    return false;
                }));
            }

            $tomorrowDt = new DateTime('tomorrow');
            $tomorrow   = $tomorrowDt->format('Y-m-d');
            foreach ($assetsToRenew as $row) {
                $aid = (int)($row['id'] ?? 0);
                if ($aid > 0) {
                    update_asset_expected_checkin($aid, $tomorrow);
                }
            }
            $messages[] = "Extended expected check-in to " . $tomorrowDt->format('d/m/Y') . " for " . count($assetsToRenew) . " asset(s).";
        } catch (Throwable $e) {
            $error = 'Could not renew all overdue assets: ' . $e->getMessage();
        }
    }
}

try {
    $assets = list_checked_out_assets($view === 'overdue');
    if ($search !== '') {
        $q = mb_strtolower($search);
    $assets = array_values(array_filter($assets, function ($row) use ($q) {
        $fields = [
            $row['asset_tag'] ?? '',
            $row['name'] ?? '',
            $row['model']['name'] ?? '',
                $row['assigned_to'] ?? ($row['assigned_to_fullname'] ?? ''),
            ];
            foreach ($fields as $f) {
                if (is_array($f)) {
                    $f = implode(' ', $f);
                }
                if (mb_stripos((string)$f, $q) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// Restore cache TTL if we temporarily disabled it
if ($forceRefresh && isset($GLOBALS['_layout_prev_cache_ttl'])) {
    $cacheTtl = $GLOBALS['_layout_prev_cache_ttl'];
    unset($GLOBALS['_layout_prev_cache_ttl']);
}
?>
<?php
function layout_checked_out_url(string $base, array $params): string
{
    $query = http_build_query($params);
    return $query === '' ? $base : ($base . '?' . $query);
}
?>
<?php if (!$embedded): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checked Out Reservations – SnipeScheduler</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
<?php endif; ?>
        <style>
            /* Make sub-tabs pop within the reservations area */
            .reservations-subtabs {
                border-bottom: 3px solid var(--primary-strong);
                gap: 0.25rem;
            }
            .reservations-subtabs .nav-link {
                border: 1px solid transparent;
                color: var(--primary-strong);
                font-weight: 600;
                padding: 0.8rem 1.1rem;
                border-radius: 0.5rem 0.5rem 0 0;
                background: linear-gradient(180deg, rgba(var(--primary-soft-rgb),0.18), rgba(255,255,255,0));
                transition: all 120ms ease;
            }
            .reservations-subtabs .nav-link:hover {
                color: var(--primary);
                background: linear-gradient(180deg, rgba(var(--primary-soft-rgb),0.36), rgba(255,255,255,0.08));
                border-color: rgba(var(--primary-rgb),0.25);
            }
            .reservations-subtabs .nav-link.active {
                color: #fff;
                background: linear-gradient(135deg, var(--primary), var(--primary-strong));
                border-color: var(--primary-strong) var(--primary-strong) #fff;
                box-shadow: 0 8px 18px rgba(var(--primary-rgb), 0.2);
            }
        </style>
        <div class="page-header">
            <h1>Checked Out Reservations</h1>
            <div class="page-subtitle">
                Showing requestable assets currently checked out in Snipe-IT.
            </div>
        </div>

        <?php if (!$embedded): ?>
            <?= layout_render_nav($active, $isStaff) ?>
        <?php endif; ?>

        <?php
            $tabBaseParams = $baseQuery;
            $allParams     = array_merge($tabBaseParams, ['view' => 'all']);
            $overdueParams = array_merge($tabBaseParams, ['view' => 'overdue']);
            if ($search !== '') {
                $allParams['q']     = $search;
                $overdueParams['q'] = $search;
            }
            $allUrl     = layout_checked_out_url($pageBase, $allParams);
            $overdueUrl = layout_checked_out_url($pageBase, $overdueParams);
        ?>

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $view === 'all' ? 'active' : '' ?>"
                   href="<?= h($allUrl) ?>">All checked out</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $view === 'overdue' ? 'active' : '' ?>"
                   href="<?= h($overdueUrl) ?>">Overdue</a>
            </li>
        </ul>

        <form method="get" class="row g-2 mb-3" action="<?= h($pageBase) ?>">
            <?php foreach ($baseQuery as $k => $v): ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
            <div class="col-md-6">
                <input type="text"
                       name="q"
                       value="<?= htmlspecialchars($search) ?>"
                       class="form-control"
                       placeholder="Filter by asset tag, name, model, or user">
            </div>
            <div class="col-md-3">
                <select id="checked-out-sort" class="form-select" aria-label="Sort checked-out assets">
                    <option value="expected_asc">Expected check-in (soonest first)</option>
                    <option value="expected_desc">Expected check-in (latest first)</option>
                    <option value="tag_asc">Asset tag (A–Z)</option>
                    <option value="tag_desc">Asset tag (Z–A)</option>
                    <option value="model_asc">Model (A–Z)</option>
                    <option value="model_desc">Model (Z–A)</option>
                    <option value="user_asc">User (A–Z)</option>
                    <option value="user_desc">User (Z–A)</option>
                    <option value="checkout_desc">Assigned since (newest first)</option>
                    <option value="checkout_asc">Assigned since (oldest first)</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </form>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($view === 'overdue' && !empty($assets)): ?>
            <div class="d-flex justify-content-end mb-2">
                <form method="post" class="mb-0 d-flex gap-2 align-items-center">
                    <input type="hidden" name="view" value="overdue">
                    <input type="hidden" name="renew_all" value="1">
                    <?php if ($search !== ''): ?>
                        <input type="hidden" name="q" value="<?= h($search) ?>">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary btn-sm px-3 shadow-sm">
                        Renew all shown to tomorrow
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <?php if (!empty($messages)): ?>
            <div class="alert alert-success">
                <ul class="mb-0">
                    <?php foreach ($messages as $m): ?>
                        <li><?= h($m) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (empty($assets) && !$error): ?>
            <div class="alert alert-secondary">
                No <?= $view === 'overdue' ? 'overdue ' : '' ?>checked-out requestable assets.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Asset Tag</th>
                            <th>Name</th>
                            <th>Model</th>
                            <th>User</th>
                            <th>Assigned Since</th>
                            <th>Expected Check-in</th>
                            <?php if ($view === 'overdue'): ?>
                                <th style="width: 140px;"></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assets as $a): ?>
                            <?php
                                $atag = $a['asset_tag'] ?? '';
                                $name = $a['name'] ?? '';
                                $model = $a['model']['name'] ?? '';
                                $user  = $a['assigned_to'] ?? ($a['assigned_to_fullname'] ?? '');
                                if (is_array($user)) {
                                    $user = $user['name'] ?? ($user['username'] ?? '');
                                }
                                $checkedOut = $a['_last_checkout_norm'] ?? ($a['last_checkout'] ?? '');
                                $expected   = $a['_expected_checkin_norm'] ?? ($a['expected_checkin'] ?? '');
                                $checkedOutTs = $checkedOut ? strtotime($checkedOut) : 0;
                                $expectedTs   = $expected ? strtotime($expected) : 0;
                            ?>
                            <tr data-asset-tag="<?= h(strtolower($atag)) ?>"
                                data-asset-name="<?= h(strtolower($name)) ?>"
                                data-model="<?= h(strtolower($model)) ?>"
                                data-user="<?= h(strtolower($user)) ?>"
                                data-expected-ts="<?= (int)$expectedTs ?>"
                                data-checkout-ts="<?= (int)$checkedOutTs ?>">
                                <td><?= h($atag) ?></td>
                                <td><?= h($name) ?></td>
                                <td><?= h($model) ?></td>
                                <td><?= h($user) ?></td>
                                <td><?= h(format_display_datetime($checkedOut)) ?></td>
                                <td class="<?= ($view === 'overdue' ? 'text-danger fw-semibold' : '') ?>">
                                    <?= h(format_display_date($expected)) ?>
                                </td>
                                <?php if ($view === 'overdue'): ?>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="view" value="overdue">
                                            <input type="hidden" name="renew_asset_id" value="<?= (int)($a['id'] ?? 0) ?>">
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-primary"
                                                    <?php if (empty($a['id'])): ?>disabled<?php endif; ?>>
                                                Renew to tomorrow
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sortSelect = document.getElementById('checked-out-sort');
    const table = document.querySelector('.table-responsive table');
    if (!sortSelect || !table) return;

    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    function getText(row, key) {
        return (row.dataset[key] || '').toString();
    }

    function getNumber(row, key) {
        const val = parseInt(row.dataset[key] || '0', 10);
        return Number.isNaN(val) ? 0 : val;
    }

    function compareValues(a, b, asc) {
        if (a < b) return asc ? -1 : 1;
        if (a > b) return asc ? 1 : -1;
        return 0;
    }

    function sortRows(value) {
        const rows = Array.from(tbody.querySelectorAll('tr'));
        let key = '';
        let asc = true;

        switch (value) {
            case 'expected_desc':
                key = 'expectedTs';
                asc = false;
                break;
            case 'expected_asc':
                key = 'expectedTs';
                asc = true;
                break;
            case 'tag_desc':
                key = 'assetTag';
                asc = false;
                break;
            case 'tag_asc':
                key = 'assetTag';
                asc = true;
                break;
            case 'model_desc':
                key = 'model';
                asc = false;
                break;
            case 'model_asc':
                key = 'model';
                asc = true;
                break;
            case 'user_desc':
                key = 'user';
                asc = false;
                break;
            case 'user_asc':
                key = 'user';
                asc = true;
                break;
            case 'checkout_asc':
                key = 'checkoutTs';
                asc = true;
                break;
            case 'checkout_desc':
                key = 'checkoutTs';
                asc = false;
                break;
            default:
                key = 'expectedTs';
                asc = true;
        }

        rows.sort(function (ra, rb) {
            if (key === 'expectedTs') {
                const aVal = getNumber(ra, 'expectedTs') || Number.MAX_SAFE_INTEGER;
                const bVal = getNumber(rb, 'expectedTs') || Number.MAX_SAFE_INTEGER;
                return compareValues(aVal, bVal, asc);
            }
            if (key === 'checkoutTs') {
                const aVal = getNumber(ra, 'checkoutTs') || 0;
                const bVal = getNumber(rb, 'checkoutTs') || 0;
                return compareValues(aVal, bVal, asc);
            }

            const aText = getText(ra, key);
            const bText = getText(rb, key);
            return compareValues(aText, bText, asc);
        });

        rows.forEach(function (row) {
            tbody.appendChild(row);
        });
    }

    sortRows('expected_asc');
    sortSelect.value = 'expected_asc';
    sortSelect.addEventListener('change', function () {
        sortRows(sortSelect.value);
    });
});
</script>
<?php if (!$embedded): ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
<?php endif; ?>
<?php if (!empty($messages) && $view === 'overdue'): ?>
<script>
    // After showing renew success, refresh overdue list to bust any cached data.
    setTimeout(() => {
        const url = new URL(window.location.href);
        url.searchParams.set('view', 'overdue');
        url.searchParams.set('_', Date.now().toString());
        url.searchParams.set('refresh', '1');
        // Force no-cache reload with refresh flag
        window.location.replace(url.toString());
    }, 4000);
</script>
<?php endif; ?>
