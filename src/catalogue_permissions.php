<?php

require_once __DIR__ . '/bootstrap.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/snipeit_client.php';

function catalogue_permissions_pdo(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    global $pdo;
    require_once SRC_PATH . '/db.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        $GLOBALS['pdo'] = $pdo;
        return $pdo;
    }
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    $config = load_config();
    $db = $config['db_booking'] ?? [];
    if (empty($db)) {
        throw new RuntimeException('Booking database configuration (db_booking) is missing in config.php');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'] ?? 'localhost',
        (int)($db['port'] ?? 3306),
        $db['dbname'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );

    $GLOBALS['pdo'] = new PDO(
        $dsn,
        $db['username'] ?? '',
        $db['password'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    return $GLOBALS['pdo'];
}

function catalogue_permissions_last_db_error(?Throwable $error = null): string
{
    static $lastError = '';

    if ($error !== null) {
        $lastError = $error->getMessage();
    }

    return $lastError;
}

function catalogue_permissions_table_exists(bool $create = true): bool
{
    static $exists = null;

    if ($exists === true) {
        return true;
    }

    try {
        $pdo = catalogue_permissions_pdo();
        $pdo->query('SELECT 1 FROM catalogue_group_restrictions LIMIT 1');
        $exists = true;
        return true;
    } catch (Throwable $e) {
        catalogue_permissions_last_db_error($e);
        $exists = false;
    }

    if (!$create) {
        return false;
    }

    try {
        $pdo = catalogue_permissions_pdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS catalogue_group_restrictions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                group_id INT UNSIGNED NOT NULL,
                group_name VARCHAR(255) NOT NULL DEFAULT '',
                item_type VARCHAR(32) NOT NULL,
                item_id INT UNSIGNED NOT NULL,
                item_name_cache VARCHAR(255) NOT NULL DEFAULT '',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_catalogue_group_item (group_id, item_type, item_id),
                KEY idx_catalogue_group_restrictions_group (group_id),
                KEY idx_catalogue_group_restrictions_item (item_type, item_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $exists = true;
        return true;
    } catch (Throwable $e) {
        catalogue_permissions_last_db_error($e);
        $exists = false;
        return false;
    }
}

function catalogue_permissions_fetch_snipeit_groups(bool $allowResponseCache = true): array
{
    $groupsById = [];
    $limit = 200;
    $offset = 0;

    do {
        $data = snipeit_request('GET', 'groups', [
            'limit' => $limit,
            'offset' => $offset,
        ], $allowResponseCache);

        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int)($row['id'] ?? 0);
            $name = trim((string)($row['name'] ?? ($row['label'] ?? '')));
            if ($id <= 0 || $name === '') {
                continue;
            }

            $groupsById[$id] = [
                'id' => $id,
                'name' => $name,
            ];
        }

        if (count($rows) < $limit) {
            break;
        }
        $offset += $limit;
    } while (true);

    $groups = array_values($groupsById);
    usort($groups, static function (array $a, array $b): int {
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    return $groups;
}

function catalogue_permissions_normalize_group_ids($value): array
{
    $ids = [];
    if (is_string($value)) {
        preg_match_all('/\d+\s*-\s*\d+|\d+/', $value, $matches);
        $value = $matches[0] ?? [];
    }
    if (!is_array($value)) {
        return [];
    }

    foreach ($value as $rawId) {
        if (is_array($rawId)) {
            foreach (catalogue_permissions_normalize_group_ids($rawId) as $nestedId) {
                $ids[$nestedId] = $nestedId;
            }
            continue;
        }

        $rawId = trim((string)$rawId);
        if ($rawId === '') {
            continue;
        }

        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $rawId, $rangeMatch)) {
            $start = (int)$rangeMatch[1];
            $end = (int)$rangeMatch[2];
            if ($start <= 0 || $end <= 0) {
                continue;
            }
            $min = min($start, $end);
            $max = max($start, $end);
            for ($id = $min; $id <= $max; $id++) {
                $ids[$id] = $id;
            }
            continue;
        }

        if (!ctype_digit($rawId)) {
            continue;
        }

        $id = (int)$rawId;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    ksort($ids, SORT_NUMERIC);
    return array_values($ids);
}

function catalogue_permissions_configured_snipeit_group_ids(?array $config = null): array
{
    if ($config === null) {
        try {
            $config = load_config();
        } catch (Throwable $e) {
            $config = [];
        }
    }

    $catalogue = is_array($config['catalogue'] ?? null) ? $config['catalogue'] : [];
    return catalogue_permissions_normalize_group_ids($catalogue['snipeit_group_ids'] ?? []);
}

function catalogue_permissions_configured_snipeit_groups(?array $config = null): array
{
    $groups = [];
    foreach (catalogue_permissions_configured_snipeit_group_ids($config) as $groupId) {
        $groups[] = [
            'id' => $groupId,
            'name' => 'Group #' . $groupId,
        ];
    }

    return $groups;
}

function catalogue_permissions_extract_group_ids_from_value($value): array
{
    $ids = [];

    if (is_array($value)) {
        if (isset($value['rows']) && is_array($value['rows'])) {
            return catalogue_permissions_extract_group_ids_from_value($value['rows']);
        }

        if (isset($value['id']) && is_numeric($value['id'])) {
            $id = (int)$value['id'];
            if ($id > 0) {
                $ids[$id] = $id;
            }
            return array_values($ids);
        }

        foreach ($value as $nested) {
            if (is_numeric($nested)) {
                $id = (int)$nested;
                if ($id > 0) {
                    $ids[$id] = $id;
                }
                continue;
            }

            foreach (catalogue_permissions_extract_group_ids_from_value($nested) as $id) {
                $ids[$id] = $id;
            }
        }
    }

    return array_values($ids);
}

function catalogue_permissions_find_snipeit_user_by_email(string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }

    $data = snipeit_request('GET', 'users', [
        'email' => $email,
        'limit' => 20,
    ], false);

    $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowEmail = strtolower(trim((string)($row['email'] ?? '')));
        if ($rowEmail === $email) {
            return $row;
        }
    }

    return [];
}

function catalogue_permissions_restricted_group_ids(): array
{
    if (!catalogue_permissions_table_exists(false)) {
        return [];
    }

    $pdo = catalogue_permissions_pdo();
    $stmt = $pdo->query('SELECT DISTINCT group_id FROM catalogue_group_restrictions');

    $groupIds = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $groupId) {
        $groupId = (int)$groupId;
        if ($groupId > 0) {
            $groupIds[$groupId] = $groupId;
        }
    }

    return array_values($groupIds);
}

function catalogue_permissions_group_contains_user_email(int $groupId, string $email): bool
{
    static $cache = [];

    $email = strtolower(trim($email));
    if ($groupId <= 0 || $email === '') {
        return false;
    }

    $cacheKey = $groupId . '|' . $email;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $data = snipeit_request('GET', 'users', [
            'group_id' => $groupId,
            'email' => $email,
            'limit' => 20,
        ], false);

        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowEmail = strtolower(trim((string)($row['email'] ?? '')));
            if ($rowEmail === $email) {
                $cache[$cacheKey] = true;
                return true;
            }
        }
    } catch (Throwable $e) {
        // Older Snipe-IT versions or restricted API tokens may not support group_id user filtering.
    }

    $cache[$cacheKey] = false;
    return false;
}

function catalogue_permissions_user_group_ids(array $user): array
{
    static $cache = [];

    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '') {
        return [];
    }
    if (array_key_exists($email, $cache)) {
        return $cache[$email];
    }

    $groupIds = [];
    try {
        $matched = catalogue_permissions_find_snipeit_user_by_email($email);
        $snipeUserId = (int)($matched['id'] ?? 0);
        if ($snipeUserId <= 0) {
            $cache[$email] = [];
            return $cache[$email];
        }

        foreach (catalogue_permissions_extract_group_ids_from_value($matched['groups'] ?? []) as $id) {
            $groupIds[$id] = $id;
        }

        $full = snipeit_request('GET', 'users/' . $snipeUserId, [], false);
        foreach (['groups', 'user_groups'] as $field) {
            if (!array_key_exists($field, $full)) {
                continue;
            }
            foreach (catalogue_permissions_extract_group_ids_from_value($full[$field]) as $id) {
                $groupIds[$id] = $id;
            }
        }
        if (empty($groupIds)) {
            try {
                $groupData = snipeit_request('GET', 'users/' . $snipeUserId . '/groups', [], false);
                foreach (catalogue_permissions_extract_group_ids_from_value($groupData['rows'] ?? $groupData) as $id) {
                    $groupIds[$id] = $id;
                }
            } catch (Throwable $e) {
                // Some Snipe-IT versions do not expose a per-user groups endpoint.
            }
        }
        if (empty($groupIds)) {
            foreach (catalogue_permissions_restricted_group_ids() as $restrictedGroupId) {
                if (catalogue_permissions_group_contains_user_email($restrictedGroupId, $email)) {
                    $groupIds[$restrictedGroupId] = $restrictedGroupId;
                }
            }
        }
    } catch (Throwable $e) {
        $groupIds = [];
    }

    $cache[$email] = array_values($groupIds);
    return $cache[$email];
}

function catalogue_permissions_denied_item_map_for_group(int $groupId): array
{
    if ($groupId <= 0 || !catalogue_permissions_table_exists()) {
        return [];
    }

    $pdo = catalogue_permissions_pdo();
    $stmt = $pdo->prepare("
        SELECT item_type, item_id
          FROM catalogue_group_restrictions
         WHERE group_id = :group_id
    ");
    $stmt->execute([':group_id' => $groupId]);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $type = booking_normalize_item_type((string)($row['item_type'] ?? ''));
        $id = (int)($row['item_id'] ?? 0);
        if (!in_array($type, ['model', 'accessory'], true) || $id <= 0) {
            continue;
        }
        $map[$type . ':' . $id] = true;
    }

    return $map;
}

function catalogue_permissions_denied_item_map_for_groups(array $groupIds): array
{
    $groupIds = array_values(array_filter(array_unique(array_map('intval', $groupIds)), static function (int $id): bool {
        return $id > 0;
    }));

    if (empty($groupIds) || !catalogue_permissions_table_exists(false)) {
        return [];
    }

    $pdo = catalogue_permissions_pdo();
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $stmt = $pdo->prepare("
        SELECT item_type, item_id
          FROM catalogue_group_restrictions
         WHERE group_id IN ({$placeholders})
    ");
    $stmt->execute($groupIds);

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $type = booking_normalize_item_type((string)($row['item_type'] ?? ''));
        $id = (int)($row['item_id'] ?? 0);
        if (!in_array($type, ['model', 'accessory'], true) || $id <= 0) {
            continue;
        }
        $map[$type . ':' . $id] = true;
    }

    return $map;
}

function catalogue_permissions_is_item_allowed(array $user, string $itemType, int $itemId): bool
{
    $itemType = booking_normalize_item_type($itemType);
    if (!in_array($itemType, ['model', 'accessory'], true) || $itemId <= 0) {
        return true;
    }

    $groupIds = catalogue_permissions_user_group_ids($user);
    if (empty($groupIds)) {
        return true;
    }

    $denied = catalogue_permissions_denied_item_map_for_groups($groupIds);
    return empty($denied[$itemType . ':' . $itemId]);
}

function catalogue_permissions_save_group_restrictions(int $groupId, string $groupName, array $catalogueItems, array $allowedKeys): void
{
    if ($groupId <= 0) {
        throw new InvalidArgumentException('Select a valid Snipe-IT group.');
    }
    if (!catalogue_permissions_table_exists()) {
        $details = catalogue_permissions_last_db_error();
        throw new RuntimeException(
            'Catalogue permissions table is not available.'
            . ($details !== '' ? ' Database error: ' . $details : '')
        );
    }

    $allowedLookup = [];
    foreach ($allowedKeys as $key) {
        $key = trim((string)$key);
        if ($key !== '') {
            $allowedLookup[$key] = true;
        }
    }

    $pdo = catalogue_permissions_pdo();
    $pdo->beginTransaction();
    try {
        $delete = $pdo->prepare('DELETE FROM catalogue_group_restrictions WHERE group_id = :group_id');
        $delete->execute([':group_id' => $groupId]);

        $insert = $pdo->prepare("
            INSERT INTO catalogue_group_restrictions (
                group_id,
                group_name,
                item_type,
                item_id,
                item_name_cache,
                created_at,
                updated_at
            ) VALUES (
                :group_id,
                :group_name,
                :item_type,
                :item_id,
                :item_name_cache,
                NOW(),
                NOW()
            )
        ");

        foreach ($catalogueItems as $item) {
            $type = booking_normalize_item_type((string)($item['type'] ?? ''));
            $id = (int)($item['id'] ?? 0);
            $name = trim((string)($item['name'] ?? ''));
            if (!in_array($type, ['model', 'accessory'], true) || $id <= 0) {
                continue;
            }

            $key = $type . ':' . $id;
            if (isset($allowedLookup[$key])) {
                continue;
            }

            $insert->execute([
                ':group_id' => $groupId,
                ':group_name' => $groupName,
                ':item_type' => $type,
                ':item_id' => $id,
                ':item_name_cache' => $name,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function catalogue_permissions_bookable_items(): array
{
    $items = [];

    try {
        $models = get_bookable_models(1, '', null, 'name_asc', 10000, [], [], false);
        foreach (($models['rows'] ?? []) as $model) {
            $id = (int)($model['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $items[] = [
                'type' => 'model',
                'id' => $id,
                'name' => trim((string)($model['name'] ?? ('Model #' . $id))),
                'category' => trim((string)($model['category']['name'] ?? '')),
                'manufacturer' => trim((string)($model['manufacturer']['name'] ?? '')),
                'image_path' => catalogue_permissions_extract_image_path($model),
            ];
        }
    } catch (Throwable $e) {
        // Keep accessories visible if models fail.
    }

    try {
        $accessories = get_bookable_accessories(1, '', 'name_asc', 10000, null);
        foreach (($accessories['rows'] ?? []) as $accessory) {
            $id = (int)($accessory['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $items[] = [
                'type' => 'accessory',
                'id' => $id,
                'name' => trim((string)($accessory['name'] ?? ('Accessory #' . $id))),
                'category' => trim((string)(
                    is_array($accessory['category'] ?? null)
                        ? ($accessory['category']['name'] ?? '')
                        : ($accessory['category_name'] ?? '')
                )),
                'manufacturer' => trim((string)(
                    is_array($accessory['manufacturer'] ?? null)
                        ? ($accessory['manufacturer']['name'] ?? '')
                        : ($accessory['manufacturer_name'] ?? '')
                )),
                'image_path' => catalogue_permissions_extract_image_path($accessory),
            ];
        }
    } catch (Throwable $e) {
        // Return whichever catalogue data could be loaded.
    }

    usort($items, static function (array $a, array $b): int {
        $typeCmp = strcmp((string)$a['type'], (string)$b['type']);
        if ($typeCmp !== 0) {
            return $typeCmp;
        }
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });

    return $items;
}

function catalogue_permissions_extract_image_path(array $record): string
{
    $candidates = [
        $record['image'] ?? null,
        $record['image_url'] ?? null,
        $record['image_path'] ?? null,
        $record['thumbnail'] ?? null,
        $record['thumbnail_url'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $path = catalogue_permissions_image_path_from_value($candidate);
        if ($path !== '') {
            return $path;
        }
    }

    foreach (['model', 'asset_model', 'category'] as $nestedKey) {
        if (isset($record[$nestedKey]) && is_array($record[$nestedKey])) {
            $path = catalogue_permissions_extract_image_path($record[$nestedKey]);
            if ($path !== '') {
                return $path;
            }
        }
    }

    return '';
}

function catalogue_permissions_image_path_from_value($value): string
{
    if (is_array($value)) {
        foreach (['url', 'src', 'href', 'path', 'image'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            $path = catalogue_permissions_image_path_from_value($value[$key]);
            if ($path !== '') {
                return $path;
            }
        }

        return '';
    }

    return trim((string)$value);
}
