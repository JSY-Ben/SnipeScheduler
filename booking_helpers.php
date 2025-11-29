<?php
// booking_helpers.php
// Shared helpers for working with reservations & items.

require_once __DIR__ . '/snipeit_client.php';

/**
 * Fetch all items for a reservation, with human-readable names.
 *
 * Returns an array of:
 *   [
 *     ['model_id' => 123, 'name' => 'Canon 5D', 'qty' => 2],
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

        $items[] = [
            'model_id' => $modelId,
            'name'     => $name,
            'qty'      => $qty,
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
