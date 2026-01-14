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

function normalize_expected_datetime(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d H:i', $ts);
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
$pageRaw = (int)($_GET['page'] ?? 1);
$perPageRaw = (int)($_GET['per_page'] ?? 10);
$page = $pageRaw > 0 ? $pageRaw : 1;
$perPageOptions = [10, 25, 50, 100];
$perPage = in_array($perPageRaw, $perPageOptions, true) ? $perPageRaw : 10;
$forceRefresh = isset($_REQUEST['refresh']) && $_REQUEST['refresh'] === '1';
if ($forceRefresh) {
    // Disable cached Snipe-IT responses for this request
    if (isset($cacheTtl)) {
        $GLOBALS['_layout_prev_cache_ttl'] = $cacheTtl;
    }
    $cacheTtl = 0;
}

// Handle renew actions (all/overdue tabs)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Renew single
    if (isset($_POST['renew_asset_id'])) {
        $renewId = (int)$_POST['renew_asset_id'];
        $renewExpected = '';
        if (isset($_POST['renew_expected']) && is_array($_POST['renew_expected'])) {
            $renewExpected = $_POST['renew_expected'][$renewId] ?? '';
        }
        $normalized = normalize_expected_datetime($renewExpected);
        if ($renewId > 0 && $normalized !== '') {
            try {
                update_asset_expected_checkin($renewId, $normalized);
                $messages[] = "Extended expected check-in to " . format_display_datetime($normalized) . " for asset #{$renewId}.";
            } catch (Throwable $e) {
                $error = 'Could not renew asset: ' . $e->getMessage();
            }
        } else {
            $error = 'Select a valid date/time for renewal.';
        }
    }

    // Renew selected items
    if (isset($_POST['bulk_renew']) && $_POST['bulk_renew'] === '1') {
        $bulkExpected = normalize_expected_datetime($_POST['bulk_expected'] ?? '');
        $bulkIds = $_POST['bulk_asset_ids'] ?? [];
        if ($bulkExpected === '') {
            $error = 'Select a valid date/time for bulk renewal.';
        } elseif (empty($bulkIds) || !is_array($bulkIds)) {
            $error = 'Select at least one asset to renew.';
        } else {
            try {
                $count = 0;
                foreach ($bulkIds as $idRaw) {
                    $aid = (int)$idRaw;
                    if ($aid > 0) {
                        update_asset_expected_checkin($aid, $bulkExpected);
                        $count++;
                    }
                }
                $messages[] = "Extended expected check-in to " . format_display_datetime($bulkExpected) . " for {$count} asset(s).";
            } catch (Throwable $e) {
                $error = 'Could not renew selected assets: ' . $e->getMessage();
            }
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
    $totalRows = count($assets);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $assets = array_slice($assets, $offset, $perPage);
} catch (Throwable $e) {
    $error = $e->getMessage();
    $totalRows = 0;
    $totalPages = 1;
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
            $allParams     = array_merge($tabBaseParams, ['view' => 'all', 'per_page' => $perPage]);
            $overdueParams = array_merge($tabBaseParams, ['view' => 'overdue', 'per_page' => $perPage]);
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

        <div class="border rounded-3 p-4 mb-4">
            <form method="get" class="row g-2 mb-0 align-items-end" action="<?= h($pageBase) ?>">
            <?php foreach ($baseQuery as $k => $v): ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
            <div class="col-md-4">
                <input type="text"
                       name="q"
                       value="<?= htmlspecialchars($search) ?>"
                       class="form-control form-control-lg"
                       placeholder="Filter by asset tag, name, model, or user">
            </div>
            <div class="col-md-2">
                <select id="checked-out-sort" class="form-select form-select-lg" aria-label="Sort checked-out assets">
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
            <div class="col-md-2">
                <select name="per_page" class="form-select form-select-lg">
                    <?php foreach ($perPageOptions as $opt): ?>
                        <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>>
                            <?= $opt ?> per page
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <?php
                    $clearParams = $tabBaseParams;
                    $clearParams['view'] = $view;
                    $clearParams['per_page'] = $perPage;
                    $clearUrl = layout_checked_out_url($pageBase, $clearParams);
                ?>
                <a href="<?= h($clearUrl) ?>" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
        </form>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
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
            <form method="post">
                <input type="hidden" name="view" value="<?= h($view) ?>">
                <?php if ($search !== ''): ?>
                    <input type="hidden" name="q" value="<?= h($search) ?>">
                <?php endif; ?>
                <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                <input type="hidden" name="page" value="<?= (int)$page ?>">
                <div class="d-flex flex-wrap gap-2 align-items-end mb-2">
                    <div>
                        <label class="form-label mb-1">Renew selected to</label>
                        <input type="datetime-local" name="bulk_expected" class="form-control form-control-sm">
                    </div>
                    <button type="submit"
                            name="bulk_renew"
                            value="1"
                            class="btn btn-outline-primary btn-sm">
                        Renew selected
                    </button>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="select-all-assets">
                    <label class="form-check-label" for="select-all-assets">
                        Select all on this page
                    </label>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th style="width: 40px;"></th>
                                <th>Asset Tag</th>
                                <th>Name</th>
                                <th>Model</th>
                                <th>User</th>
                                <th>Assigned Since</th>
                                <th>Expected Check-in</th>
                                <th style="width: 240px;">Renew to</th>
                                <th style="width: 120px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assets as $a): ?>
                                <?php
                                    $aid = (int)($a['id'] ?? 0);
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
                                    $isOverdue = $expectedTs > 0 && $expectedTs < time();
                                ?>
                                <tr data-asset-tag="<?= h(strtolower($atag)) ?>"
                                    data-asset-name="<?= h(strtolower($name)) ?>"
                                    data-model="<?= h(strtolower($model)) ?>"
                                    data-user="<?= h(strtolower($user)) ?>"
                                    data-expected-ts="<?= (int)$expectedTs ?>"
                                    data-checkout-ts="<?= (int)$checkedOutTs ?>">
                                    <td>
                                        <input class="form-check-input"
                                               type="checkbox"
                                               name="bulk_asset_ids[]"
                                               value="<?= $aid ?>">
                                    </td>
                                    <td><?= h($atag) ?></td>
                                    <td><?= h($name) ?></td>
                                    <td><?= h($model) ?></td>
                                    <td><?= h($user) ?></td>
                                    <td><?= h(format_display_datetime($checkedOut)) ?></td>
                                    <td class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
                                        <?= h(format_display_datetime($expected)) ?>
                                    </td>
                                    <td>
                                        <input type="datetime-local"
                                               name="renew_expected[<?= $aid ?>]"
                                               class="form-control form-control-sm">
                                    </td>
                                    <td>
                                        <button type="submit"
                                                name="renew_asset_id"
                                                value="<?= $aid ?>"
                                                class="btn btn-sm btn-outline-primary"
                                                <?php if ($aid <= 0): ?>disabled<?php endif; ?>>
                                            Renew
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?php if ($totalPages > 1): ?>
                <?php
                    $pagerBase = $pageBase;
                    $pagerQuery = array_merge($baseQuery, [
                        'view' => $view,
                        'q' => $search,
                        'per_page' => $perPage,
                    ]);
                ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php
                            $prevPage = max(1, $page - 1);
                            $nextPage = min($totalPages, $page + 1);
                            $pagerQuery['page'] = $prevPage;
                            $prevUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                            $pagerQuery['page'] = $nextPage;
                            $nextUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                        ?>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($prevUrl) ?>">Previous</a>
                        </li>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php
                                $pagerQuery['page'] = $p;
                                $pageUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                            ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= h($pageUrl) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($nextUrl) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const scrollKey = 'checked_out_scroll_y';
    const savedY = sessionStorage.getItem(scrollKey);
    if (savedY !== null) {
        const y = parseInt(savedY, 10);
        if (!Number.isNaN(y)) {
            window.scrollTo(0, y);
        }
        sessionStorage.removeItem(scrollKey);
    }

    document.addEventListener('submit', () => {
        sessionStorage.setItem(scrollKey, String(window.scrollY));
    });
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a');
        if (!link) {
            return;
        }
        const href = link.getAttribute('href') || '';
        if (href.includes('page=')) {
            sessionStorage.setItem(scrollKey, String(window.scrollY));
        }
    });

    const selectAll = document.getElementById('select-all-assets');
    if (selectAll) {
        selectAll.addEventListener('change', () => {
            const boxes = document.querySelectorAll('input[name="bulk_asset_ids[]"]');
            boxes.forEach((box) => {
                box.checked = selectAll.checked;
            });
        });
    }

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

    const storageKey = 'checked_out_sort';
    const saved = window.localStorage ? localStorage.getItem(storageKey) : '';
    const initial = saved || 'expected_asc';
    sortSelect.value = initial;
    sortRows(initial);
    sortSelect.addEventListener('change', function () {
        const val = sortSelect.value;
        if (window.localStorage) {
            localStorage.setItem(storageKey, val);
        }
        sortRows(val);
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
<?php if (!empty($messages)): ?>
<script>
    // After showing renew success, refresh overdue list to bust any cached data.
    setTimeout(() => {
        const url = new URL(window.location.href);
        url.searchParams.set('view', '<?= h($view) ?>');
        url.searchParams.set('_', Date.now().toString());
        url.searchParams.set('refresh', '1');
        // Force no-cache reload with refresh flag
        window.location.replace(url.toString());
    }, 4000);
</script>
<?php endif; ?>
