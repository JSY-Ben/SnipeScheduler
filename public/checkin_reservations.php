<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/inventory_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/layout.php';

$active    = basename($_SERVER['PHP_SELF']);
$isAdmin   = !empty($currentUser['is_admin']);
$isStaff   = !empty($currentUser['is_staff']) || $isAdmin;
$embedded  = defined('RESERVATIONS_EMBED');
$pageBase  = $embedded ? 'reservations.php' : 'checkin_reservations.php';
$baseQuery = $embedded ? ['tab' => 'checkin'] : [];

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

function format_checked_out_datetime(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    try {
        $dt = new DateTime($value);
        return $dt->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

$messages = [];
$errors   = [];
$selectedUserId = (int)($_REQUEST['user_id'] ?? 0);
$selectedUserEmail = trim((string)($_REQUEST['user_email'] ?? ''));
$selectedUserNameInput = trim((string)($_REQUEST['user_name'] ?? ''));
$userSearch = trim((string)($_GET['user_q'] ?? ''));
$selectedUser = null;
$checkedOut = [];
$totalCheckedOut = 0;
$pageRaw = (int)($_GET['page'] ?? 1);
$perPageRaw = (int)($_GET['per_page'] ?? 10);
$page = $pageRaw > 0 ? $pageRaw : 1;
$perPageOptions = [10, 25, 50, 100];
$perPage = in_array($perPageRaw, $perPageOptions, true) ? $perPageRaw : 10;
$userPageRaw = (int)($_GET['user_page'] ?? 1);
$userPage = $userPageRaw > 0 ? $userPageRaw : 1;
$userPerPageRaw = (int)($_GET['user_per_page'] ?? 25);
$userPerPageOptions = [10, 25, 50, 100];
$userPerPage = in_array($userPerPageRaw, $userPerPageOptions, true) ? $userPerPageRaw : 25;
$userList = [];
$userTotal = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';
    if ($mode === 'checkin' || $mode === 'checkin_all') {
        $selectedUserId = (int)($_POST['user_id'] ?? 0);
        $selectedUserEmail = trim((string)($_POST['user_email'] ?? ''));
        $selectedUserNameInput = trim((string)($_POST['user_name'] ?? ''));
        $assetIdsRaw = $_POST['asset_ids'] ?? [];
        $assetNotes = $_POST['asset_notes'] ?? [];

        $assetIds = array_values(array_filter(array_map('intval', (array)$assetIdsRaw), static function (int $id): bool {
            return $id > 0;
        }));

        if ($selectedUserId <= 0 && $selectedUserEmail === '' && $selectedUserNameInput === '') {
            $errors[] = 'Select a user before checking in assets.';
        } elseif ($mode === 'checkin' && empty($assetIds)) {
            $errors[] = 'Select at least one checked-out asset to check in.';
        } else {
            try {
                $params = [];
                $whereSql = '';
                if ($selectedUserId > 0) {
                    $whereSql = 'assigned_to_id = ?';
                    $params[] = $selectedUserId;
                } elseif ($selectedUserEmail !== '') {
                    $whereSql = 'assigned_to_email = ?';
                    $params[] = $selectedUserEmail;
                } else {
                    $whereSql = 'assigned_to_name = ?';
                    $params[] = $selectedUserNameInput;
                }
                $assetFilterSql = '';
                if ($mode === 'checkin' && !empty($assetIds)) {
                    $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
                    $assetFilterSql = " AND asset_id IN ({$placeholders})";
                    $params = array_merge($params, $assetIds);
                }

                $stmt = $pdo->prepare("
                    SELECT asset_id, asset_tag, model_name
                      FROM checked_out_asset_cache
                     WHERE {$whereSql} {$assetFilterSql}
                ");
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $validIds = [];
                $labels = [];
                $notesMeta = [];
                foreach ($rows as $row) {
                    $assetId = (int)($row['asset_id'] ?? 0);
                    if ($assetId <= 0) {
                        continue;
                    }
                    $validIds[] = $assetId;
                    $tag = $row['asset_tag'] ?? ('Asset #' . $assetId);
                    $model = $row['model_name'] ?? '';
                    $label = $model !== '' ? ($tag . ' (' . $model . ')') : $tag;
                    $labels[] = $label;
                    $note = '';
                    if (isset($assetNotes[$assetId])) {
                        $note = trim((string)$assetNotes[$assetId]);
                    }
                    if ($note !== '') {
                        $notesMeta[] = [
                            'asset_id' => $assetId,
                            'label' => $label,
                            'note' => $note,
                        ];
                    }
                }

                if (empty($validIds)) {
                    $errors[] = 'No matching checked-out assets were found for that user.';
                } else {
                    foreach ($validIds as $assetId) {
                        $note = '';
                        if (isset($assetNotes[$assetId])) {
                            $note = trim((string)$assetNotes[$assetId]);
                        }
                        checkin_asset($assetId, $note);
                    }
                    $userEmail = $selectedUserEmail;
                    $userName = $selectedUserNameInput;
                    if ($selectedUserId > 0) {
                        $userStmt = $pdo->prepare("SELECT first_name, last_name, email FROM users WHERE id = :id LIMIT 1");
                        $userStmt->execute([':id' => $selectedUserId]);
                        $user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                        $userEmail = $user['email'] ?? $userEmail;
                        $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    }
                    if ($userName === '') {
                        $userName = $userEmail;
                    }
                    $staffEmail = $currentUser['email'] ?? '';
                    $staffName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
                    if ($staffName === '') {
                        $staffName = $staffEmail !== '' ? $staffEmail : 'Staff';
                    }
                    $noteByLabel = [];
                    foreach ($notesMeta as $noteRow) {
                        $noteByLabel[$noteRow['label']] = $noteRow['note'];
                    }
                    $assetLineItems = array_map(static function (string $label) use ($noteByLabel): string {
                        $note = $noteByLabel[$label] ?? '';
                        $suffix = $note !== '' ? (' - Note: ' . $note) : '';
                        return '- ' . $label . $suffix;
                    }, $labels);
                    if ($userEmail !== '' && !empty($labels)) {
                        $bodyLines = array_merge(
                            ['The following assets have been checked in:'],
                            $assetLineItems,
                            $staffName !== '' ? ["Checked in by: {$staffName}"] : []
                        );
                        layout_send_notification($userEmail, $userName, 'Assets checked in', $bodyLines);
                    }
                    if ($staffEmail !== '' && !empty($labels)) {
                        $bodyLines = array_merge(
                            ['You checked in the following assets:'],
                            $assetLineItems
                        );
                        layout_send_notification($staffEmail, $staffName, 'Assets checked in', $bodyLines);
                    }
                    $count = count($validIds);
                    $messages[] = "Checked in {$count} asset(s).";
                    activity_log_event('assets_checked_in', 'Assets checked in (reservations)', [
                        'metadata' => [
                            'count' => $count,
                            'assets' => $labels,
                            'notes' => $notesMeta,
                            'user_id' => $selectedUserId,
                            'user_email' => $selectedUserEmail,
                        ],
                    ]);
                }
            } catch (Throwable $e) {
                $errors[] = 'Could not check in selected assets: ' . $e->getMessage();
            }
        }
    }
}

if ($selectedUserId > 0) {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, username FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $selectedUserId]);
    $selectedUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($selectedUserId > 0 || $selectedUserEmail !== '' || $selectedUserNameInput !== '') {
    $filterSql = 'assigned_to_name = :name';
    $countParams = [':name' => $selectedUserNameInput];
    if ($selectedUserId > 0) {
        $filterSql = 'assigned_to_id = :id';
        $countParams = [':id' => $selectedUserId];
    } elseif ($selectedUserEmail !== '') {
        $filterSql = 'assigned_to_email = :email';
        $countParams = [':email' => $selectedUserEmail];
    }
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) AS total
          FROM checked_out_asset_cache
         WHERE {$filterSql}
    ");
    $countStmt->execute($countParams);
    $totalCheckedOut = (int)($countStmt->fetchColumn() ?: 0);
    $totalPages = max(1, (int)ceil($totalCheckedOut / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT co.asset_id, co.asset_tag, co.asset_name, co.model_id, co.model_name,
               co.last_checkout, co.expected_checkin, m.image_url,
               co.assigned_to_name, co.assigned_to_email, co.assigned_to_username
          FROM checked_out_asset_cache co
          LEFT JOIN asset_models m ON m.id = co.model_id
         WHERE {$filterSql}
         ORDER BY co.asset_tag, co.asset_id
         LIMIT :limit OFFSET :offset
    ");
    if ($selectedUserId > 0) {
        $stmt->bindValue(':id', $selectedUserId, PDO::PARAM_INT);
    } elseif ($selectedUserEmail !== '') {
        $stmt->bindValue(':email', $selectedUserEmail, PDO::PARAM_STR);
    } else {
        $stmt->bindValue(':name', $selectedUserNameInput, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $checkedOut = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($selectedUserId > 0 && !$selectedUser) {
        $errors[] = 'Selected user was not found.';
    }
}

$userSearchParams = [];
$userSearchSql = '';
if ($userSearch !== '') {
    $userSearchSql = " AND (
            co.assigned_to_name LIKE :user_q
            OR co.assigned_to_email LIKE :user_q
            OR co.assigned_to_username LIKE :user_q
            OR u.first_name LIKE :user_q
            OR u.last_name LIKE :user_q
            OR u.email LIKE :user_q
            OR u.username LIKE :user_q
            OR CONCAT(u.first_name, ' ', u.last_name) LIKE :user_q
        )";
    $userSearchParams[':user_q'] = '%' . $userSearch . '%';
}

try {
    $baseUserSql = "
        SELECT
            COALESCE(u.id, 0) AS uid,
            COALESCE(u.email, co.assigned_to_email, '') AS email,
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), co.assigned_to_name, '') AS name,
            COALESCE(u.username, co.assigned_to_username, '') AS username
          FROM checked_out_asset_cache co
          LEFT JOIN users u ON u.id = co.assigned_to_id
         WHERE (
                (co.assigned_to_id IS NOT NULL AND co.assigned_to_id > 0)
                OR co.assigned_to_email <> ''
                OR co.assigned_to_name <> ''
                OR co.assigned_to_username <> ''
           )
           {$userSearchSql}
    ";
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) FROM (
            SELECT uid, email, name, username
              FROM ({$baseUserSql}) AS base_users
             GROUP BY uid, email, name, username
        ) AS distinct_users
    ");
    foreach ($userSearchParams as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $userTotal = (int)($countStmt->fetchColumn() ?: 0);
    $userTotalPages = max(1, (int)ceil($userTotal / $userPerPage));
    if ($userPage > $userTotalPages) {
        $userPage = $userTotalPages;
    }
    $userOffset = ($userPage - 1) * $userPerPage;

    $stmt = $pdo->prepare("
        SELECT uid, email, name, username, COUNT(*) AS asset_count
          FROM ({$baseUserSql}) AS base_users
         GROUP BY uid, email, name, username
         ORDER BY name, email
         LIMIT :limit OFFSET :offset
    ");
    foreach ($userSearchParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $userPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $userOffset, PDO::PARAM_INT);
    $stmt->execute();
    $userList = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = 'Could not load checked-out users: ' . $e->getMessage();
}

$selectedName = '';
$selectedEmail = $selectedUserEmail;
if ($selectedUser) {
    $selectedName = trim(($selectedUser['first_name'] ?? '') . ' ' . ($selectedUser['last_name'] ?? ''));
    $selectedEmail = $selectedUser['email'] ?? '';
} elseif (!empty($checkedOut)) {
    $firstRow = $checkedOut[0];
    $selectedName = trim((string)($firstRow['assigned_to_name'] ?? ''));
    if ($selectedEmail === '') {
        $selectedEmail = $firstRow['assigned_to_email'] ?? '';
    }
    if ($selectedName === '') {
        $selectedName = $selectedEmail !== '' ? $selectedEmail : (string)($firstRow['assigned_to_username'] ?? '');
    }
} elseif ($selectedUserNameInput !== '') {
    $selectedName = $selectedUserNameInput;
}
?>
<?php if (!$embedded): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Check In Reservations – KitGrab</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
    <style>
        .checkin-search {
            font-size: 1.1rem;
        }
    </style>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
<?php endif; ?>
        <div class="page-header">
            <h1>Check In Reservations</h1>
            <div class="page-subtitle">
                Search for a user with checked-out equipment and check items back in.
            </div>
        </div>

        <?php if (!$embedded): ?>
            <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <?php endif; ?>

        <?php foreach ($messages as $msg): ?>
            <div class="alert alert-success"><?= h($msg) ?></div>
        <?php endforeach; ?>
        <?php foreach ($errors as $msg): ?>
            <div class="alert alert-danger"><?= h($msg) ?></div>
        <?php endforeach; ?>

        <div class="border rounded-3 p-4 mb-4">
            <form method="get" action="<?= h($pageBase) ?>" id="checkin-user-form">
                <?php foreach ($baseQuery as $k => $v): ?>
                    <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                <?php endforeach; ?>
                <div class="d-flex flex-column flex-md-row gap-3 align-items-md-end">
                    <div class="flex-grow-1">
                        <label for="checkin_user_search" class="form-label fw-semibold">Search checked-out users</label>
                        <input type="text"
                               name="user_q"
                               class="form-control form-control-lg checkin-search"
                               id="checkin_user_search"
                               placeholder="Filter by name or email"
                               value="<?= h($userSearch) ?>"
                               <?= $userSearch !== '' ? 'autofocus' : '' ?>>
                    </div>
                    <div>
                        <label class="form-label fw-semibold d-block">Per page</label>
                        <select class="form-select form-select-lg" name="user_per_page" id="checkin-user-per-page">
                            <?php foreach ($userPerPageOptions as $option): ?>
                                <option value="<?= $option ?>" <?= $userPerPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="d-flex flex-column flex-md-row align-items-md-center gap-3 mt-2">
                    <div class="form-text">Only users with checked-out equipment will appear.</div>
                    <?php if ($selectedUserId > 0 || $selectedUserEmail !== '' || $selectedUserNameInput !== ''): ?>
                        <a href="<?= h($pageBase . (!empty($baseQuery) ? ('?' . http_build_query($baseQuery)) : '')) ?>" class="btn btn-link btn-sm">Clear selection</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="border rounded-3 p-3 mb-4">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">User</th>
                            <th scope="col">Checked-out items</th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($userList)): ?>
                            <tr>
                                <td colspan="3" class="text-muted">No checked-out users found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($userList as $userRow): ?>
                                <?php
                                    $uid = (int)($userRow['uid'] ?? 0);
                                    $email = trim((string)($userRow['email'] ?? ''));
                                    $name = trim((string)($userRow['name'] ?? ''));
                                    $username = trim((string)($userRow['username'] ?? ''));
                                    $count = (int)($userRow['asset_count'] ?? 0);
                                    $label = $name !== '' ? $name : ($email !== '' ? $email : $username);
                                    $subLabel = '';
                                    if ($email !== '' && $label !== $email) {
                                        $subLabel = $email;
                                    }
                                    $linkParams = $baseQuery;
                                    $linkParams['user_id'] = $uid > 0 ? $uid : '';
                                    $linkParams['user_email'] = $uid > 0 ? '' : $email;
                                    $linkParams['user_name'] = $uid > 0 ? '' : $name;
                                    $linkParams['user_q'] = $userSearch;
                                    $linkParams['user_per_page'] = $userPerPage;
                                    $linkParams['user_page'] = $userPage;
                                    $link = $pageBase . '?' . http_build_query($linkParams);
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= h($label !== '' ? $label : 'Unknown user') ?></div>
                                        <?php if ($subLabel !== ''): ?>
                                            <div class="text-muted small"><?= h($subLabel) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $count ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-outline-primary btn-sm" href="<?= h($link) ?>">Check in</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
                $userTotalPages = max(1, (int)ceil($userTotal / $userPerPage));
                $userPagerParams = array_merge($baseQuery, [
                    'user_q' => $userSearch,
                    'user_per_page' => $userPerPage,
                ]);
            ?>
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mt-3">
                <div class="text-muted small">
                    Showing <?= count($userList) ?> of <?= $userTotal ?> user(s)
                </div>
                <?php if ($userTotalPages > 1): ?>
                    <nav>
                        <ul class="pagination mb-0">
                            <?php
                                $userPrev = max(1, $userPage - 1);
                                $userNext = min($userTotalPages, $userPage + 1);
                                $userPagerParams['user_page'] = $userPrev;
                            ?>
                            <li class="page-item <?= $userPage <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= h($pageBase . '?' . http_build_query($userPagerParams)) ?>">Previous</a>
                            </li>
                            <?php for ($i = 1; $i <= $userTotalPages; $i++): ?>
                                <?php $userPagerParams['user_page'] = $i; ?>
                                <li class="page-item <?= $i === $userPage ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= h($pageBase . '?' . http_build_query($userPagerParams)) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php $userPagerParams['user_page'] = $userNext; ?>
                            <li class="page-item <?= $userPage >= $userTotalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= h($pageBase . '?' . http_build_query($userPagerParams)) ?>">Next</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($selectedUserId > 0 || $selectedUserEmail !== '' || $selectedUserNameInput !== ''): ?>
            <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-2 mb-3">
                <div>
                    <div class="h5 mb-1">
                        <?= h($selectedName !== '' ? $selectedName : ($selectedEmail !== '' ? $selectedEmail : 'Selected user')) ?>
                    </div>
                    <?php if ($selectedEmail !== ''): ?>
                        <div class="text-muted small"><?= h($selectedEmail) ?></div>
                    <?php endif; ?>
                </div>
                <div class="text-muted">
                    <?= $totalCheckedOut ?> item(s) checked out
                </div>
            </div>

            <?php if (empty($checkedOut)): ?>
                <div class="alert alert-info">No checked-out items found for this user.</div>
            <?php else: ?>
                <form method="post" action="<?= h($pageBase) ?>" class="border rounded-3 p-3">
                    <?php foreach ($baseQuery as $k => $v): ?>
                        <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="user_id" value="<?= (int)$selectedUserId ?>">
                    <input type="hidden" name="user_email" value="<?= h($selectedUserEmail) ?>">
                    <input type="hidden" name="user_name" value="<?= h($selectedUserNameInput) ?>">

                <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-2 mb-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="checkin-select-all">
                        <label class="form-check-label" for="checkin-select-all">Select all</label>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-outline-primary btn-lg" name="mode" value="checkin_all">Check in all</button>
                        <button type="submit" class="btn btn-primary btn-lg" name="mode" value="checkin">Check in selected</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th scope="col"></th>
                                <th scope="col">Asset Tag</th>
                                <th scope="col">Model</th>
                                <th scope="col">Checked Out</th>
                                <th scope="col">Expected Return</th>
                                <th scope="col">Check-in Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checkedOut as $row): ?>
                                    <?php
                                        $assetId = (int)($row['asset_id'] ?? 0);
                                        $assetTag = $row['asset_tag'] ?? '';
                                        $assetName = $row['asset_name'] ?? '';
                                        $modelName = $row['model_name'] ?? '';
                                        $imageUrl = $row['image_url'] ?? '';
                                        $lastCheckout = format_checked_out_datetime($row['last_checkout'] ?? '');
                                        $expected = format_checked_out_datetime($row['expected_checkin'] ?? '');
                                    ?>
                                    <tr>
                                        <td>
                                            <input class="form-check-input" type="checkbox" name="asset_ids[]" value="<?= $assetId ?>">
                                        </td>
                                        <td class="fw-semibold"><?= h($assetTag !== '' ? $assetTag : ('Asset #' . $assetId)) ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <?php if ($imageUrl !== ''): ?>
                                                    <img src="<?= h($imageUrl) ?>" alt="" class="reservation-model-image">
                                                <?php else: ?>
                                                    <div class="reservation-model-image reservation-model-image--placeholder">No image</div>
                                                <?php endif; ?>
                                                <div>
                                                    <div class="fw-semibold"><?= h($modelName !== '' ? $modelName : 'Unassigned model') ?></div>
                                                    <?php if ($assetName !== ''): ?>
                                                        <div class="text-muted small"><?= h($assetName) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= h($lastCheckout !== '' ? $lastCheckout : '—') ?></td>
                                        <td><?= h($expected !== '' ? $expected : '—') ?></td>
                                        <td style="min-width: 220px;">
                                            <input type="text"
                                                   name="asset_notes[<?= $assetId ?>]"
                                                   class="form-control"
                                                   placeholder="Optional note">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php
                        $totalPages = max(1, (int)ceil($totalCheckedOut / $perPage));
                        $baseParams = array_merge($baseQuery, [
                            'user_id' => $selectedUserId,
                            'user_email' => $selectedUserEmail,
                            'user_name' => $selectedUserNameInput,
                            'per_page' => $perPage,
                        ]);
                        $pageParams = $baseParams;
                    ?>
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2 mt-3">
                        <div class="text-muted small">
                            Showing <?= count($checkedOut) ?> of <?= $totalCheckedOut ?> item(s)
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <label class="text-muted small" for="checkin-per-page">Per page</label>
                            <select class="form-select form-select-sm" id="checkin-per-page" style="width:auto;">
                                <?php foreach ($perPageOptions as $option): ?>
                                    <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <nav class="mt-3">
                            <ul class="pagination mb-0">
                                <?php
                                    $prevPage = max(1, $page - 1);
                                    $nextPage = min($totalPages, $page + 1);
                                    $pageParams['page'] = $prevPage;
                                ?>
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= h($pageBase . '?' . http_build_query($pageParams)) ?>">Previous</a>
                                </li>
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <?php $pageParams['page'] = $i; ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= h($pageBase . '?' . http_build_query($pageParams)) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php $pageParams['page'] = $nextPage; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= h($pageBase . '?' . http_build_query($pageParams)) ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        <?php endif; ?>

<?php if (!$embedded): ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('checkin-select-all');
    if (selectAll) {
        selectAll.addEventListener('change', () => {
            const boxes = document.querySelectorAll('input[name="asset_ids[]"]');
            boxes.forEach((box) => {
                box.checked = selectAll.checked;
            });
        });
    }

    const perPageSelect = document.getElementById('checkin-per-page');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', () => {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', perPageSelect.value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        });
    }

    const userSearch = document.getElementById('checkin_user_search');
    const userPerPage = document.getElementById('checkin-user-per-page');
    const userForm = document.getElementById('checkin-user-form');
    if (userSearch && userForm) {
        if (userSearch.value.trim() !== '') {
            userSearch.focus();
            const val = userSearch.value;
            userSearch.setSelectionRange(val.length, val.length);
        }
        let timer = null;
        userSearch.addEventListener('input', () => {
            if (timer) clearTimeout(timer);
            timer = setTimeout(() => userForm.submit(), 350);
        });
    }
    if (userPerPage && userForm) {
        userPerPage.addEventListener('change', () => {
            userForm.submit();
        });
    }

    const checkinForm = document.querySelector('form[action="<?= h($pageBase) ?>"]');
    if (checkinForm) {
        checkinForm.addEventListener('submit', (event) => {
            const submitter = event.submitter;
            if (!submitter || submitter.value !== 'checkin') {
                return;
            }
            const boxes = checkinForm.querySelectorAll('input[name="asset_ids[]"]');
            const anyChecked = Array.from(boxes).some((box) => box.checked);
            if (!anyChecked) {
                event.preventDefault();
                alert('Select at least one checked-out item to check in.');
            }
        });
    }
});
</script>
