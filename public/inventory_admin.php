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

$categoryEditId = (int)($_GET['category_edit'] ?? 0);
$modelEditId = (int)($_GET['model_edit'] ?? 0);
$assetEditId = (int)($_GET['asset_edit'] ?? 0);

$statusOptions = ['available', 'checked_out', 'maintenance', 'retired'];

$uploadDirRelative = 'uploads/images';
$uploadDir = APP_ROOT . '/public/' . $uploadDirRelative;
$uploadBaseUrl = $uploadDirRelative . '/';

if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    $errors[] = 'Upload directory could not be created. Check permissions for public/uploads/images.';
}

$handleUpload = static function (string $field) use ($uploadDir, $uploadBaseUrl): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $file = $_FILES[$field];
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Image upload failed.');
    }
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new Exception('Could not create upload directory.');
    }

    $original = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext);
    if ($ext === '') {
        $ext = 'bin';
    }
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    $target = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new Exception('Invalid upload.');
    }
    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception('Could not save uploaded file.');
    }
    if (!is_file($target)) {
        throw new Exception('Upload did not persist on disk.');
    }

    return $uploadBaseUrl . $filename;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_model') {
        $modelEditId = (int)($_POST['model_id'] ?? 0);
        $name = trim($_POST['model_name'] ?? '');
        $manufacturer = trim($_POST['model_manufacturer'] ?? '');
        $categoryRaw = trim($_POST['model_category_id'] ?? '');
        $categoryId = $categoryRaw === '' ? null : (int)$categoryRaw;
        $notes = trim($_POST['model_notes'] ?? '');
        $imageUrl = trim($_POST['model_image_url'] ?? '');
        $uploadedModelImage = null;

        try {
            $uploadedModelImage = $handleUpload('model_image_upload');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if ($name === '') {
            $errors[] = 'Model name is required.';
        }

        if (!$errors) {
            try {
                $existingImageUrl = null;
                if ($modelEditId > 0) {
                    $stmt = $pdo->prepare('SELECT image_url FROM asset_models WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $modelEditId]);
                    $existingImageUrl = $stmt->fetchColumn() ?: null;
                }
                $finalImageUrl = $imageUrl !== '' ? $imageUrl : $existingImageUrl;
                if ($uploadedModelImage && ($imageUrl === '' || $imageUrl === $existingImageUrl)) {
                    $finalImageUrl = $uploadedModelImage;
                }

                if ($modelEditId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE asset_models
                           SET name = :name,
                               manufacturer = :manufacturer,
                               category_id = :category_id,
                               notes = :notes,
                               image_url = :image_url
                         WHERE id = :id
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':manufacturer' => $manufacturer !== '' ? $manufacturer : null,
                        ':category_id' => $categoryId ?: null,
                        ':notes' => $notes !== '' ? $notes : null,
                        ':image_url' => $finalImageUrl,
                        ':id' => $modelEditId,
                    ]);
                    $messages[] = 'Model updated.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO asset_models (name, manufacturer, category_id, notes, image_url, created_at)
                        VALUES (:name, :manufacturer, :category_id, :notes, :image_url, NOW())
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':manufacturer' => $manufacturer !== '' ? $manufacturer : null,
                        ':category_id' => $categoryId ?: null,
                        ':notes' => $notes !== '' ? $notes : null,
                        ':image_url' => $finalImageUrl,
                    ]);
                    $modelEditId = (int)$pdo->lastInsertId();
                    $messages[] = 'Model created.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Model save failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'save_asset') {
        $assetEditId = (int)($_POST['asset_id'] ?? 0);
        $assetTag = trim($_POST['asset_tag'] ?? '');
        $assetName = trim($_POST['asset_name'] ?? '');
        $modelId = (int)($_POST['asset_model_id'] ?? 0);
        $status = $_POST['asset_status'] ?? 'available';
        $requestable = isset($_POST['asset_requestable']) ? 1 : 0;
        $imageUrl = trim($_POST['asset_image_url'] ?? '');
        $uploadedAssetImage = null;

        try {
            $uploadedAssetImage = $handleUpload('asset_image_upload');
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        if ($assetTag === '') {
            $errors[] = 'Asset tag is required.';
        }
        if ($assetName === '') {
            $errors[] = 'Asset name is required.';
        }
        if ($modelId <= 0) {
            $errors[] = 'Model is required.';
        }
        if (!in_array($status, $statusOptions, true)) {
            $errors[] = 'Asset status is invalid.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare('SELECT id FROM assets WHERE asset_tag = :tag AND id <> :id LIMIT 1');
                $stmt->execute([
                    ':tag' => $assetTag,
                    ':id' => $assetEditId,
                ]);
                if ($stmt->fetch()) {
                    throw new Exception('Asset tag is already in use.');
                }

                $stmt = $pdo->prepare('SELECT id FROM asset_models WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $modelId]);
                if (!$stmt->fetch()) {
                    throw new Exception('Selected model does not exist.');
                }

                $existingImageUrl = null;
                if ($assetEditId > 0) {
                    $stmt = $pdo->prepare('SELECT image_url FROM assets WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $assetEditId]);
                    $existingImageUrl = $stmt->fetchColumn() ?: null;
                }
                $finalImageUrl = $imageUrl !== '' ? $imageUrl : $existingImageUrl;
                if ($uploadedAssetImage && ($imageUrl === '' || $imageUrl === $existingImageUrl)) {
                    $finalImageUrl = $uploadedAssetImage;
                }

                if ($assetEditId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE assets
                           SET asset_tag = :asset_tag,
                               name = :name,
                               model_id = :model_id,
                               status = :status,
                               requestable = :requestable,
                               image_url = :image_url
                         WHERE id = :id
                    ");
                    $stmt->execute([
                        ':asset_tag' => $assetTag,
                        ':name' => $assetName,
                        ':model_id' => $modelId,
                        ':status' => $status,
                        ':requestable' => $requestable,
                        ':image_url' => $finalImageUrl,
                        ':id' => $assetEditId,
                    ]);
                    $messages[] = 'Asset updated.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO assets (asset_tag, name, model_id, status, requestable, image_url, created_at)
                        VALUES (:asset_tag, :name, :model_id, :status, :requestable, :image_url, NOW())
                    ");
                    $stmt->execute([
                        ':asset_tag' => $assetTag,
                        ':name' => $assetName,
                        ':model_id' => $modelId,
                        ':status' => $status,
                        ':requestable' => $requestable,
                        ':image_url' => $finalImageUrl,
                    ]);
                    $assetEditId = (int)$pdo->lastInsertId();
                    $messages[] = 'Asset created.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Asset save failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'save_category') {
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
                    $categoryEditId = (int)$pdo->lastInsertId();
                    $messages[] = 'Category created.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Category save failed: ' . $e->getMessage();
            }
        }
    }
}

$categories = [];
$models = [];
$assets = [];
$editCategory = null;
$editModel = null;
$editAsset = null;

try {
    $categories = $pdo->query('SELECT id, name, description FROM asset_categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $models = $pdo->query('
        SELECT m.id, m.name, m.manufacturer, m.category_id, m.notes, m.image_url, c.name AS category_name
          FROM asset_models m
          LEFT JOIN asset_categories c ON c.id = m.category_id
         ORDER BY m.name ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $assets = $pdo->query('
        SELECT a.id, a.asset_tag, a.name, a.model_id, a.status, a.requestable, a.image_url, a.created_at, m.name AS model_name
          FROM assets a
          JOIN asset_models m ON m.id = a.model_id
         ORDER BY a.asset_tag ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = 'Inventory lookup failed: ' . $e->getMessage();
}

if ($categoryEditId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM asset_categories WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $categoryEditId]);
    $editCategory = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editCategory) {
        $errors[] = 'Category not found.';
    }
}

if ($modelEditId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM asset_models WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $modelEditId]);
    $editModel = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editModel) {
        $errors[] = 'Model not found.';
    }
}

if ($assetEditId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM assets WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $assetEditId]);
    $editAsset = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editAsset) {
        $errors[] = 'Asset not found.';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Inventory</title>
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
            <h1>Inventory</h1>
            <div class="page-subtitle">
                Manage models and assets in the local inventory.
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
                <a class="nav-link" href="activity_log.php">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="settings.php">Settings</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="users.php">Users</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="inventory_admin.php">Inventory</a>
            </li>
        </ul>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title mb-1"><?= $editCategory ? 'Edit category' : 'Create category' ?></h5>
                <form method="post" class="row g-3" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_category">
                    <input type="hidden" name="category_id" value="<?= (int)($editCategory['id'] ?? 0) ?>">
                    <div class="col-md-4">
                        <label class="form-label">Category name</label>
                        <input type="text" name="category_name" class="form-control" value="<?= h($editCategory['name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Description</label>
                        <input type="text" name="category_description" class="form-control" value="<?= h($editCategory['description'] ?? '') ?>">
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <?php if ($editCategory): ?>
                            <a href="inventory_admin.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary"><?= $editCategory ? 'Update category' : 'Create category' ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title mb-1"><?= $editModel ? 'Edit model' : 'Create model' ?></h5>
                <form method="post" class="row g-3" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_model">
                    <input type="hidden" name="model_id" value="<?= (int)($editModel['id'] ?? 0) ?>">
                    <div class="col-md-4">
                        <label class="form-label">Model name</label>
                        <input type="text" name="model_name" class="form-control" value="<?= h($editModel['name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Manufacturer</label>
                        <input type="text" name="model_manufacturer" class="form-control" value="<?= h($editModel['manufacturer'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Category</label>
                        <select name="model_category_id" class="form-select">
                            <option value="">Unassigned</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int)$category['id'] ?>" <?= (int)($editModel['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>>
                                    <?= h($category['name'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Notes</label>
                        <textarea name="model_notes" class="form-control" rows="2"><?= h($editModel['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Image URL</label>
                        <input type="text" name="model_image_url" class="form-control" value="<?= h($editModel['image_url'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Upload image</label>
                        <input type="file" name="model_image_upload" class="form-control">
                        <div class="form-text">Upload replaces the stored image unless a URL is provided.</div>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <?php if ($editModel): ?>
                            <a href="inventory_admin.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary"><?= $editModel ? 'Update model' : 'Create model' ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title mb-1"><?= $editAsset ? 'Edit asset' : 'Create asset' ?></h5>
                <form method="post" class="row g-3" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_asset">
                    <input type="hidden" name="asset_id" value="<?= (int)($editAsset['id'] ?? 0) ?>">
                    <div class="col-md-3">
                        <label class="form-label">Asset tag</label>
                        <input type="text" name="asset_tag" class="form-control" value="<?= h($editAsset['asset_tag'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Asset name</label>
                        <input type="text" name="asset_name" class="form-control" value="<?= h($editAsset['name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Model</label>
                        <select name="asset_model_id" class="form-select" required>
                            <option value="">Select model</option>
                            <?php foreach ($models as $model): ?>
                                <option value="<?= (int)$model['id'] ?>" <?= (int)($editAsset['model_id'] ?? 0) === (int)$model['id'] ? 'selected' : '' ?>>
                                    <?= h($model['name'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="asset_status" class="form-select">
                            <?php foreach ($statusOptions as $opt): ?>
                                <option value="<?= h($opt) ?>" <?= ($editAsset['status'] ?? 'available') === $opt ? 'selected' : '' ?>>
                                    <?= h(ucwords(str_replace('_', ' ', $opt))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Image URL</label>
                        <input type="text" name="asset_image_url" class="form-control" value="<?= h($editAsset['image_url'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Upload image</label>
                        <input type="file" name="asset_image_upload" class="form-control">
                        <div class="form-text">Upload replaces the stored image unless a URL is provided.</div>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="asset_requestable" id="asset_requestable" <?= !empty($editAsset['requestable']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="asset_requestable">Requestable</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <?php if ($editAsset): ?>
                            <a href="inventory_admin.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary"><?= $editAsset ? 'Update asset' : 'Create asset' ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
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
                                        <td><?= h($category['name'] ?? '') ?></td>
                                        <td><?= h($category['description'] ?? '') ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-secondary" href="inventory_admin.php?category_edit=<?= (int)$category['id'] ?>">Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title mb-1">Models</h5>
                <p class="text-muted small mb-3"><?= count($models) ?> total.</p>
                <?php if (empty($models)): ?>
                    <div class="text-muted small">No models found yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Manufacturer</th>
                                    <th>Category</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($models as $model): ?>
                                    <tr>
                                        <td><?= h($model['name'] ?? '') ?></td>
                                        <td><?= h($model['manufacturer'] ?? '') ?></td>
                                        <td><?= h($model['category_name'] ?? 'Unassigned') ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-secondary" href="inventory_admin.php?model_edit=<?= (int)$model['id'] ?>">Edit</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-1">Assets</h5>
                <p class="text-muted small mb-3"><?= count($assets) ?> total.</p>
                <?php if (empty($assets)): ?>
                    <div class="text-muted small">No assets found yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Tag</th>
                                    <th>Name</th>
                                    <th>Model</th>
                                    <th>Status</th>
                                    <th>Requestable</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assets as $asset): ?>
                                    <tr>
                                        <td><?= h($asset['asset_tag'] ?? '') ?></td>
                                        <td><?= h($asset['name'] ?? '') ?></td>
                                        <td><?= h($asset['model_name'] ?? '') ?></td>
                                        <td><?= h(ucwords(str_replace('_', ' ', $asset['status'] ?? 'available'))) ?></td>
                                        <td><?= !empty($asset['requestable']) ? 'Yes' : 'No' ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-secondary" href="inventory_admin.php?asset_edit=<?= (int)$asset['id'] ?>">Edit</a>
                                        </td>
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
