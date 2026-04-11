<?php
// booking_helpers.php
// Shared helpers for working with reservations & items.

require_once __DIR__ . '/snipeit_client.php';

function booking_normalize_item_type(string $type): string
{
    $type = strtolower(trim($type));

    switch ($type) {
        case 'accessory':
        case 'kit':
            return $type;
        case 'model':
        default:
            return 'model';
    }
}

function booking_catalogue_item_key(string $type, int $itemId): string
{
    $type = booking_normalize_item_type($type);
    $itemId = max(0, $itemId);

    return $type . ':' . $itemId;
}

function booking_session_basket_items(array $basket): array
{
    $items = [];

    foreach ($basket as $key => $value) {
        $type = 'model';
        $itemId = 0;
        $qty = 0;

        if (is_array($value)) {
            $type = booking_normalize_item_type((string)($value['type'] ?? 'model'));
            $itemId = (int)($value['id'] ?? 0);
            $qty = (int)($value['qty'] ?? ($value['quantity'] ?? 0));
        } else {
            $qty = (int)$value;
            $itemId = is_int($key) || ctype_digit((string)$key) ? (int)$key : 0;
        }

        if ($itemId <= 0 && is_string($key) && preg_match('/^([a-z_]+):(\d+)$/i', $key, $m)) {
            $type = booking_normalize_item_type($m[1]);
            $itemId = (int)$m[2];
        }

        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }

        $itemKey = booking_catalogue_item_key($type, $itemId);
        if (!isset($items[$itemKey])) {
            $items[$itemKey] = [
                'key' => $itemKey,
                'type' => $type,
                'id' => $itemId,
                'qty' => 0,
            ];
        }

        $items[$itemKey]['qty'] += $qty;
    }

    return $items;
}

function booking_session_basket_export(array $items): array
{
    $export = [];

    foreach ($items as $item) {
        $type = booking_normalize_item_type((string)($item['type'] ?? 'model'));
        $itemId = (int)($item['id'] ?? 0);
        $qty = (int)($item['qty'] ?? 0);
        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }

        $key = booking_catalogue_item_key($type, $itemId);
        $export[$key] = [
            'type' => $type,
            'id' => $itemId,
            'qty' => $qty,
        ];
    }

    return $export;
}

function booking_session_basket_total_quantity(array $basket): int
{
    $total = 0;

    foreach (booking_session_basket_items($basket) as $item) {
        $total += (int)($item['qty'] ?? 0);
    }

    return $total;
}

function booking_blocking_reservation_statuses(): array
{
    return ['pending', 'confirmed'];
}

function booking_reservation_items_have_typed_columns(PDO $pdo, bool $refresh = false): bool
{
    static $hasTypedColumns = null;

    if ($hasTypedColumns !== null && !$refresh) {
        return $hasTypedColumns;
    }

    $hasTypedColumns = false;

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM reservation_items LIKE 'item_type'");
        $typeColumn = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        $stmt = $pdo->query("SHOW COLUMNS FROM reservation_items LIKE 'item_id'");
        $idColumn = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        $stmt = $pdo->query("SHOW COLUMNS FROM reservation_items LIKE 'item_name_cache'");
        $nameColumn = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        $hasTypedColumns = !empty($typeColumn) && !empty($idColumn) && !empty($nameColumn);
    } catch (Throwable $e) {
        $hasTypedColumns = false;
    }

    return $hasTypedColumns;
}

function booking_reservation_item_select_sql(PDO $pdo, string $alias = ''): string
{
    $aliasPrefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';

    if (booking_reservation_items_have_typed_columns($pdo)) {
        return "
            COALESCE(NULLIF({$aliasPrefix}item_type, ''), 'model') AS item_type,
            COALESCE(NULLIF({$aliasPrefix}item_id, 0), {$aliasPrefix}model_id) AS item_id,
            COALESCE(NULLIF({$aliasPrefix}item_name_cache, ''), {$aliasPrefix}model_name_cache) AS item_name_cache,
            {$aliasPrefix}model_id,
            {$aliasPrefix}model_name_cache,
            {$aliasPrefix}quantity
        ";
    }

    return "
        'model' AS item_type,
        {$aliasPrefix}model_id AS item_id,
        {$aliasPrefix}model_name_cache AS item_name_cache,
        {$aliasPrefix}model_id,
        {$aliasPrefix}model_name_cache,
        {$aliasPrefix}quantity
    ";
}

function booking_reservation_item_match_sql(PDO $pdo, string $alias, string $itemTypePlaceholder, string $itemIdPlaceholder): string
{
    $aliasPrefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';

    if (booking_reservation_items_have_typed_columns($pdo)) {
        return "
            COALESCE(NULLIF({$aliasPrefix}item_type, ''), 'model') = {$itemTypePlaceholder}
            AND COALESCE(NULLIF({$aliasPrefix}item_id, 0), {$aliasPrefix}model_id) = {$itemIdPlaceholder}
        ";
    }

    return "
        {$itemTypePlaceholder} = 'model'
        AND {$aliasPrefix}model_id = {$itemIdPlaceholder}
    ";
}

function booking_fetch_catalogue_item_record(string $type, int $itemId): ?array
{
    $type = booking_normalize_item_type($type);
    if ($itemId <= 0) {
        return null;
    }

    try {
        switch ($type) {
            case 'accessory':
                return get_accessory($itemId);
            case 'model':
            default:
                return get_model($itemId);
        }
    } catch (Throwable $e) {
        return null;
    }
}

function booking_extract_catalogue_item_image_path(array $record): string
{
    $candidates = [
        $record['image'] ?? null,
        $record['image_path'] ?? null,
        $record['image_url'] ?? null,
        $record['thumbnail'] ?? null,
        $record['thumbnail_url'] ?? null,
        $record['photo'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_array($candidate)) {
            foreach (['url', 'src', 'href', 'path', 'image'] as $key) {
                $nested = trim((string)($candidate[$key] ?? ''));
                if ($nested !== '') {
                    return $nested;
                }
            }
            continue;
        }

        $value = trim((string)$candidate);
        if ($value !== '') {
            return $value;
        }
    }

    if (isset($record['category']) && is_array($record['category'])) {
        $categoryImage = booking_extract_catalogue_item_image_path($record['category']);
        if ($categoryImage !== '') {
            return $categoryImage;
        }
    }

    return '';
}

function booking_get_requestable_total_for_item(string $type, int $itemId): int
{
    $type = booking_normalize_item_type($type);
    if ($itemId <= 0) {
        throw new InvalidArgumentException('Item ID must be positive.');
    }

    switch ($type) {
        case 'accessory':
            return count_available_accessory_units($itemId);
        case 'model':
        default:
            return count_requestable_assets_by_model($itemId);
    }
}

function booking_count_effective_checked_out_for_item(string $type, int $itemId, array $config, ?int $windowStartTs = null): int
{
    $type = booking_normalize_item_type($type);
    if ($itemId <= 0) {
        throw new InvalidArgumentException('Item ID must be positive.');
    }

    switch ($type) {
        case 'accessory':
            return 0;
        case 'model':
        default:
            return booking_count_effective_checked_out_assets($itemId, $config, $windowStartTs);
    }
}

function booking_count_reserved_item_quantity(
    PDO $pdo,
    string $type,
    int $itemId,
    string $start,
    string $end,
    array $statuses = ['pending', 'confirmed'],
    ?int $excludeReservationId = null
): int {
    $type = booking_normalize_item_type($type);
    if ($itemId <= 0 || $start === '' || $end === '' || empty($statuses)) {
        return 0;
    }

    $statusPlaceholders = [];
    $params = [
        ':item_type' => $type,
        ':item_id' => $itemId,
        ':start' => $start,
        ':end' => $end,
    ];

    foreach (array_values($statuses) as $idx => $status) {
        $placeholder = ':status_' . $idx;
        $statusPlaceholders[] = $placeholder;
        $params[$placeholder] = (string)$status;
    }

    $sql = "
        SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
          FROM reservation_items ri
          JOIN reservations r ON r.id = ri.reservation_id
         WHERE " . booking_reservation_item_match_sql($pdo, 'ri', ':item_type', ':item_id') . "
           AND r.status IN (" . implode(', ', $statusPlaceholders) . ")
           AND (r.start_datetime < :end AND r.end_datetime > :start)
    ";

    if ($excludeReservationId !== null && $excludeReservationId > 0) {
        $sql .= " AND r.id <> :exclude_id";
        $params[':exclude_id'] = $excludeReservationId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

function booking_reservation_contains_only_models(PDO $pdo, int $reservationId): bool
{
    if ($reservationId <= 0) {
        return true;
    }

    try {
        if (!booking_reservation_items_have_typed_columns($pdo)) {
            return true;
        }

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
              FROM reservation_items
             WHERE reservation_id = :reservation_id
               AND COALESCE(NULLIF(item_type, ''), 'model') <> 'model'
        ");
        $stmt->execute([':reservation_id' => $reservationId]);

        return ((int)$stmt->fetchColumn()) === 0;
    } catch (Throwable $e) {
        return true;
    }
}

function booking_catalogue_checked_out_affects_future_availability(array $config): bool
{
    $catalogueCfg = $config['catalogue'] ?? [];

    return array_key_exists('checked_out_affects_future_availability', $catalogueCfg)
        ? !empty($catalogueCfg['checked_out_affects_future_availability'])
        : true;
}

function booking_expected_checkin_to_timestamp($value): ?int
{
    if (is_array($value)) {
        $value = $value['datetime'] ?? ($value['date'] ?? '');
    }

    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $value .= ' 23:59:59';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }

    return $ts;
}

function booking_should_count_checked_out_asset(array $config, $expectedCheckin, ?int $windowStartTs = null): bool
{
    $nowTs = time();
    if ($windowStartTs === null || $windowStartTs <= $nowTs) {
        return true;
    }

    if (booking_catalogue_checked_out_affects_future_availability($config)) {
        return true;
    }

    $expectedCheckinTs = booking_expected_checkin_to_timestamp($expectedCheckin);
    if ($expectedCheckinTs === null) {
        return true;
    }

    return $expectedCheckinTs > $windowStartTs;
}

function booking_count_effective_checked_out_assets(int $modelId, array $config, ?int $windowStartTs = null): int
{
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Model ID must be positive.');
    }

    $nowTs = time();
    if (
        $windowStartTs === null
        || $windowStartTs <= $nowTs
        || booking_catalogue_checked_out_affects_future_availability($config)
    ) {
        return count_checked_out_assets_by_model($modelId);
    }

    global $pdo;
    require_once SRC_PATH . '/db.php';

    $stmt = $pdo->prepare("
        SELECT expected_checkin, status_label
          FROM checked_out_asset_cache
         WHERE model_id = :model_id
    ");
    $stmt->execute([':model_id' => $modelId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allowedStatusMap = snipeit_catalogue_allowed_status_labels($config);
    $count = 0;
    foreach ($rows as $row) {
        if (!snipeit_status_label_is_allowed($row['status_label'] ?? '', $allowedStatusMap)) {
            continue;
        }
        if (booking_should_count_checked_out_asset($config, $row['expected_checkin'] ?? '', $windowStartTs)) {
            $count++;
        }
    }

    return $count;
}

/**
 * Fetch all items for a reservation, with human-readable names.
 *
 * Returns an array of:
 *   [
 *     ['type' => 'model', 'item_id' => 123, 'name' => 'Canon 5D', 'qty' => 2, 'image' => '/uploads/models/...'],
 *     ...
 *   ]
 *
 * Uses Snipe-IT catalogue records to resolve names and images where possible.
 */
function get_reservation_items_with_names(PDO $pdo, int $reservationId): array
{
    $sql = "
        SELECT " . booking_reservation_item_select_sql($pdo) . "
        FROM reservation_items
        WHERE reservation_id = :res_id
        ORDER BY item_type ASC, item_id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':res_id' => $reservationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return [];
    }

    $items = [];
    static $itemCache = [];

    foreach ($rows as $row) {
        $itemType = booking_normalize_item_type((string)($row['item_type'] ?? 'model'));
        $itemId = isset($row['item_id']) ? (int)$row['item_id'] : 0;
        $qty = isset($row['quantity']) ? (int)$row['quantity'] : 0;

        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }

        $cacheKey = booking_catalogue_item_key($itemType, $itemId);
        if (!array_key_exists($cacheKey, $itemCache)) {
            $itemCache[$cacheKey] = booking_fetch_catalogue_item_record($itemType, $itemId);
        }

        $record = $itemCache[$cacheKey];
        $fallbackName = trim((string)($row['item_name_cache'] ?? ''));
        if ($fallbackName === '') {
            $fallbackName = ($itemType === 'accessory' ? 'Accessory #' : 'Model #') . $itemId;
        }
        $name = trim((string)($record['name'] ?? $fallbackName));
        if ($name === '') {
            $name = $fallbackName;
        }
        $image = is_array($record) ? booking_extract_catalogue_item_image_path($record) : '';

        $items[] = [
            'type' => $itemType,
            'item_id' => $itemId,
            'model_id' => $itemType === 'model' ? $itemId : 0,
            'name' => $name,
            'qty' => $qty,
            'image' => $image,
        ];
    }

    return $items;
}

/**
 * Build a single-line text summary from an items array.
 *
 * Example:
 *   "Canon 5D (2), Tripod (1), LED Panel (3)"
 */
function build_items_summary_text(array $items): string
{
    if (empty($items)) {
        return '';
    }

    $parts = [];
    foreach ($items as $item) {
        $name = $item['name'] ?? '';
        $qty  = isset($item['qty']) ? (int)$item['qty'] : 0;

        if ($name === '' || $qty <= 0) {
            continue;
        }

        $parts[] = $qty > 1
            ? sprintf('%s (%d)', $name, $qty)
            : $name;
    }

    return implode(', ', $parts);
}
