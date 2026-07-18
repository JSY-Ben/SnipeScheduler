<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: basket.php');
    exit;
}

$itemType = booking_normalize_item_type((string)($_POST['item_type'] ?? 'model'));
$itemId = isset($_POST['item_id']) ? (int)$_POST['item_id'] : (isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0);
$direction = trim((string)($_POST['direction'] ?? ''));

$basketItems = booking_session_basket_items($_SESSION['basket'] ?? []);
$itemKey = booking_catalogue_item_key($itemType, $itemId);

if ($itemId <= 0 || !isset($basketItems[$itemKey])) {
    header('Location: basket.php');
    exit;
}

$currentQty = (int)$basketItems[$itemKey]['qty'];
if ($currentQty < 1) {
    unset($basketItems[$itemKey]);
    $_SESSION['basket'] = booking_session_basket_export($basketItems);
    header('Location: basket.php');
    exit;
}

if ($direction === 'up') {
    $newQty = min(100, $currentQty + 1);
    $windowStartTs = null;
    $windowStart = '';
    $windowEnd = '';

    $startRaw = trim((string)($_SESSION['reservation_window_start'] ?? ''));
    $endRaw = trim((string)($_SESSION['reservation_window_end'] ?? ''));
    if ($startRaw !== '' && $endRaw !== '') {
        $timezone = app_get_timezone($config);
        $startDateTime = app_parse_local_datetime_input($startRaw, $timezone);
        $endDateTime = app_parse_local_datetime_input($endRaw, $timezone);
        if ($startDateTime && $endDateTime && $endDateTime > $startDateTime) {
            $windowStartTs = $startDateTime->getTimestamp();
            $windowStart = $startDateTime->format('Y-m-d H:i:s');
            $windowEnd = $endDateTime->format('Y-m-d H:i:s');
        }
    }

    try {
        $maxQty = booking_get_requestable_total_for_item($itemType, $itemId);
        $maxQty -= booking_count_effective_checked_out_for_item($itemType, $itemId, $config, $windowStartTs);
        if ($windowStart !== '' && $windowEnd !== '') {
            $maxQty -= booking_count_reserved_item_quantity(
                $pdo,
                $itemType,
                $itemId,
                $windowStart,
                $windowEnd,
                booking_blocking_reservation_statuses()
            );
        }
        $maxQty = max(0, $maxQty);
    } catch (Throwable $e) {
        $maxQty = 0;
    }

    if ($maxQty <= $currentQty) {
        $newQty = $currentQty;
    } elseif ($newQty > $maxQty) {
        $newQty = $maxQty;
    }

    $basketItems[$itemKey]['qty'] = max(1, $newQty);
} elseif ($direction === 'down') {
    $basketItems[$itemKey]['qty'] = max(1, $currentQty - 1);
}

$_SESSION['basket'] = booking_session_basket_export($basketItems);

header('Location: basket.php');
exit;
