<?php
// booking_helpers.php
// Shared helpers for working with reservations & items.

require_once __DIR__ . '/snipeit_client.php';

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
        SELECT expected_checkin
          FROM checked_out_asset_cache
         WHERE model_id = :model_id
    ");
    $stmt->execute([':model_id' => $modelId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    foreach ($rows as $row) {
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
 *     ['model_id' => 123, 'name' => 'Canon 5D', 'qty' => 2, 'image' => '/uploads/models/...'],
 *     ...
 *   ]
 *
 * Assumes reservation_items has: reservation_id, model_id, quantity.
 * Uses Snipe-IT get_model($modelId) to resolve names.
 */
function get_reservation_items_with_names(PDO $pdo, int $reservationId): array
{
    // Adjust columns / table name here if yours differ:
    $sql = "
        SELECT model_id, quantity
        FROM reservation_items
        WHERE reservation_id = :res_id
        ORDER BY model_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':res_id' => $reservationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return [];
    }

    $items = [];
    static $modelCache = [];

    foreach ($rows as $row) {
        $modelId = isset($row['model_id']) ? (int)$row['model_id'] : 0;
        $qty     = isset($row['quantity']) ? (int)$row['quantity'] : 0;

        if ($modelId <= 0 || $qty <= 0) {
            continue;
        }

        if (!isset($modelCache[$modelId])) {
            try {
                // Uses Snipe-IT API client function we already have
                $modelCache[$modelId] = get_model($modelId);
            } catch (Exception $e) {
                $modelCache[$modelId] = null;
            }
        }

        $model = $modelCache[$modelId];
        $name  = $model['name'] ?? ('Model #' . $modelId);
        $image = $model['image'] ?? '';

        $items[] = [
            'model_id' => $modelId,
            'name'     => $name,
            'qty'      => $qty,
            'image'    => $image,
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
