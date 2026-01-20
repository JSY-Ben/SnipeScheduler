<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/db.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$sectionRaw = $_GET['section'] ?? $_POST['section'] ?? 'inventory';
$section = in_array($sectionRaw, ['categories', 'models', 'inventory'], true) ? $sectionRaw : 'inventory';

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$messages = [];
$errors   = [];

$modelEditId = (int)($_GET['model_edit'] ?? 0);
$assetEditId = 0;

$statusOptions = ['available', 'checked_out', 'maintenance', 'retired'];

$uploadDirRelative = 'uploads/images';
$uploadDir = APP_ROOT . '/public/' . $uploadDirRelative;
$uploadBaseUrl = $uploadDirRelative . '/';

if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    $errors[] = 'Upload directory could not be created. Check permissions for public/uploads/images.';
}

$exportType = $_GET['export'] ?? '';
if (in_array($exportType, ['categories', 'models', 'assets'], true)) {
    $filenameMap = [
        'categories' => 'categories.csv',
        'models' => 'models.csv',
        'assets' => 'assets.csv',
    ];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filenameMap[$exportType] . '"');
    $out = fopen('php://output', 'w');
    if ($exportType === 'categories') {
        fputcsv($out, ['id', 'name', 'description']);
        $rows = $pdo->query('SELECT id, name, description FROM asset_categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            fputcsv($out, [
                (int)$row['id'],
                $row['name'] ?? '',
                $row['description'] ?? '',
            ]);
        }
    } elseif ($exportType === 'models') {
        fputcsv($out, ['id', 'name', 'manufacturer', 'category_id', 'category_name', 'notes', 'image_url']);
        $rows = $pdo->query('
            SELECT m.id, m.name, m.manufacturer, m.category_id, m.notes, m.image_url, c.name AS category_name
              FROM asset_models m
              LEFT JOIN asset_categories c ON c.id = m.category_id
             ORDER BY m.name ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            fputcsv($out, [
                (int)$row['id'],
                $row['name'] ?? '',
                $row['manufacturer'] ?? '',
                (int)($row['category_id'] ?? 0),
                $row['category_name'] ?? '',
                $row['notes'] ?? '',
                $row['image_url'] ?? '',
            ]);
        }
    } elseif ($exportType === 'assets') {
        fputcsv($out, ['id', 'asset_tag', 'name', 'model_id', 'model_name', 'status']);
        $rows = $pdo->query('
            SELECT a.id, a.asset_tag, a.name, a.model_id, a.status, m.name AS model_name
              FROM assets a
              JOIN asset_models m ON m.id = a.model_id
             ORDER BY a.asset_tag ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            fputcsv($out, [
                (int)$row['id'],
                $row['asset_tag'] ?? '',
                $row['name'] ?? '',
                (int)($row['model_id'] ?? 0),
                $row['model_name'] ?? '',
                $row['status'] ?? '',
            ]);
        }
    }
    fclose($out);
    exit;
}

$templateType = $_GET['template'] ?? '';
if (in_array($templateType, ['categories', 'models', 'assets'], true)) {
    $templateMap = [
        'categories' => APP_ROOT . '/templates/csv/categories_template.csv',
        'models' => APP_ROOT . '/templates/csv/models_template.csv',
        'assets' => APP_ROOT . '/templates/csv/assets_template.csv',
    ];
    $path = $templateMap[$templateType];
    if (is_file($path)) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }
    http_response_code(404);
    echo 'Template not found.';
    exit;
}

$readCsvUpload = static function (string $field, array &$errors): array {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        $errors[] = 'CSV upload is required.';
        return [];
    }
    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'CSV upload failed.';
        return [];
    }
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        $errors[] = 'Could not read uploaded CSV.';
        return [];
    }
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        $errors[] = 'CSV header row is missing.';
        return [];
    }
    $header = array_map(static function ($value) {
        $value = trim((string)$value);
        return strtolower(preg_replace('/^\xEF\xBB\xBF/', '', $value));
    }, $header);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if ($row === [null] || $row === false) {
            continue;
        }
        $row = array_pad($row, count($header), '');
        $rows[] = array_combine($header, $row);
    }
    fclose($handle);
    return $rows;
};

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

                if ($assetEditId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE assets
                           SET asset_tag = :asset_tag,
                               name = :name,
                               model_id = :model_id,
                               status = :status
                         WHERE id = :id
                    ");
                    $stmt->execute([
                        ':asset_tag' => $assetTag,
                        ':name' => $assetName,
                        ':model_id' => $modelId,
                        ':status' => $status,
                        ':id' => $assetEditId,
                    ]);
                    $messages[] = 'Asset updated.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO assets (asset_tag, name, model_id, status, created_at)
                        VALUES (:asset_tag, :name, :model_id, :status, NOW())
                    ");
                    $stmt->execute([
                        ':asset_tag' => $assetTag,
                        ':name' => $assetName,
                        ':model_id' => $modelId,
                        ':status' => $status,
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
                    $messages[] = 'Category created.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Category save failed: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'import_categories') {
        $rows = $readCsvUpload('categories_csv', $errors);
        if ($rows && !$errors) {
            $imported = 0;
            $rowErrors = [];
            foreach ($rows as $idx => $row) {
                $name = trim($row['name'] ?? '');
                $description = trim($row['description'] ?? '');
                if ($name === '') {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': name is required.';
                    continue;
                }
                try {
                    $stmt = $pdo->prepare('SELECT id FROM asset_categories WHERE name = :name LIMIT 1');
                    $stmt->execute([':name' => $name]);
                    $existingId = (int)$stmt->fetchColumn();
                    if ($existingId > 0) {
                        $stmt = $pdo->prepare('UPDATE asset_categories SET description = :description WHERE id = :id');
                        $stmt->execute([
                            ':description' => $description !== '' ? $description : null,
                            ':id' => $existingId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO asset_categories (name, description, created_at) VALUES (:name, :description, NOW())');
                        $stmt->execute([
                            ':name' => $name,
                            ':description' => $description !== '' ? $description : null,
                        ]);
                    }
                    $imported++;
                } catch (Throwable $e) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': ' . $e->getMessage();
                }
            }
            if ($rowErrors) {
                $errors[] = 'Category import completed with errors: ' . implode(' | ', array_slice($rowErrors, 0, 5));
            }
            if ($imported > 0) {
                $messages[] = 'Categories imported: ' . $imported . '.';
            }
        }
    } elseif ($action === 'import_models') {
        $rows = $readCsvUpload('models_csv', $errors);
        if ($rows && !$errors) {
            $imported = 0;
            $rowErrors = [];
            foreach ($rows as $idx => $row) {
                $name = trim($row['name'] ?? '');
                $manufacturer = trim($row['manufacturer'] ?? '');
                $notes = trim($row['notes'] ?? '');
                $imageUrl = trim($row['image_url'] ?? '');
                $categoryIdRaw = trim($row['category_id'] ?? '');
                $categoryName = trim($row['category_name'] ?? '');
                $modelIdRaw = trim($row['id'] ?? '');
                if ($name === '') {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': name is required.';
                    continue;
                }
                $categoryId = $categoryIdRaw !== '' ? (int)$categoryIdRaw : 0;
                if ($categoryId <= 0 && $categoryName !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM asset_categories WHERE name = :name LIMIT 1');
                    $stmt->execute([':name' => $categoryName]);
                    $categoryId = (int)$stmt->fetchColumn();
                    if ($categoryId <= 0) {
                        $rowErrors[] = 'Row ' . ($idx + 2) . ': category "' . $categoryName . '" not found.';
                        continue;
                    }
                }
                try {
                    $modelId = $modelIdRaw !== '' ? (int)$modelIdRaw : 0;
                    if ($modelId > 0) {
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
                            ':category_id' => $categoryId > 0 ? $categoryId : null,
                            ':notes' => $notes !== '' ? $notes : null,
                            ':image_url' => $imageUrl !== '' ? $imageUrl : null,
                            ':id' => $modelId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO asset_models (name, manufacturer, category_id, notes, image_url, created_at)
                            VALUES (:name, :manufacturer, :category_id, :notes, :image_url, NOW())
                        ");
                        $stmt->execute([
                            ':name' => $name,
                            ':manufacturer' => $manufacturer !== '' ? $manufacturer : null,
                            ':category_id' => $categoryId > 0 ? $categoryId : null,
                            ':notes' => $notes !== '' ? $notes : null,
                            ':image_url' => $imageUrl !== '' ? $imageUrl : null,
                        ]);
                    }
                    $imported++;
                } catch (Throwable $e) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': ' . $e->getMessage();
                }
            }
            if ($rowErrors) {
                $errors[] = 'Model import completed with errors: ' . implode(' | ', array_slice($rowErrors, 0, 5));
            }
            if ($imported > 0) {
                $messages[] = 'Models imported: ' . $imported . '.';
            }
        }
    } elseif ($action === 'import_assets') {
        $rows = $readCsvUpload('assets_csv', $errors);
        if ($rows && !$errors) {
            $imported = 0;
            $rowErrors = [];
            foreach ($rows as $idx => $row) {
                $assetTag = trim($row['asset_tag'] ?? '');
                $name = trim($row['name'] ?? '');
                $status = trim($row['status'] ?? '');
                if ($status === '') {
                    $status = 'available';
                }
                $modelIdRaw = trim($row['model_id'] ?? '');
                $modelName = trim($row['model_name'] ?? '');
                if ($assetTag === '') {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': asset_tag is required.';
                    continue;
                }
                if (!in_array($status, $statusOptions, true)) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': invalid status.';
                    continue;
                }
                $modelId = $modelIdRaw !== '' ? (int)$modelIdRaw : 0;
                if ($modelId <= 0 && $modelName !== '') {
                    $stmt = $pdo->prepare('SELECT id FROM asset_models WHERE name = :name LIMIT 1');
                    $stmt->execute([':name' => $modelName]);
                    $modelId = (int)$stmt->fetchColumn();
                }
                if ($modelId <= 0) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': model not found.';
                    continue;
                }
                try {
                    $stmt = $pdo->prepare('SELECT id FROM assets WHERE asset_tag = :tag LIMIT 1');
                    $stmt->execute([':tag' => $assetTag]);
                    $existingId = (int)$stmt->fetchColumn();
                    if ($existingId > 0) {
                        if ($name === '') {
                            $stmt = $pdo->prepare('SELECT name FROM assets WHERE id = :id LIMIT 1');
                            $stmt->execute([':id' => $existingId]);
                            $name = (string)$stmt->fetchColumn();
                        }
                        $stmt = $pdo->prepare("
                            UPDATE assets
                               SET name = :name,
                                   model_id = :model_id,
                                   status = :status
                             WHERE id = :id
                        ");
                        $stmt->execute([
                            ':name' => $name,
                            ':model_id' => $modelId,
                            ':status' => $status,
                            ':id' => $existingId,
                        ]);
                    } else {
                        if ($name === '') {
                            $rowErrors[] = 'Row ' . ($idx + 2) . ': name is required for new assets.';
                            continue;
                        }
                        $stmt = $pdo->prepare("
                            INSERT INTO assets (asset_tag, name, model_id, status, created_at)
                            VALUES (:asset_tag, :name, :model_id, :status, NOW())
                        ");
                        $stmt->execute([
                            ':asset_tag' => $assetTag,
                            ':name' => $name,
                            ':model_id' => $modelId,
                            ':status' => $status,
                        ]);
                    }
                    $imported++;
                } catch (Throwable $e) {
                    $rowErrors[] = 'Row ' . ($idx + 2) . ': ' . $e->getMessage();
                }
            }
            if ($rowErrors) {
                $errors[] = 'Asset import completed with errors: ' . implode(' | ', array_slice($rowErrors, 0, 5));
            }
            if ($imported > 0) {
                $messages[] = 'Assets imported: ' . $imported . '.';
            }
        }
    }
}

$categories = [];
$models = [];
$assets = [];
$editModel = null;

try {
    $categories = $pdo->query('SELECT id, name, description FROM asset_categories ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $models = $pdo->query('
        SELECT m.id, m.name, m.manufacturer, m.category_id, m.notes, m.image_url, c.name AS category_name
          FROM asset_models m
          LEFT JOIN asset_categories c ON c.id = m.category_id
         ORDER BY m.name ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $assets = $pdo->query('
        SELECT a.id, a.asset_tag, a.name, a.model_id, a.status, a.created_at, m.name AS model_name
          FROM assets a
          JOIN asset_models m ON m.id = a.model_id
         ORDER BY a.asset_tag ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = 'Inventory lookup failed: ' . $e->getMessage();
}

if ($modelEditId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM asset_models WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $modelEditId]);
    $editModel = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editModel) {
        $errors[] = 'Model not found.';
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
                <a class="nav-link active" href="inventory_admin.php">Inventory</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="users.php">Users</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="activity_log.php">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="settings.php">Settings</a>
            </li>
        </ul>

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $section === 'categories' ? 'active' : '' ?>" href="inventory_admin.php?section=categories">Categories</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $section === 'models' ? 'active' : '' ?>" href="inventory_admin.php?section=models">Models</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $section === 'inventory' ? 'active' : '' ?>" href="inventory_admin.php">Assets</a>
            </li>
        </ul>

        <?php if ($section === 'inventory'): ?>
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                        <h5 class="card-title mb-0">Assets</h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <a class="btn btn-outline-secondary" href="inventory_admin.php?section=inventory&export=assets">Export CSV</a>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importAssetsModal">Import CSV</button>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAssetModal">Create Asset</button>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="assets-filter" placeholder="Filter assets...">
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="assets-sort">
                                <option value="tag:asc">Sort by tag (A-Z)</option>
                                <option value="tag:desc">Sort by tag (Z-A)</option>
                                <option value="name:asc">Sort by name (A-Z)</option>
                                <option value="name:desc">Sort by name (Z-A)</option>
                                <option value="model:asc">Sort by model (A-Z)</option>
                                <option value="model:desc">Sort by model (Z-A)</option>
                                <option value="status:asc">Sort by status (A-Z)</option>
                                <option value="status:desc">Sort by status (Z-A)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select class="form-select" id="assets-status-filter">
                                <option value="">All statuses</option>
                                <?php foreach ($statusOptions as $opt): ?>
                                    <option value="<?= h($opt) ?>"><?= h(ucwords(str_replace('_', ' ', $opt))) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="assets-model-filter">
                                <option value="">All models</option>
                                <?php foreach ($models as $model): ?>
                                    <option value="<?= h($model['name'] ?? '') ?>"><?= h($model['name'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <p class="text-muted small mb-3"><?= count($assets) ?> total.</p>
                    <?php if (empty($assets)): ?>
                        <div class="text-muted small">No assets found yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Tag</th>
                                        <th>Name</th>
                                        <th>Model</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody id="assets-table">
                                    <?php foreach ($assets as $asset): ?>
                                        <tr data-id="<?= (int)($asset['id'] ?? 0) ?>"
                                            data-tag="<?= h($asset['asset_tag'] ?? '') ?>"
                                            data-name="<?= h($asset['name'] ?? '') ?>"
                                            data-model="<?= h($asset['model_name'] ?? '') ?>"
                                            data-status="<?= h($asset['status'] ?? 'available') ?>">
                                            <td><?= (int)($asset['id'] ?? 0) ?></td>
                                            <td><?= h($asset['asset_tag'] ?? '') ?></td>
                                            <td><?= h($asset['name'] ?? '') ?></td>
                                            <td><?= h($asset['model_name'] ?? '') ?></td>
                                            <td><?= h(ucwords(str_replace('_', ' ', $asset['status'] ?? 'available'))) ?></td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editAssetModal-<?= (int)$asset['id'] ?>">Edit</button>
                                            </td>
                                            </tr>
                                        <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <?php if ($section === 'models'): ?>
                <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                        <h5 class="card-title mb-0">Models</h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <a class="btn btn-outline-secondary" href="inventory_admin.php?section=models&export=models">Export CSV</a>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importModelsModal">Import CSV</button>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModelModal">Create Model</button>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="models-filter" placeholder="Filter models...">
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="models-sort">
                                <option value="name:asc">Sort by name (A-Z)</option>
                                <option value="name:desc">Sort by name (Z-A)</option>
                                <option value="manufacturer:asc">Sort by manufacturer (A-Z)</option>
                                <option value="manufacturer:desc">Sort by manufacturer (Z-A)</option>
                                <option value="category:asc">Sort by category (A-Z)</option>
                                <option value="category:desc">Sort by category (Z-A)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="models-category-filter">
                                <option value="">All categories</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= h($category['name'] ?? '') ?>"><?= h($category['name'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <p class="text-muted small mb-3"><?= count($models) ?> total.</p>
                    <?php if (empty($models)): ?>
                        <div class="text-muted small">No models found yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                                <table class="table table-sm table-striped align-middle">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Image</th>
                                            <th>Name</th>
                                            <th>Manufacturer</th>
                                            <th>Category</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="models-table">
                                        <?php foreach ($models as $model): ?>
                                            <tr data-id="<?= (int)($model['id'] ?? 0) ?>"
                                                data-name="<?= h($model['name'] ?? '') ?>"
                                                data-manufacturer="<?= h($model['manufacturer'] ?? '') ?>"
                                                data-category="<?= h($model['category_name'] ?? '') ?>">
                                                <td><?= (int)($model['id'] ?? 0) ?></td>
                                                <td>
                                                    <?php if (!empty($model['image_url'])): ?>
                                                        <img src="<?= h($model['image_url']) ?>" alt="" style="width: 56px; height: 56px; object-fit: cover; border-radius: 6px;">
                                                    <?php else: ?>
                                                        <span class="text-muted small">No image</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= h($model['name'] ?? '') ?></td>
                                                <td><?= h($model['manufacturer'] ?? '') ?></td>
                                                <td><?= h($model['category_name'] ?? 'Unassigned') ?></td>
                                                <td class="text-end">
                                                    <a class="btn btn-sm btn-outline-primary" href="inventory_admin.php?section=inventory&asset_model=<?= urlencode($model['name'] ?? '') ?>">View Assets</a>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createAssetForModelModal-<?= (int)$model['id'] ?>">Create Asset</button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editModelModal-<?= (int)$model['id'] ?>">Edit</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
                        <h5 class="card-title mb-0">Categories</h5>
                        <div class="d-flex gap-2 flex-wrap">
                            <a class="btn btn-outline-secondary" href="inventory_admin.php?section=categories&export=categories">Export CSV</a>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importCategoriesModal">Import CSV</button>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">Create Category</button>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="categories-filter" placeholder="Filter categories...">
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="categories-sort">
                                <option value="name:asc">Sort by name (A-Z)</option>
                                <option value="name:desc">Sort by name (Z-A)</option>
                                <option value="description:asc">Sort by description (A-Z)</option>
                                <option value="description:desc">Sort by description (Z-A)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="categories-description-filter">
                                <option value="">All descriptions</option>
                                <option value="1">Has description</option>
                                <option value="0">No description</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-muted small mb-3"><?= count($categories) ?> total.</p>
                    <?php if (empty($categories)): ?>
                        <div class="text-muted small">No categories found yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th></th>
                                        </tr>
                                    </thead>
                                <tbody id="categories-table">
                                    <?php foreach ($categories as $category): ?>
                                        <tr data-id="<?= (int)($category['id'] ?? 0) ?>"
                                            data-name="<?= h($category['name'] ?? '') ?>"
                                            data-description="<?= h($category['description'] ?? '') ?>">
                                            <td><?= (int)($category['id'] ?? 0) ?></td>
                                            <td>
                                                <input type="text" name="category_name" class="form-control form-control-sm" value="<?= h($category['name'] ?? '') ?>" required form="category-form-<?= (int)($category['id'] ?? 0) ?>" disabled>
                                            </td>
                                            <td>
                                                <input type="text" name="category_description" class="form-control form-control-sm" value="<?= h($category['description'] ?? '') ?>" form="category-form-<?= (int)($category['id'] ?? 0) ?>" disabled>
                                            </td>
                                            <td class="text-end">
                                                <form method="post" id="category-form-<?= (int)($category['id'] ?? 0) ?>" class="d-inline">
                                                    <input type="hidden" name="action" value="save_category">
                                                    <input type="hidden" name="category_id" value="<?= (int)($category['id'] ?? 0) ?>">
                                                    <input type="hidden" name="section" value="categories">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary js-category-edit">Edit</button>
                                                    <button type="submit" class="btn btn-sm btn-outline-primary js-category-save" disabled>Save</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php layout_footer(); ?>
<div class="modal fade" id="importCategoriesModal" tabindex="-1" aria-labelledby="importCategoriesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_categories">
                <div class="modal-header">
                    <h5 class="modal-title" id="importCategoriesModalLabel">Import categories CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Columns: name, description</p>
                    <div class="mb-3">
                        <a class="btn btn-outline-secondary btn-sm" href="inventory_admin.php?section=categories&template=categories">Download template CSV</a>
                    </div>
                    <input type="file" name="categories_csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import categories</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="importModelsModal" tabindex="-1" aria-labelledby="importModelsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_models">
                <div class="modal-header">
                    <h5 class="modal-title" id="importModelsModalLabel">Import models CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Columns: id (optional), name, manufacturer, category_id or category_name, notes, image_url</p>
                    <div class="mb-3">
                        <a class="btn btn-outline-secondary btn-sm" href="inventory_admin.php?section=models&template=models">Download template CSV</a>
                    </div>
                    <input type="file" name="models_csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import models</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="importAssetsModal" tabindex="-1" aria-labelledby="importAssetsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="import_assets">
                <div class="modal-header">
                    <h5 class="modal-title" id="importAssetsModalLabel">Import assets CSV</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Columns: asset_tag, name, model_id or model_name, status</p>
                    <div class="mb-3">
                        <a class="btn btn-outline-secondary btn-sm" href="inventory_admin.php?section=inventory&template=assets">Download template CSV</a>
                    </div>
                    <input type="file" name="assets_csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Import assets</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="createCategoryModal" tabindex="-1" aria-labelledby="createCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="save_category">
                <input type="hidden" name="category_id" value="0">
                <input type="hidden" name="section" value="categories">
                <div class="modal-header">
                    <h5 class="modal-title" id="createCategoryModalLabel">Create category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Category name</label>
                            <input type="text" name="category_name" class="form-control" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Description</label>
                            <input type="text" name="category_description" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create category</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="createModelModal" tabindex="-1" aria-labelledby="createModelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_model">
                <input type="hidden" name="model_id" value="0">
                <input type="hidden" name="section" value="models">
                <div class="modal-header">
                    <h5 class="modal-title" id="createModelModalLabel">Create model</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Model name</label>
                            <input type="text" name="model_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Manufacturer</label>
                            <input type="text" name="model_manufacturer" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select name="model_category_id" class="form-select">
                                <option value="">Unassigned</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= (int)$category['id'] ?>">
                                        <?= h($category['name'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Notes</label>
                            <textarea name="model_notes" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Image URL</label>
                            <input type="text" name="model_image_url" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Upload image</label>
                            <input type="file" name="model_image_upload" class="form-control">
                            <div class="form-text">Upload replaces the stored image unless a URL is provided.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create model</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php foreach ($models as $model): ?>
    <div class="modal fade" id="editModelModal-<?= (int)$model['id'] ?>" tabindex="-1" aria-labelledby="editModelModalLabel-<?= (int)$model['id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_model">
                    <input type="hidden" name="model_id" value="<?= (int)$model['id'] ?>">
                    <input type="hidden" name="section" value="models">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editModelModalLabel-<?= (int)$model['id'] ?>">Edit model</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Model name</label>
                                <input type="text" name="model_name" class="form-control" value="<?= h($model['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Manufacturer</label>
                                <input type="text" name="model_manufacturer" class="form-control" value="<?= h($model['manufacturer'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Category</label>
                                <select name="model_category_id" class="form-select">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= (int)$category['id'] ?>" <?= (int)($model['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>>
                                            <?= h($category['name'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Notes</label>
                                <textarea name="model_notes" class="form-control" rows="2"><?= h($model['notes'] ?? '') ?></textarea>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Image URL</label>
                                <input type="text" name="model_image_url" class="form-control" value="<?= h($model['image_url'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Upload image</label>
                                <input type="file" name="model_image_upload" class="form-control">
                                <div class="form-text">Upload replaces the stored image unless a URL is provided.</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update model</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php foreach ($models as $model): ?>
    <div class="modal fade" id="createAssetForModelModal-<?= (int)$model['id'] ?>" tabindex="-1" aria-labelledby="createAssetForModelModalLabel-<?= (int)$model['id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_asset">
                    <input type="hidden" name="asset_id" value="0">
                    <input type="hidden" name="section" value="inventory">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createAssetForModelModalLabel-<?= (int)$model['id'] ?>">Create asset for <?= h($model['name'] ?? '') ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Asset tag</label>
                                <input type="text" name="asset_tag" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Asset name</label>
                                <input type="text" name="asset_name" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Model</label>
                                <select name="asset_model_id" class="form-select" required>
                                    <?php foreach ($models as $modelOption): ?>
                                        <option value="<?= (int)$modelOption['id'] ?>" <?= (int)($modelOption['id'] ?? 0) === (int)($model['id'] ?? 0) ? 'selected' : '' ?>>
                                            <?= h($modelOption['name'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="asset_status" class="form-select">
                                    <?php foreach ($statusOptions as $opt): ?>
                                        <option value="<?= h($opt) ?>">
                                            <?= h(ucwords(str_replace('_', ' ', $opt))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                    </div>
                </div>
                <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create asset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<div class="modal fade" id="createAssetModal" tabindex="-1" aria-labelledby="createAssetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_asset">
                <input type="hidden" name="asset_id" value="0">
                <input type="hidden" name="section" value="inventory">
                <div class="modal-header">
                    <h5 class="modal-title" id="createAssetModalLabel">Create asset</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Asset tag</label>
                            <input type="text" name="asset_tag" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Asset name</label>
                            <input type="text" name="asset_name" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Model</label>
                            <select name="asset_model_id" class="form-select" required>
                                <option value="">Select model</option>
                                <?php foreach ($models as $model): ?>
                                    <option value="<?= (int)$model['id'] ?>">
                                        <?= h($model['name'] ?? '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Status</label>
                            <select name="asset_status" class="form-select">
                                <?php foreach ($statusOptions as $opt): ?>
                                    <option value="<?= h($opt) ?>">
                                        <?= h(ucwords(str_replace('_', ' ', $opt))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create asset</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php foreach ($assets as $asset): ?>
    <div class="modal fade" id="editAssetModal-<?= (int)$asset['id'] ?>" tabindex="-1" aria-labelledby="editAssetModalLabel-<?= (int)$asset['id'] ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="save_asset">
                    <input type="hidden" name="asset_id" value="<?= (int)$asset['id'] ?>">
                    <input type="hidden" name="section" value="inventory">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editAssetModalLabel-<?= (int)$asset['id'] ?>">Edit asset</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Asset tag</label>
                                <input type="text" name="asset_tag" class="form-control" value="<?= h($asset['asset_tag'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Asset name</label>
                                <input type="text" name="asset_name" class="form-control" value="<?= h($asset['name'] ?? '') ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Model</label>
                                <select name="asset_model_id" class="form-select" required>
                                    <option value="">Select model</option>
                                    <?php foreach ($models as $model): ?>
                                        <option value="<?= (int)$model['id'] ?>" <?= (int)($asset['model_id'] ?? 0) === (int)$model['id'] ? 'selected' : '' ?>>
                                            <?= h($model['name'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="asset_status" class="form-select">
                                    <?php foreach ($statusOptions as $opt): ?>
                                        <option value="<?= h($opt) ?>" <?= ($asset['status'] ?? 'available') === $opt ? 'selected' : '' ?>>
                                            <?= h(ucwords(str_replace('_', ' ', $opt))) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update asset</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<script>
    function wireTableControls(config) {
        var input = document.getElementById(config.filterId);
        var table = document.getElementById(config.tableId);
        var sortSelect = document.getElementById(config.sortId);
        var filterSelects = (config.filterSelectIds || []).map(function (id) {
            return document.getElementById(id);
        }).filter(Boolean);
        if (!input || !table) {
            return;
        }
        var rows = Array.from(table.querySelectorAll('tr'));

        function getSortParts() {
            var value = sortSelect && sortSelect.value ? sortSelect.value : '';
            var parts = value.split(':');
            return {
                key: parts[0] || '',
                dir: parts[1] || 'asc',
            };
        }

        function compareRows(a, b, key, dir) {
            var av = (a.dataset[key] || '').toLowerCase();
            var bv = (b.dataset[key] || '').toLowerCase();
            if (av === bv) {
                return 0;
            }
            var result = av < bv ? -1 : 1;
            return dir === 'desc' ? -result : result;
        }

        function matchesFilters(row) {
            var query = input.value.trim().toLowerCase();
            if (query && row.textContent.toLowerCase().indexOf(query) === -1) {
                return false;
            }
            return filterSelects.every(function (select) {
                var value = select.value;
                if (value === '') {
                    return true;
                }
                return config.filterPredicates[select.id](row, value);
            });
        }

        function render() {
            var sort = getSortParts();
            var ordered = rows.slice();
            if (sort.key) {
                ordered.sort(function (a, b) {
                    return compareRows(a, b, sort.key, sort.dir);
                });
            }
            ordered.forEach(function (row) {
                row.style.display = matchesFilters(row) ? '' : 'none';
                table.appendChild(row);
            });
        }

        input.addEventListener('input', render);
        if (sortSelect) {
            sortSelect.addEventListener('change', render);
        }
        filterSelects.forEach(function (select) {
            select.addEventListener('change', render);
        });
        render();
        return {
            render: render,
            input: input,
        };
    }

    var assetsControls = wireTableControls({
        filterId: 'assets-filter',
        sortId: 'assets-sort',
        tableId: 'assets-table',
        filterSelectIds: ['assets-status-filter', 'assets-model-filter'],
        filterPredicates: {
            'assets-status-filter': function (row, value) {
                return (row.dataset.status || '') === value;
            },
            'assets-model-filter': function (row, value) {
                return (row.dataset.model || '') === value;
            },
        },
    });

    wireTableControls({
        filterId: 'models-filter',
        sortId: 'models-sort',
        tableId: 'models-table',
        filterSelectIds: ['models-category-filter'],
        filterPredicates: {
            'models-category-filter': function (row, value) {
                return (row.dataset.category || '') === value;
            },
        },
    });

    wireTableControls({
        filterId: 'categories-filter',
        sortId: 'categories-sort',
        tableId: 'categories-table',
        filterSelectIds: ['categories-description-filter'],
        filterPredicates: {
            'categories-description-filter': function (row, value) {
                var hasDescription = (row.dataset.description || '').trim() !== '' ? '1' : '0';
                return hasDescription === value;
            },
        },
    });

    var params = new URLSearchParams(window.location.search);
    var assetModelQuery = params.get('asset_model');
    if (assetsControls && assetModelQuery) {
        var modelSelect = document.getElementById('assets-model-filter');
        if (modelSelect) {
            modelSelect.value = assetModelQuery;
        }
        if (assetsControls.input) {
            assetsControls.input.value = '';
        }
        assetsControls.render();
    }

    document.querySelectorAll('.js-category-edit').forEach(function (button) {
        button.addEventListener('click', function () {
            var form = button.closest('form');
            if (!form) {
                return;
            }
            var formId = form.getAttribute('id');
            if (!formId) {
                return;
            }
            var inputs = document.querySelectorAll('input[form="' + formId + '"]');
            inputs.forEach(function (input) {
                input.disabled = false;
            });
            var saveButton = form.querySelector('.js-category-save');
            if (saveButton) {
                saveButton.disabled = false;
            }
            if (inputs.length > 0) {
                inputs[0].focus();
            }
        });
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
