<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$isAuthenticated = isset($isAuthenticated) ? (bool)$isAuthenticated : !empty($currentUser['email']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Equipment Booking – Dashboard</title>
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
            <h1>Equipment Booking</h1>
            <div class="page-subtitle">
                Browse bookable equipment, manage your basket, and review your bookings.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin, $isAuthenticated) ?>

        <div class="top-bar mb-3">
            <?php if ($isAuthenticated): ?>
                <div class="top-bar-user">
                    Logged in as:
                    <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                    (<?= h($currentUser['email'] ?? '') ?>)
                </div>
                <div class="top-bar-actions">
                    <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
                </div>
            <?php else: ?>
                <div class="top-bar-user">
                    Browsing as <strong>Guest</strong>. Sign in to add items to your basket and place reservations.
                </div>
                <div class="top-bar-actions">
                    <a href="login.php" class="btn btn-primary btn-sm">Log in</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">Browse equipment</h5>
                        <p class="card-text">
                            View the catalogue of equipment models available for users to book.
                            <?= $isAuthenticated
                                ? 'Add items to your basket and request them for specific dates.'
                                : 'You can browse publicly; sign in when ready to add items to your basket.' ?>
                        </p>
                        <a href="catalogue.php" class="btn btn-primary mt-auto">
                            Go to catalogue
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($isAuthenticated): ?>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">My Reservations</h5>
                            <p class="card-text">
                                See all of your upcoming and past reservations, including which models you
                                requested, and cancel future bookings where allowed.
                            </p>
                            <a href="my_bookings.php" class="btn btn-outline-primary mt-auto">
                                View my reservations
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">Sign in to book</h5>
                            <p class="card-text">
                                Adding items to basket, viewing basket, and creating reservations require a logged-in account.
                            </p>
                            <a href="login.php" class="btn btn-outline-primary mt-auto">
                                Go to login
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($isStaff): ?>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">Reservations</h5>
                            <p class="card-text">
                                Review reservation history, process today’s checkouts, and view checked-out assets.
                            </p>
                            <a href="reservations.php" class="btn btn-outline-primary mt-auto">
                                Go to reservations
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">Quick Checkout</h5>
                            <p class="card-text">
                                Perform ad-hoc bulk checkouts via Snipe-IT without selecting a reservation.
                            </p>
                            <a href="quick_checkout.php" class="btn btn-outline-primary mt-auto">
                                Go to quick checkout
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">Quick Checkin</h5>
                            <p class="card-text">
                                Scan asset tags to check items back in via Snipe-IT (quick scan style).
                            </p>
                            <a href="quick_checkin.php" class="btn btn-outline-primary mt-auto">
                                Go to quick checkin
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-4">
            <div class="alert alert-secondary mb-0">
                Need help or something is missing from the catalogue? Please contact staff.
            </div>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
