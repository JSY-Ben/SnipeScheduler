<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';

function basket_add_is_ajax_request(): bool
{
    return (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    ) || (
        isset($_SERVER['HTTP_ACCEPT']) &&
        strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false
    );
}

function basket_add_respond(bool $ok, string $message, int $statusCode = 200): void
{
    $basketCount = booking_session_basket_total_quantity($_SESSION['basket'] ?? []);

    if (basket_add_is_ajax_request()) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => $ok,
            'message' => $message,
            'basket_count' => $basketCount,
        ]);
        exit;
    }

    $_SESSION['basket_feedback'] = [
        'type' => $ok ? 'success' : 'danger',
        'message' => $message,
    ];

    $tab = strtolower(trim((string)($_SESSION['catalogue_active_tab'] ?? 'models')));
    $tab = in_array($tab, ['models', 'accessories', 'kits'], true) ? $tab : 'models';
    $redirectUrl = 'catalogue.php?' . http_build_query([
        'tab' => $tab,
        'prefetch' => 1,
    ]);

    header('Location: ' . $redirectUrl);
    exit;
}

function basket_add_window_bounds(): array
{
    $startRaw = trim((string)($_POST['start_datetime'] ?? ''));
    $endRaw = trim((string)($_POST['end_datetime'] ?? ''));
    if ($startRaw === '' || $endRaw === '') {
        return [
            'start_ts' => null,
            'start' => '',
            'end' => '',
        ];
    }

    $startTs = strtotime($startRaw);
    $endTs = strtotime($endRaw);
    if ($startTs === false || $endTs === false || $endTs <= $startTs) {
        return [
            'start_ts' => null,
            'start' => '',
            'end' => '',
        ];
    }

    $_SESSION['reservation_window_start'] = $startRaw;
    $_SESSION['reservation_window_end'] = $endRaw;

    return [
        'start_ts' => (int)$startTs,
        'start' => date('Y-m-d H:i:s', $startTs),
        'end' => date('Y-m-d H:i:s', $endTs),
    ];
}

function basket_add_available_units_for_item(
    PDO $pdo,
    string $type,
    int $itemId,
    array $config,
    ?int $windowStartTs,
    string $windowStart = '',
    string $windowEnd = ''
): int
{
    $total = booking_get_requestable_total_for_item($type, $itemId);
    $checkedOut = booking_count_effective_checked_out_for_item($type, $itemId, $config, $windowStartTs);
    $reserved = 0;

    if ($windowStart !== '' && $windowEnd !== '') {
        $reserved = booking_count_reserved_item_quantity(
            $pdo,
            $type,
            $itemId,
            $windowStart,
            $windowEnd,
            booking_blocking_reservation_statuses()
        );
    }

    return $total > 0 ? max(0, $total - $checkedOut - $reserved) : 0;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: catalogue.php');
    exit;
}

$itemType = booking_normalize_item_type((string)($_POST['item_type'] ?? 'model'));
$itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : (isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0);
$qtyRequested = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
$window = basket_add_window_bounds();
$windowStartTs = $window['start_ts'];
$windowStart = $window['start'];
$windowEnd = $window['end'];

if ($itemId <= 0 || $qtyRequested <= 0) {
    basket_add_respond(false, 'Invalid item selection.', 400);
}

$qtyRequested = min(100, $qtyRequested);
$basketItems = booking_session_basket_items($_SESSION['basket'] ?? []);

try {
    if ($itemType === 'kit') {
        $breakdown = get_kit_booking_breakdown($itemId);
        $kitName = trim((string)($breakdown['kit']['name'] ?? ('Kit #' . $itemId)));
        $supportedItems = $breakdown['supported_items'] ?? [];
        $unsupportedItems = $breakdown['unsupported_items'] ?? [];

        if (empty($supportedItems)) {
            basket_add_respond(false, 'This kit does not contain any bookable models or accessories.', 400);
        }
        if (!empty($unsupportedItems)) {
            $unsupportedLabels = [];
            foreach ($unsupportedItems as $unsupported) {
                $label = trim((string)($unsupported['type'] ?? 'item'));
                $count = (int)($unsupported['count'] ?? 0);
                $unsupportedLabels[] = $count > 0 ? ($label . ' x' . $count) : $label;
            }
            basket_add_respond(
                false,
                'This kit includes unsupported item types for SnipeScheduler bookings: ' . implode(', ', $unsupportedLabels) . '.',
                400
            );
        }

        $maxKits = $qtyRequested;
        foreach ($supportedItems as $supportedItem) {
            $supportedType = booking_normalize_item_type((string)($supportedItem['type'] ?? 'model'));
            $supportedId = (int)($supportedItem['id'] ?? 0);
            $perKitQty = max(1, (int)($supportedItem['qty'] ?? 1));
            if ($supportedId <= 0) {
                continue;
            }

            $availableUnits = basket_add_available_units_for_item(
                $pdo,
                $supportedType,
                $supportedId,
                $config,
                $windowStartTs,
                $windowStart,
                $windowEnd
            );
            $existingQty = (int)($basketItems[booking_catalogue_item_key($supportedType, $supportedId)]['qty'] ?? 0);
            $remainingUnits = max(0, $availableUnits - $existingQty);
            $kitsForItem = intdiv($remainingUnits, $perKitQty);
            $maxKits = min($maxKits, $kitsForItem);
        }

        if ($maxKits <= 0) {
            basket_add_respond(false, 'No complete kits are currently available for the selected dates.', 409);
        }

        foreach ($supportedItems as $supportedItem) {
            $supportedType = booking_normalize_item_type((string)($supportedItem['type'] ?? 'model'));
            $supportedId = (int)($supportedItem['id'] ?? 0);
            $perKitQty = max(1, (int)($supportedItem['qty'] ?? 1));
            if ($supportedId <= 0) {
                continue;
            }

            $itemKey = booking_catalogue_item_key($supportedType, $supportedId);
            if (!isset($basketItems[$itemKey])) {
                $basketItems[$itemKey] = [
                    'key' => $itemKey,
                    'type' => $supportedType,
                    'id' => $supportedId,
                    'qty' => 0,
                ];
            }
            $basketItems[$itemKey]['qty'] += ($perKitQty * $maxKits);
        }

        $_SESSION['basket'] = booking_session_basket_export($basketItems);
        $message = $maxKits === 1 ? "Added {$kitName} to basket." : "Added {$maxKits} kits of {$kitName} to basket.";
        basket_add_respond(true, $message);
    }

    $availableUnits = basket_add_available_units_for_item(
        $pdo,
        $itemType,
        $itemId,
        $config,
        $windowStartTs,
        $windowStart,
        $windowEnd
    );
    $itemKey = booking_catalogue_item_key($itemType, $itemId);
    $currentQty = (int)($basketItems[$itemKey]['qty'] ?? 0);
    if ($availableUnits <= $currentQty) {
        basket_add_respond(false, 'No additional units are currently available for that item.', 409);
    }
    $newQty = $currentQty + $qtyRequested;

    $newQty = min($newQty, $availableUnits);

    $basketItems[$itemKey] = [
        'key' => $itemKey,
        'type' => $itemType,
        'id' => $itemId,
        'qty' => $newQty,
    ];
    $_SESSION['basket'] = booking_session_basket_export($basketItems);

    basket_add_respond(true, 'Added to basket.');
} catch (Throwable $e) {
    basket_add_respond(false, $e->getMessage(), 500);
}
