<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/booking_helpers.php';

$itemType = booking_normalize_item_type((string)($_GET['item_type'] ?? 'model'));
$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : (isset($_GET['model_id']) ? (int)$_GET['model_id'] : 0);

if ($itemId <= 0 || empty($_SESSION['basket'])) {
    header('Location: basket.php');
    exit;
}

$basketItems = booking_session_basket_items($_SESSION['basket'] ?? []);
unset($basketItems[booking_catalogue_item_key($itemType, $itemId)]);
$_SESSION['basket'] = booking_session_basket_export($basketItems);

header('Location: basket.php');
exit;
