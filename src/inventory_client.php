<?php
// inventory_client.php
//
// Local inventory data access layer for models, assets, and checkouts.

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

function inventory_map_model_row(array $row): array
{
    return [
        'id' => (int)($row['id'] ?? 0),
        'name' => $row['name'] ?? '',
        'manufacturer' => [
            'name' => $row['manufacturer'] ?? '',
        ],
        'category' => [
            'id' => (int)($row['category_id'] ?? 0),
            'name' => $row['category_name'] ?? '',
        ],
        'image' => $row['image_url'] ?? '',
        'notes' => $row['notes'] ?? '',
    ];
}

function inventory_map_asset_row(array $row): array
{
    $modelImage = $row['model_image_url'] ?? '';
    $image = $modelImage;

    $asset = [
        'id' => (int)($row['asset_id'] ?? ($row['id'] ?? 0)),
        'asset_tag' => $row['asset_tag'] ?? '',
        'name' => $row['asset_name'] ?? ($row['name'] ?? ''),
        'model_id' => (int)($row['model_id'] ?? 0),
        'status' => $row['status'] ?? '',
        'image' => $image ?? '',
    ];

    if (isset($row['model_name'])) {
        $asset['model'] = [
            'id' => (int)($row['model_id'] ?? 0),
            'name' => $row['model_name'] ?? '',
        ];
        if ($modelImage !== '') {
            $asset['model']['image'] = $modelImage;
        }
    }

    $assigned = [];
    $assignedId = (int)($row['assigned_to_id'] ?? 0);
    if ($assignedId > 0) {
        $assigned['id'] = $assignedId;
    }
    $assignedEmail = $row['assigned_to_email'] ?? '';
    $assignedName = $row['assigned_to_name'] ?? '';
    $assignedUsername = $row['assigned_to_username'] ?? '';
    if ($assignedEmail !== '') {
        $assigned['email'] = $assignedEmail;
    }
    if ($assignedUsername !== '') {
        $assigned['username'] = $assignedUsername;
    }
    if ($assignedName !== '') {
        $assigned['name'] = $assignedName;
    }
    if (!empty($assigned)) {
        $asset['assigned_to'] = $assigned;
    } elseif ($assignedName !== '') {
        $asset['assigned_to_fullname'] = $assignedName;
    }
    if (isset($row['status_label'])) {
        $asset['status_label'] = $row['status_label'];
    }

    return $asset;
}

function get_model_categories(): array
{
    global $pdo;
    $stmt = $pdo->query("
        SELECT id, name
          FROM asset_categories
         ORDER BY name ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $rows ?: [];
}

function get_bookable_models(
    int $page = 1,
    string $search = '',
    ?int $categoryId = null,
    ?string $sort = null,
    int $perPage = 50,
    array $allowedCategoryIds = []
): array {
    global $pdo;

    $page = max(1, $page);
    $perPage = max(1, $perPage);

    $params = [];
    $where = [];

    if ($search !== '') {
        $where[] = '(m.name LIKE :search OR m.manufacturer LIKE :search)';
        $params[':search'] = '%' . $search . '%';
    }

    if ($categoryId !== null) {
        $where[] = 'm.category_id = :category_id';
        $params[':category_id'] = $categoryId;
    }

    if (!empty($allowedCategoryIds)) {
        $placeholders = [];
        foreach ($allowedCategoryIds as $idx => $cid) {
            $ph = ':allowed_' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = (int)$cid;
        }
        $where[] = 'm.category_id IN (' . implode(',', $placeholders) . ')';
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) AS total
          FROM asset_models m
          {$whereSql}
    ");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sortSql = 'm.name ASC';
    if ($sort === 'manu_desc') {
        $sortSql = 'm.manufacturer DESC, m.name ASC';
    } elseif ($sort === 'manu_asc') {
        $sortSql = 'm.manufacturer ASC, m.name ASC';
    } elseif ($sort === 'name_desc') {
        $sortSql = 'm.name DESC';
    } elseif ($sort === 'units_asc') {
        $sortSql = 'assets_count ASC, m.name ASC';
    } elseif ($sort === 'units_desc') {
        $sortSql = 'assets_count DESC, m.name ASC';
    }

    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.name,
            m.manufacturer,
            m.notes,
            m.image_url,
            m.category_id,
            c.name AS category_name,
            COALESCE(assets_count.count_total, 0) AS assets_count
        FROM asset_models m
        LEFT JOIN asset_categories c ON c.id = m.category_id
        LEFT JOIN (
            SELECT model_id, COUNT(*) AS count_total
              FROM assets
             WHERE status IN ('available','checked_out')
             GROUP BY model_id
        ) AS assets_count ON assets_count.model_id = m.id
        {$whereSql}
        ORDER BY {$sortSql}
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $models = [];
    foreach ($rows as $row) {
        $models[] = inventory_map_model_row($row);
    }

    return [
        'total' => $total,
        'rows' => $models,
    ];
}

function get_model(int $modelId): array
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.name,
            m.manufacturer,
            m.notes,
            m.image_url,
            m.category_id,
            c.name AS category_name
        FROM asset_models m
        LEFT JOIN asset_categories c ON c.id = m.category_id
        WHERE m.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $modelId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return [];
    }
    return inventory_map_model_row($row);
}

function get_asset(int $assetId): array
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT
            a.id AS asset_id,
            a.asset_tag,
            a.name AS asset_name,
            a.model_id,
            a.status,
            m.name AS model_name,
            m.image_url AS model_image_url,
            co.assigned_to_id,
            co.assigned_to_name,
            co.assigned_to_email,
            co.assigned_to_username,
            co.status_label
        FROM assets a
        LEFT JOIN asset_models m ON m.id = a.model_id
        LEFT JOIN checked_out_asset_cache co ON co.asset_id = a.id
        WHERE a.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $assetId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? inventory_map_asset_row($row) : [];
}

function find_asset_by_tag(string $tag): array
{
    global $pdo;
    $tagTrim = trim($tag);
    if ($tagTrim === '') {
        throw new Exception('Asset tag is required.');
    }

    $stmt = $pdo->prepare("
        SELECT
            a.id AS asset_id,
            a.asset_tag,
            a.name AS asset_name,
            a.model_id,
            a.status,
            m.name AS model_name,
            m.image_url AS model_image_url,
            co.assigned_to_id,
            co.assigned_to_name,
            co.assigned_to_email,
            co.assigned_to_username,
            co.status_label
        FROM assets a
        LEFT JOIN asset_models m ON m.id = a.model_id
        LEFT JOIN checked_out_asset_cache co ON co.asset_id = a.id
        WHERE LOWER(a.asset_tag) = LOWER(:tag)
        LIMIT 1
    ");
    $stmt->execute([':tag' => $tagTrim]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception("No asset found with tag '{$tagTrim}'.");
    }
    return inventory_map_asset_row($row);
}

function search_assets(string $query, int $limit = 20): array
{
    global $pdo;

    $q = trim($query);
    if ($q === '') {
        return [];
    }

    $where = [
        '(a.asset_tag LIKE :q OR a.name LIKE :q OR m.name LIKE :q)',
    ];
    $params = [
        ':q' => '%' . $q . '%',
    ];

    $stmt = $pdo->prepare("
        SELECT
            a.id AS asset_id,
            a.asset_tag,
            a.name AS asset_name,
            a.model_id,
            a.status,
            m.name AS model_name,
            m.image_url AS model_image_url,
            co.assigned_to_id,
            co.assigned_to_name,
            co.assigned_to_email,
            co.assigned_to_username,
            co.status_label
        FROM assets a
        LEFT JOIN asset_models m ON m.id = a.model_id
        LEFT JOIN checked_out_asset_cache co ON co.asset_id = a.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.asset_tag ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $assets = [];
    foreach ($rows as $row) {
        $assets[] = inventory_map_asset_row($row);
    }

    return $assets;
}

function list_assets_by_model(int $modelId, int $maxResults = 300): array
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT
            a.id AS asset_id,
            a.asset_tag,
            a.name AS asset_name,
            a.model_id,
            a.status,
            m.name AS model_name,
            m.image_url AS model_image_url,
            co.assigned_to_id,
            co.assigned_to_name,
            co.assigned_to_email,
            co.assigned_to_username,
            co.status_label
        FROM assets a
        LEFT JOIN asset_models m ON m.id = a.model_id
        LEFT JOIN checked_out_asset_cache co ON co.asset_id = a.id
        WHERE a.model_id = :mid
        ORDER BY a.asset_tag ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':mid', $modelId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $maxResults, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $assets = [];
    foreach ($rows as $row) {
        $assets[] = inventory_map_asset_row($row);
    }

    return $assets;
}

function count_assets_by_model(int $modelId): int
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
          FROM assets
         WHERE model_id = :model_id
           AND status IN ('available','checked_out')
    ");
    $stmt->execute([':model_id' => $modelId]);
    return (int)$stmt->fetchColumn();
}

function count_checked_out_assets_by_model(int $modelId): int
{
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total
          FROM checked_out_asset_cache
         WHERE model_id = :model_id
    ");
    $stmt->execute([':model_id' => $modelId]);
    return (int)$stmt->fetchColumn();
}

function search_users(string $query, int $limit = 10): array
{
    global $pdo;
    $q = trim($query);
    if ($q === '') {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT id, name, email, username
          FROM users
         WHERE email LIKE :q
            OR name LIKE :q
            OR username LIKE :q
         ORDER BY email ASC
         LIMIT :limit
    ");
    $stmt->bindValue(':q', '%' . $q . '%');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function find_single_user_by_email_or_name(string $query): array
{
    $rows = search_users($query, 5);
    if (count($rows) === 0) {
        throw new Exception("No users found matching '{$query}'.");
    }
    if (count($rows) > 1) {
        throw new Exception("Multiple users matched '{$query}'; please refine (e.g. full email).");
    }
    return $rows[0];
}

function checkout_asset_to_user(int $assetId, int $userId, string $note = '', ?string $expectedCheckin = null): void
{
    global $pdo;

    $asset = get_asset($assetId);
    if (empty($asset['id'])) {
        throw new Exception('Asset not found.');
    }

    $userStmt = $pdo->prepare("SELECT id, name, email, username FROM users WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception('User not found.');
    }

    $checkStmt = $pdo->prepare("SELECT asset_id FROM checked_out_asset_cache WHERE asset_id = :asset_id");
    $checkStmt->execute([':asset_id' => $assetId]);
    if ($checkStmt->fetch()) {
        throw new Exception('Asset is already checked out.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            UPDATE assets
               SET status = 'checked_out'
             WHERE id = :id
        ")->execute([':id' => $assetId]);

        $insert = $pdo->prepare("
            INSERT INTO checked_out_asset_cache (
                asset_id, asset_tag, asset_name,
                model_id, model_name,
                assigned_to_id, assigned_to_name, assigned_to_email, assigned_to_username,
                status_label, last_checkout, expected_checkin, updated_at
            ) VALUES (
                :asset_id, :asset_tag, :asset_name,
                :model_id, :model_name,
                :assigned_to_id, :assigned_to_name, :assigned_to_email, :assigned_to_username,
                :status_label, :last_checkout, :expected_checkin, NOW()
            )
            ON DUPLICATE KEY UPDATE
                assigned_to_id = VALUES(assigned_to_id),
                assigned_to_name = VALUES(assigned_to_name),
                assigned_to_email = VALUES(assigned_to_email),
                assigned_to_username = VALUES(assigned_to_username),
                status_label = VALUES(status_label),
                last_checkout = VALUES(last_checkout),
                expected_checkin = VALUES(expected_checkin),
                updated_at = NOW()
        ");

        $insert->execute([
            ':asset_id' => $assetId,
            ':asset_tag' => $asset['asset_tag'] ?? '',
            ':asset_name' => $asset['name'] ?? '',
            ':model_id' => $asset['model']['id'] ?? ($asset['model_id'] ?? 0),
            ':model_name' => $asset['model']['name'] ?? '',
            ':assigned_to_id' => (int)$user['id'],
            ':assigned_to_name' => $user['name'] ?? '',
            ':assigned_to_email' => $user['email'] ?? '',
            ':assigned_to_username' => $user['username'] ?? '',
            ':status_label' => 'Checked out',
            ':last_checkout' => date('Y-m-d H:i:s'),
            ':expected_checkin' => $expectedCheckin,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function update_asset_expected_checkin(int $assetId, string $expectedDate): void
{
    global $pdo;
    $stmt = $pdo->prepare("
        UPDATE checked_out_asset_cache
           SET expected_checkin = :expected_checkin,
               updated_at = NOW()
         WHERE asset_id = :asset_id
    ");
    $stmt->execute([
        ':expected_checkin' => $expectedDate,
        ':asset_id' => $assetId,
    ]);
}

function checkin_asset(int $assetId, string $note = ''): void
{
    global $pdo;
    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            DELETE FROM checked_out_asset_cache WHERE asset_id = :asset_id
        ")->execute([':asset_id' => $assetId]);

        $pdo->prepare("
            UPDATE assets
               SET status = 'available'
             WHERE id = :id
        ")->execute([':id' => $assetId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function list_checked_out_assets(bool $overdueOnly = false): array
{
    global $pdo;

    $sql = "
        SELECT
            asset_id,
            asset_tag,
            asset_name,
            model_id,
            model_name,
            assigned_to_id,
            assigned_to_name,
            assigned_to_email,
            assigned_to_username,
            status_label,
            last_checkout,
            expected_checkin
        FROM checked_out_asset_cache
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $now = time();
    $results = [];
    foreach ($rows as $row) {
        $expectedCheckin = $row['expected_checkin'] ?? '';
        if ($overdueOnly) {
            $normalizedExpected = $expectedCheckin;
            if (is_string($expectedCheckin) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $expectedCheckin)) {
                $normalizedExpected = $expectedCheckin . ' 23:59:59';
            }
            $expTs = $normalizedExpected ? strtotime($normalizedExpected) : null;
            if (!$expTs || $expTs > $now) {
                continue;
            }
        }

        $assigned = [];
        $assignedId = (int)($row['assigned_to_id'] ?? 0);
        if ($assignedId > 0) {
            $assigned['id'] = $assignedId;
        }
        $assignedEmail = $row['assigned_to_email'] ?? '';
        $assignedName = $row['assigned_to_name'] ?? '';
        $assignedUsername = $row['assigned_to_username'] ?? '';
        if ($assignedEmail !== '') {
            $assigned['email'] = $assignedEmail;
        }
        if ($assignedUsername !== '') {
            $assigned['username'] = $assignedUsername;
        }
        if ($assignedName !== '') {
            $assigned['name'] = $assignedName;
        }

        $item = [
            'id' => (int)($row['asset_id'] ?? 0),
            'asset_tag' => $row['asset_tag'] ?? '',
            'name' => $row['asset_name'] ?? '',
            'model' => [
                'id' => (int)($row['model_id'] ?? 0),
                'name' => $row['model_name'] ?? '',
            ],
            'status_label' => $row['status_label'] ?? '',
            'last_checkout' => $row['last_checkout'] ?? '',
            'expected_checkin' => $expectedCheckin,
            '_last_checkout_norm' => $row['last_checkout'] ?? '',
            '_expected_checkin_norm' => $expectedCheckin,
        ];

        if (!empty($assigned)) {
            $item['assigned_to'] = $assigned;
        } elseif ($assignedName !== '') {
            $item['assigned_to_fullname'] = $assignedName;
        }

        $results[] = $item;
    }

    return $results;
}
