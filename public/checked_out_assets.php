<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/footer.php';

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

$active    = basename($_SERVER['PHP_SELF']);
$isStaff   = !empty($currentUser['is_admin']);
$embedded  = defined('RESERVATIONS_EMBED');
$pageBase  = $embedded ? 'reservations.php' : 'checked_out_assets.php';
$baseQuery = $embedded ? ['tab' => 'checked_out'] : [];

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$viewRaw = $_GET['view'] ?? ($_GET['tab'] ?? 'all');
$view    = $viewRaw === 'overdue' ? 'overdue' : 'all';
$error   = '';
$assets  = [];
$search  = trim($_GET['q'] ?? '');

try {
    $assets = list_checked_out_assets($tab === 'overdue');
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
?>
<?php
function reserveit_checked_out_url(string $base, array $params): string
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
    <title>Checked Out Reservations – ReserveIT</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= reserveit_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= reserveit_logo_tag() ?>
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
            <?= reserveit_render_nav($active, $isStaff) ?>
        <?php endif; ?>

        <?php
            $tabBaseParams = $baseQuery;
            $allParams     = array_merge($tabBaseParams, ['view' => 'all']);
            $overdueParams = array_merge($tabBaseParams, ['view' => 'overdue']);
            if ($search !== '') {
                $allParams['q']     = $search;
                $overdueParams['q'] = $search;
            }
            $allUrl     = reserveit_checked_out_url($pageBase, $allParams);
            $overdueUrl = reserveit_checked_out_url($pageBase, $overdueParams);
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
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </form>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
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
                            ?>
                            <tr>
                                <td><?= h($atag) ?></td>
                                <td><?= h($name) ?></td>
                                <td><?= h($model) ?></td>
                                <td><?= h($user) ?></td>
                                <td><?= h(format_display_date($checkedOut)) ?></td>
                                <td class="<?= ($view === 'overdue' ? 'text-danger fw-semibold' : '') ?>">
                                    <?= h(format_display_date($expected)) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
<?php if (!$embedded): ?>
    </div>
</div>
<?php reserveit_footer(); ?>
</body>
</html>
<?php endif; ?>
