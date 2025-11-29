<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/snipeit_client.php';
require_once __DIR__ . '/db.php';

$config = require __DIR__ . '/config.php';

// Active nav + staff flag
$active  = basename($_SERVER['PHP_SELF']);
$isStaff = !empty($currentUser['is_admin']);

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

try {
    $data = get_bookable_models($page, $search ?? '', $category, $sort, $perPage);

    if (isset($data['rows']) && is_array($data['rows'])) {
        $models = $data['rows'];
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
                   class="app-nav-link <?= $active === 'staff_reservations.php' ? 'active' : '' ?>">Admin</a>
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
                    $assetCount = isset($model['assets_count']) ? (int)$model['assets_count'] : null;
                    $maxQty     = ($assetCount && $assetCount > 0) ? $assetCount : 10;

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
                                        <span><strong>Units in total:</strong> <?= $assetCount ?></span>
                                    <?php endif; ?>
                                </p>

                                <form method="post"
                                      action="basket_add.php"
                                      class="mt-auto add-to-basket-form">
                                    <input type="hidden" name="model_id" value="<?= $modelId ?>">

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
    });
});
</script>
</body>
</html>
