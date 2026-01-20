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

$editId = (int)($_GET['edit'] ?? 0);
$editLocked = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_user';

    if ($action === 'save_user') {
        $editId = (int)($_POST['user_id'] ?? 0);
        $email = strtolower(trim($_POST['email'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $isAdminFlag = isset($_POST['is_admin']);
        $isStaffFlag = isset($_POST['is_staff']) || $isAdminFlag;

        if ($email === '') {
            $errors[] = 'User email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'User email is not valid.';
        }

        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
                $stmt->execute([
                    ':email' => $email,
                    ':id' => $editId,
                ]);
                if ($stmt->fetch()) {
                    throw new Exception('That email address is already in use.');
                }

                $existing = null;
                if ($editId > 0) {
                    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
                    $stmt->execute([':id' => $editId]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$existing) {
                        throw new Exception('User not found.');
                    }
                    if (!empty($existing['auth_source']) && $existing['auth_source'] !== 'local') {
                        throw new Exception('External users cannot be edited here.');
                    }
                } elseif ($password === '') {
                    throw new Exception('Password is required for new users.');
                }

                $nameValue = $name !== '' ? $name : $email;
                $usernameValue = $username !== '' ? $username : null;
                $passwordHash = $password !== ''
                    ? password_hash($password, PASSWORD_DEFAULT)
                    : ($existing['password_hash'] ?? null);
                $userId = sprintf('%u', crc32($email));

                if ($editId > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE users
                           SET user_id = :user_id,
                               name = :name,
                               email = :email,
                               username = :username,
                               is_admin = :is_admin,
                               is_staff = :is_staff,
                               password_hash = :password_hash,
                               auth_source = 'local'
                         WHERE id = :id
                    ");
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':name' => $nameValue,
                        ':email' => $email,
                        ':username' => $usernameValue,
                        ':is_admin' => $isAdminFlag ? 1 : 0,
                        ':is_staff' => $isStaffFlag ? 1 : 0,
                        ':password_hash' => $passwordHash,
                        ':id' => $editId,
                    ]);
                    $messages[] = 'User updated.';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO users (user_id, name, email, username, is_admin, is_staff, password_hash, auth_source, created_at)
                        VALUES (:user_id, :name, :email, :username, :is_admin, :is_staff, :password_hash, 'local', NOW())
                    ");
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':name' => $nameValue,
                        ':email' => $email,
                        ':username' => $usernameValue,
                        ':is_admin' => $isAdminFlag ? 1 : 0,
                        ':is_staff' => $isStaffFlag ? 1 : 0,
                        ':password_hash' => $passwordHash,
                    ]);
                    $messages[] = 'User created.';
                    $editId = (int)$pdo->lastInsertId();
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$editUser = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $editId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editUser) {
        $errors[] = 'User not found.';
    } elseif (!empty($editUser['auth_source']) && $editUser['auth_source'] !== 'local') {
        $editLocked = true;
    }
}

$users = [];
try {
    $stmt = $pdo->query('
        SELECT id, name, email, username, is_admin, is_staff, auth_source, created_at
          FROM users
         ORDER BY name ASC, email ASC
    ');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = 'Could not load users: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin – Users</title>
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
            <h1>Users</h1>
            <div class="page-subtitle">
                Manage local user accounts and access levels.
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
                <a class="nav-link active" href="users.php">Users</a>
            </li>
        </ul>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title mb-1"><?= $editUser ? 'Edit user' : 'Create user' ?></h5>
                <p class="text-muted small mb-3">
                    <?= $editLocked ? 'External users are managed by their identity provider and cannot be edited here.' : 'Leave password blank to keep the existing password.' ?>
                </p>
                <form method="post" class="row g-3">
                    <input type="hidden" name="action" value="save_user">
                    <input type="hidden" name="user_id" value="<?= (int)($editUser['id'] ?? 0) ?>">
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= h($editUser['email'] ?? '') ?>" required <?= $editLocked ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Display name</label>
                        <input type="text" name="name" class="form-control" value="<?= h($editUser['name'] ?? '') ?>" placeholder="User Name" <?= $editLocked ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Username</label>
                        <input type="text" name="username" class="form-control" value="<?= h($editUser['username'] ?? '') ?>" placeholder="username" <?= $editLocked ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Set a password" <?= $editLocked ? 'disabled' : '' ?>>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_admin" id="is_admin" <?= !empty($editUser['is_admin']) ? 'checked' : '' ?> <?= $editLocked ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="is_admin">Admin</label>
                        </div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_staff" id="is_staff" <?= !empty($editUser['is_staff']) ? 'checked' : '' ?> <?= $editLocked ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="is_staff">Staff</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <?php if ($editUser): ?>
                            <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary" <?= $editLocked ? 'disabled' : '' ?>><?= $editUser ? 'Update user' : 'Create user' ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-1">All users</h5>
                <p class="text-muted small mb-3"><?= count($users) ?> total.</p>
                <?php if (empty($users)): ?>
                    <div class="text-muted small">No users found yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Source</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <?php
                                    $roleLabel = !empty($user['is_admin']) ? 'Admin' : (!empty($user['is_staff']) ? 'Staff' : 'User');
                                    $sourceRaw = trim((string)($user['auth_source'] ?? ''));
                                    $sourceLabel = $sourceRaw !== '' ? ucfirst($sourceRaw) : 'Local';
                                    $isEditable = ($sourceRaw === '' || $sourceRaw === 'local');
                                    $createdAt = $user['created_at'] ? date('Y-m-d', strtotime($user['created_at'])) : '';
                                    ?>
                                    <tr>
                                        <td><?= h($user['name'] ?? '') ?></td>
                                        <td><?= h($user['email'] ?? '') ?></td>
                                        <td><?= h($user['username'] ?? '') ?></td>
                                        <td><?= h($roleLabel) ?></td>
                                        <td><?= h($sourceLabel) ?></td>
                                        <td><?= h($createdAt) ?></td>
                                        <td class="text-end">
                                            <?php if ($isEditable): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="users.php?edit=<?= (int)$user['id'] ?>">Edit</a>
                                            <?php else: ?>
                                                <span class="text-muted small">External</span>
                                            <?php endif; ?>
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
