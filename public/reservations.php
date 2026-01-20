<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$allowedTabs = ['today', 'checked_out', 'checkin', 'history'];
$tab         = $_GET['tab'] ?? 'today';
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'today';
}

$tabMap = [
    'today'       => __DIR__ . '/staff_checkout.php',
    'checked_out' => __DIR__ . '/checked_out_assets.php',
    'checkin'     => __DIR__ . '/checkin_reservations.php',
    'history'     => __DIR__ . '/staff_reservations.php',
];

if (!defined('RESERVATIONS_EMBED')) {
    define('RESERVATIONS_EMBED', true);
}

$tabFile = $tabMap[$tab] ?? null;
if (!$tabFile || !is_file($tabFile)) {
    $tabContent = '<div class="alert alert-danger mb-0">Tab content unavailable.</div>';
} else {
    ob_start();
    include $tabFile;
    $tabContent = ob_get_clean();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservations</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
    <style>
        /* Make reservations tabs more prominent */
        .reservations-tabs {
            border-bottom: 3px solid var(--primary-strong);
            gap: 0.25rem;
        }
        .reservations-tabs .nav-link {
            border: 1px solid transparent;
            color: var(--primary-strong);
            font-weight: 600;
            padding: 0.9rem 1.2rem;
            border-radius: 0.5rem 0.5rem 0 0;
            background: linear-gradient(180deg, rgba(var(--primary-soft-rgb),0.18), rgba(255,255,255,0));
            transition: all 120ms ease;
        }
        .reservations-tabs .nav-link:hover {
            color: var(--primary);
            background: linear-gradient(180deg, rgba(var(--primary-soft-rgb),0.36), rgba(255,255,255,0.08));
            border-color: rgba(var(--primary-rgb),0.25);
        }
        .reservations-tabs .nav-link.active {
            color: #fff;
            background: linear-gradient(135deg, var(--primary), var(--primary-strong));
            border-color: var(--primary-strong) var(--primary-strong) #fff;
            box-shadow: 0 8px 18px rgba(var(--primary-rgb), 0.22);
        }
        .reservations-shell {
            background: #fff;
            border: 1px solid rgba(0,0,0,0.05);
            border-radius: 0 0 0.75rem 0.75rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.05);
        }
    </style>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Reservations</h1>
            <div class="page-subtitle">
                Manage reservation history, today’s checkouts, and checked-out assets from one place.
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
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <ul class="nav nav-tabs reservations-tabs">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'today' ? 'active' : '' ?>"
                   href="reservations.php?tab=today">Today’s Reservations (Checkout)</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'checked_out' ? 'active' : '' ?>"
                   href="reservations.php?tab=checked_out">Checked out Assets</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'checkin' ? 'active' : '' ?>"
                   href="reservations.php?tab=checkin">Check In Reservations</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>"
                   href="reservations.php?tab=history">Reservation History</a>
            </li>
        </ul>

        <div class="tab-content border border-top-0 p-3 bg-white reservations-shell">
            <?= $tabContent ?>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
