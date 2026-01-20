<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/db.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$messages = [];
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_category') {
        $categoryEditId = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['category_name'] ?? '');
        $description = trim($_POST['category_description'] ?? '');

        if ($name === '') {
            $errors[] = 'Category name is required.';
        }

        if (!$errors) {
            try {
                if ($categoryEditId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE asset_categories
                           SET name = :name,
                               description = :description
                         WHERE id = :id
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
                        ':id' => $categoryEditId,
                    ]);
                    $messages[] = 'Category updated.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO asset_categories (name, description, created_at)
                        VALUES (:name, :description, NOW())
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
                    ]);
                    $messages[] = 'Category created.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Category save failed: ' . $e->getMessage();
            }
        }
    }
}

$categories = [];

try {
    $categories = $pdo->query('SELECT id, name, description FROM asset_categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = 'Category lookup failed: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Categories</title>
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
            <h1>Categories</h1>
            <div class="page-subtitle">
                Manage inventory categories.
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

        <?php if ($messages): ?>
            <div class="alert alert-success">
                <?= implode('<br>', array_map('h', $messages)) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?= implode('<br>', array_map('h', $errors)) ?>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link active" href="inventory_admin.php">Inventory</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="activity_log.php">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="settings.php">Settings</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="users.php">Users</a>
            </li>
        </ul>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title mb-1">Create category</h5>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="save_category">
                    <input type="hidden" name="category_id" value="0">
                    <div class="col-md-4">
                        <label class="form-label">Category name</label>
                        <input type="text" name="category_name" class="form-control" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Description</label>
                        <input type="text" name="category_description" class="form-control">
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn btn-primary">Create category</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-1">Categories</h5>
                <p class="text-muted small mb-3"><?= count($categories) ?> total.</p>
                <?php if (empty($categories)): ?>
                    <div class="text-muted small">No categories found yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $category): ?>
                                    <tr>
                                        <form method="post">
                                            <input type="hidden" name="action" value="save_category">
                                            <input type="hidden" name="category_id" value="<?= (int)($category['id'] ?? 0) ?>">
                                            <td>
                                                <input type="text" name="category_name" class="form-control form-control-sm" value="<?= h($category['name'] ?? '') ?>" required>
                                            </td>
                                            <td>
                                                <input type="text" name="category_description" class="form-control form-control-sm" value="<?= h($category['description'] ?? '') ?>">
                                            </td>
                                            <td class="text-end">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">Update</button>
                                            </td>
                                        </form>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
