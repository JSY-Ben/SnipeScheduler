<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/snipeit_client.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/footer.php';

$config   = require __DIR__ . '/config.php';
$isStaff  = !empty($currentUser['is_admin']);

$bookingOverride = $_SESSION['booking_user_override'] ?? null;
$activeUser      = $bookingOverride ?: $currentUser;

$ldapCfg  = $config['ldap'] ?? [];
$appCfg   = $config['app'] ?? [];
$debugOn  = !empty($appCfg['debug']);

// Staff-only LDAP autocomplete endpoint
if ($isStaff && ($_GET['ajax'] ?? '') === 'ldap_user_search') {
    header('Content-Type: application/json');

    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        if (!empty($ldapCfg['ignore_cert'])) {
            putenv('LDAPTLS_REQCERT=never');
        }

        $ldap = @ldap_connect($ldapCfg['host']);
        if (!$ldap) {
            throw new Exception('Cannot connect to LDAP host');
        }

        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

        if (!@ldap_bind($ldap, $ldapCfg['bind_dn'], $ldapCfg['bind_password'])) {
            throw new Exception('LDAP service bind failed: ' . ldap_error($ldap));
        }

        $filter = sprintf(
            '(|(mail=*%1$s*)(displayName=*%1$s*)(sAMAccountName=*%1$s*))',
            ldap_escape($q, null, LDAP_ESCAPE_FILTER)
        );

        $attrs = ['mail', 'displayName', 'givenName', 'sn', 'sAMAccountName'];
        $search = @ldap_search($ldap, $ldapCfg['base_dn'], $filter, $attrs, 0, 20);
        $entries = $search ? ldap_get_entries($ldap, $search) : ['count' => 0];

        $results = [];
        for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
            $e    = $entries[$i];
            $mail = $e['mail'][0] ?? '';
            $dn   = $e['displayname'][0] ?? '';
            $fn   = $e['givenname'][0] ?? '';
            $ln   = $e['sn'][0] ?? '';
            $name = $dn !== '' ? $dn : trim($fn . ' ' . $ln);
            $sam  = $e['samaccountname'][0] ?? '';

            $results[] = [
                'email' => $mail,
                'name'  => $name !== '' ? $name : $mail,
                'sam'   => $sam,
            ];
        }

        ldap_unbind($ldap);
        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        if (isset($ldap) && $ldap) {
            @ldap_unbind($ldap);
        }
        http_response_code(500);
        echo json_encode(['error' => $debugOn ? $e->getMessage() : 'LDAP error']);
    }
    exit;
}

// Handle staff override selection
if ($isStaff && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'set_booking_user') {
    $revert   = isset($_POST['booking_user_revert']) && $_POST['booking_user_revert'] === '1';
    $selEmail = trim($_POST['booking_user_email'] ?? '');
    $selName  = trim($_POST['booking_user_name'] ?? '');
    if ($revert || $selEmail === '') {
        unset($_SESSION['booking_user_override']);
    } else {
        $_SESSION['booking_user_override'] = [
            'email'      => $selEmail,
            'first_name' => $selName,
            'last_name'  => '',
            'id'         => 0,
        ];
    }
    header('Location: catalogue.php');
    exit;
}

// Active nav + staff flag
$active  = basename($_SERVER['PHP_SELF']);

// ---------------------------------------------------------------------
// Helper: decode Snipe-IT strings safely
// ---------------------------------------------------------------------
function label_safe(?string $str): string
{
    if ($str === null) {
        return '';
    }
    $decoded = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------
// Current basket count (for "View basket (X)")
// ---------------------------------------------------------------------
$basket       = $_SESSION['basket'] ?? [];
$basketCount  = 0;
foreach ($basket as $qty) {
    $basketCount += (int)$qty;
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$searchRaw    = trim($_GET['q'] ?? '');
$categoryRaw  = trim($_GET['category'] ?? '');
$sortRaw      = trim($_GET['sort'] ?? '');
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Normalise filters
$search   = $searchRaw !== '' ? $searchRaw : null;
$category = ctype_digit($categoryRaw) ? (int)$categoryRaw : null;
$sort     = $sortRaw !== '' ? $sortRaw : null;

// Pagination limit (from config constants)
$perPage = defined('CATALOGUE_ITEMS_PER_PAGE')
    ? (int)CATALOGUE_ITEMS_PER_PAGE
    : 12;

// ---------------------------------------------------------------------
// Load categories from Snipe-IT
// ---------------------------------------------------------------------
$categories   = [];
$categoryErr  = '';
try {
    $categories = get_model_categories();
} catch (Throwable $e) {
    $categories  = [];
    $categoryErr = $e->getMessage();
}

// ---------------------------------------------------------------------
// Load models from Snipe-IT
// ---------------------------------------------------------------------
$models        = [];
$modelErr      = '';
$totalModels   = 0;
$totalPages    = 1;
$categoriesUsed = [];
$nowIso        = date('Y-m-d H:i:s');

try {
    $data = get_bookable_models($page, $search ?? '', $category, $sort, $perPage);

    if (isset($data['rows']) && is_array($data['rows'])) {
        $models = $data['rows'];
        foreach ($models as $m) {
            $cid = isset($m['category']['id']) ? (int)$m['category']['id'] : 0;
            if ($cid > 0) {
                $categoriesUsed[$cid] = true;
            }
        }
    }

    if (isset($data['total'])) {
        $totalModels = (int)$data['total'];
    } else {
        $totalModels = count($models);
    }

    if ($perPage > 0) {
        $totalPages = max(1, (int)ceil($totalModels / $perPage));
    } else {
        $totalPages = 1;
    }
} catch (Throwable $e) {
    $models   = [];
    $modelErr = $e->getMessage();
}

// Filter categories to ones that are requestable (if API provides counts) or present in current model set
if (!empty($categories)) {
    $categories = array_values(array_filter($categories, function ($cat) use ($categoriesUsed) {
        $id = isset($cat['id']) ? (int)$cat['id'] : 0;
        if (isset($cat['requestable_count']) && is_numeric($cat['requestable_count'])) {
            if ((int)$cat['requestable_count'] > 0) {
                return true;
            }
        }
        if (!empty($categoriesUsed) && $id > 0) {
            return isset($categoriesUsed[$id]);
        }
        return true;
    }));
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Catalogue – Book Equipment</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= reserveit_logo_tag() ?>
        <div class="page-header">
            <h1>Equipment catalogue</h1>
            <div class="page-subtitle">
                Browse bookable equipment models and add them to your basket.
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
            <?php if ($isStaff): ?>
                <a href="staff_reservations.php"
                   class="app-nav-link <?= $active === 'staff_reservations.php' ? 'active' : '' ?>">Booking History</a>
                <a href="staff_checkout.php"
                   class="app-nav-link <?= $active === 'staff_checkout.php' ? 'active' : '' ?>">Checkout</a>
                <a href="quick_checkout.php"
                   class="app-nav-link <?= $active === 'quick_checkout.php' ? 'active' : '' ?>">Quick Checkout</a>
                <a href="quick_checkin.php"
                   class="app-nav-link <?= $active === 'quick_checkin.php' ? 'active' : '' ?>">Quick Checkin</a>
            <?php endif; ?>
        </nav>

        <!-- Top bar -->
        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= htmlspecialchars(trim($currentUser['first_name'] . ' ' . $currentUser['last_name'])) ?></strong>
                (<?= htmlspecialchars($currentUser['email']) ?>)
            </div>
            <div class="top-bar-actions d-flex gap-2">
                <a href="basket.php"
                   class="btn btn-outline-primary"
                   id="view-basket-btn">
                    View basket<?= $basketCount > 0 ? ' (' . $basketCount . ')' : '' ?>
                </a>
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if ($categoryErr): ?>
            <div class="alert alert-warning">
                Could not load categories from Snipe-IT: <?= htmlspecialchars($categoryErr) ?>
            </div>
        <?php endif; ?>

        <?php if ($modelErr): ?>
            <div class="alert alert-danger">
                Error talking to Snipe-IT (models): <?= htmlspecialchars($modelErr) ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <?php if ($isStaff): ?>
            <div class="alert alert-info d-flex flex-column flex-md-row align-items-md-center justify-content-md-between">
                <div class="mb-2 mb-md-0">
                    <strong>Booking for:</strong>
                    <?= h($activeUser['email'] ?? '') ?>
                    <?php if (!empty($activeUser['first_name'])): ?>
                        (<?= h(trim(($activeUser['first_name'] ?? '') . ' ' . ($activeUser['last_name'] ?? ''))) ?>)
                    <?php endif; ?>
                </div>
                <form method="post" id="booking_user_form" class="d-flex gap-2 mb-0 flex-wrap">
                    <input type="hidden" name="mode" value="set_booking_user">
                    <input type="hidden" name="booking_user_email" id="booking_user_email">
                    <input type="hidden" name="booking_user_name" id="booking_user_name">
                    <div class="position-relative">
                        <input type="text"
                               id="booking_user_input"
                               class="form-control form-control-sm"
                               placeholder="Start typing email or name"
                               autocomplete="off">
                        <div class="list-group position-absolute w-100"
                             id="booking_user_suggestions"
                             style="z-index: 1050; max-height: 220px; overflow-y: auto; display: none;"></div>
                    </div>
                    <button class="btn btn-sm btn-primary" type="submit">Use</button>
                    <button class="btn btn-sm btn-outline-secondary" type="submit" name="booking_user_revert" value="1">Revert to logged in user</button>
                </form>
            </div>
        <?php endif; ?>

        <form class="row g-2 mb-3" method="get" action="catalogue.php">
            <div class="col-md-4">
                <input type="text"
                       name="q"
                       class="form-control"
                       placeholder="Search by name, manufacturer..."
                       value="<?= htmlspecialchars($searchRaw) ?>">
            </div>

            <div class="col-md-3">
                <select name="category" class="form-select">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <?php
                        $cid   = (int)($cat['id'] ?? 0);
                        $cname = $cat['name'] ?? '';
                        ?>
                        <option value="<?= $cid ?>"
                            <?= ($category === $cid) ? 'selected' : '' ?>>
                            <?= label_safe($cname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <select name="sort" class="form-select">
                    <option value="">Sort: Model name (A–Z)</option>
                    <option value="name_asc"   <?= $sort === 'name_asc'   ? 'selected' : '' ?>>Model Name (Ascending)</option>
                    <option value="name_desc"  <?= $sort === 'name_desc'  ? 'selected' : '' ?>>Model Name (Descending)</option>
                    <option value="manu_asc"   <?= $sort === 'manu_asc'   ? 'selected' : '' ?>>Manufacturer (Ascending)</option>
                    <option value="manu_desc"  <?= $sort === 'manu_desc'  ? 'selected' : '' ?>>Manufacturer (Descending)</option>
                    <option value="units_asc"  <?= $sort === 'units_asc'  ? 'selected' : '' ?>>Units in Total (Ascending)</option>
                    <option value="units_desc" <?= $sort === 'units_desc' ? 'selected' : '' ?>>Units in Total (Descending)</option>
                </select>
            </div>

            <div class="col-md-2 d-grid">
                <button class="btn btn-primary" type="submit">Filter</button>
            </div>
        </form>

        <?php if (empty($models) && !$modelErr): ?>
            <div class="alert alert-info">
                No models found. Try adjusting your filters.
            </div>
        <?php endif; ?>

        <?php if (!empty($models)): ?>
            <div class="row g-3">
                <?php foreach ($models as $model): ?>
                    <?php
                    $modelId    = (int)($model['id'] ?? 0);
                    $name       = $model['name'] ?? 'Model';
                    $manuName   = $model['manufacturer']['name'] ?? '';
                    $catName    = $model['category']['name'] ?? '';
                    $imagePath  = $model['image'] ?? '';
                    $assetCount = null;
                    $freeNow     = 0;
                    $maxQty      = 0;
                    $isRequestable = false;
                    try {
                        $assetCount = count_requestable_assets_by_model($modelId);

                        // Active reservations overlapping "now"
                        $stmt = $pdo->prepare("
                            SELECT
                                COALESCE(SUM(CASE WHEN r.status IN ('pending','confirmed') THEN ri.quantity END), 0) AS pending_qty,
                                COALESCE(SUM(CASE WHEN r.status = 'completed' THEN ri.quantity END), 0) AS completed_qty
                            FROM reservation_items ri
                            JOIN reservations r ON r.id = ri.reservation_id
                            WHERE ri.model_id = :mid
                              AND r.status IN ('pending','confirmed','completed')
                              AND r.start_datetime <= :now
                              AND r.end_datetime   > :now
                        ");
                        $stmt->execute([
                            ':mid' => $modelId,
                            ':now' => $nowIso,
                        ]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        $pendingQty   = $row ? (int)$row['pending_qty'] : 0;
                        $completedQty = $row ? (int)$row['completed_qty'] : 0;

                        // How many are actually still checked out in Snipe-IT
                        $activeCheckedOut = count_checked_out_assets_by_model($modelId);
                        $bookedFromCompleted = min($completedQty, $activeCheckedOut);

                        $booked = $pendingQty + $bookedFromCompleted;
                        $freeNow = max(0, $assetCount - $booked);
                        $maxQty = $freeNow;
                        $isRequestable = $assetCount > 0;
                    } catch (Throwable $e) {
                        $assetCount = $assetCount ?? 0;
                        $freeNow    = 0;
                        $maxQty     = 0;
                        $isRequestable = $assetCount > 0;
                    }
                    $notes      = $model['notes'] ?? '';
                    if (is_array($notes)) {
                        $notes = $notes['text'] ?? '';
                    }

                    $proxiedImage = '';
                    if ($imagePath !== '') {
                        $proxiedImage = 'image_proxy.php?src=' . urlencode($imagePath);
                    }
                    ?>
                    <div class="col-md-4">
                        <div class="card h-100 model-card">
                            <?php if ($proxiedImage !== ''): ?>
                                <div class="model-image-wrapper">
                                    <img src="<?= htmlspecialchars($proxiedImage) ?>"
                                         alt=""
                                         class="model-image img-fluid">
                                </div>
                            <?php else: ?>
                                <div class="model-image-wrapper model-image-wrapper--placeholder">
                                    <div class="model-image-placeholder">
                                        No image
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title">
                                    <?= label_safe($name) ?>
                                </h5>
                                <p class="card-text small text-muted mb-2">
                                    <?php if ($manuName): ?>
                                        <span><strong>Manufacturer:</strong> <?= label_safe($manuName) ?></span><br>
                                    <?php endif; ?>
                                    <?php if ($catName): ?>
                                        <span><strong>Category:</strong> <?= label_safe($catName) ?></span><br>
                                    <?php endif; ?>
                                    <?php if ($assetCount !== null): ?>
                                        <span><strong>Requestable units:</strong> <?= $assetCount ?></span><br>
                                    <?php endif; ?>
                                    <span><strong>Available now:</strong> <?= $freeNow ?></span>
                                    <?php if (!empty($notes)): ?>
                                        <div class="mt-2 text-muted clamp-3">
                                            <?= label_safe($notes) ?>
                                        </div>
                                    <?php endif; ?>
                                </p>

                                <form method="post"
                                      action="basket_add.php"
                                      class="mt-auto add-to-basket-form">
                                    <input type="hidden" name="model_id" value="<?= $modelId ?>">

                                    <?php if ($isRequestable): ?>
                                        <div class="row g-2 align-items-center mb-2">
                                            <div class="col-6">
                                                <label class="form-label mb-0 small">Quantity</label>
                                                <input type="number"
                                                       name="quantity"
                                                       class="form-control form-control-sm"
                                                       value="1"
                                                       min="1"
                                                       max="<?= $maxQty ?>">
                                            </div>
                                        </div>

                                        <button type="submit"
                                                class="btn btn-sm btn-success w-100">
                                            Add to basket
                                        </button>
                                    <?php else: ?>
                                        <div class="alert alert-secondary small mb-0">
                                            No requestable units available.
                                        </div>
                                        <button type="button"
                                                class="btn btn-sm btn-secondary w-100 mt-2"
                                                disabled>
                                            Add to basket
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination">
                        <?php
                        $baseQuery = [
                            'q'        => $searchRaw,
                            'category' => $categoryRaw,
                            'sort'     => $sortRaw,
                        ];
                        ?>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php $q = http_build_query(array_merge($baseQuery, ['page' => $p])); ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="catalogue.php?<?= $q ?>">
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- AJAX add-to-basket + update basket count text -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const viewBasketBtn = document.getElementById('view-basket-btn');
    const forms = document.querySelectorAll('.add-to-basket-form');
    const bookingInput = document.getElementById('booking_user_input');
    const bookingList  = document.getElementById('booking_user_suggestions');
    const bookingEmail = document.getElementById('booking_user_email');
    const bookingName  = document.getElementById('booking_user_name');
    let bookingTimer   = null;
    let bookingQuery   = '';

    forms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(function (response) {
                    const ct = response.headers.get('Content-Type') || '';
                    if (ct.indexOf('application/json') !== -1) {
                        return response.json();
                    }
                    return null;
                })
                .then(function (data) {
                    if (!viewBasketBtn) return;

                    if (data && typeof data.basket_count !== 'undefined') {
                        const count = parseInt(data.basket_count, 10) || 0;
                        if (count > 0) {
                            viewBasketBtn.textContent = 'View basket (' + count + ')';
                        } else {
                            viewBasketBtn.textContent = 'View basket';
                        }
                    }
                })
                .catch(function () {
                // Fallback: if AJAX fails for any reason, do normal form submit
                form.submit();
            });
    });

    function hideBookingSuggestions() {
        if (!bookingList) return;
        bookingList.style.display = 'none';
        bookingList.innerHTML = '';
    }

    function renderBookingSuggestions(items) {
        if (!bookingList) return;
        bookingList.innerHTML = '';
        if (!items || !items.length) {
            hideBookingSuggestions();
            return;
        }
        items.forEach(function (item) {
            const email = item.email || '';
            const name = item.name || '';
            const label = (name && email && name !== email) ? (name + ' (' + email + ')') : (name || email);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action';
            btn.textContent = label;
            btn.addEventListener('click', function () {
                bookingInput.value = label;
                bookingEmail.value = email;
                bookingName.value  = name || email;
                hideBookingSuggestions();
            });
            bookingList.appendChild(btn);
        });
        bookingList.style.display = 'block';
    }

    if (bookingInput && bookingList) {
        bookingInput.addEventListener('input', function () {
            const q = bookingInput.value.trim();
            if (q.length < 2) {
                hideBookingSuggestions();
                return;
            }
            if (bookingTimer) clearTimeout(bookingTimer);
            bookingTimer = setTimeout(function () {
                bookingQuery = q;
                fetch('catalogue.php?ajax=ldap_user_search&q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (res) { return res.ok ? res.json() : null; })
                    .then(function (data) {
                        if (bookingQuery !== q) return;
                        renderBookingSuggestions(data && data.results ? data.results : []);
                    })
                    .catch(function () {
                        hideBookingSuggestions();
                    });
            }, 250);
        });

        bookingInput.addEventListener('blur', function () {
            setTimeout(hideBookingSuggestions, 150);
        });
    }
});

function clearBookingUser() {
    const email = document.getElementById('booking_user_email');
    const name  = document.getElementById('booking_user_name');
    const input = document.getElementById('booking_user_input');
    if (email) email.value = '';
    if (name) name.value = '';
    if (input) input.value = '';
}

function revertToLoggedIn(e) {
    if (e) e.preventDefault();
    const email = document.getElementById('booking_user_email');
    const name  = document.getElementById('booking_user_name');
    const input = document.getElementById('booking_user_input');
    const form  = document.getElementById('booking_user_form');
    if (email) email.value = '';
    if (name) name.value = '';
    if (input) input.value = '';
    // Submit form via hidden revert button to mirror normal submit
    const revertBtn = document.querySelector('button[name="booking_user_revert"]');
    if (revertBtn) {
        revertBtn.click();
    } else if (form) {
        form.submit();
    }
}
});
</script>
<?php reserveit_footer(); ?>
</body>
</html>
