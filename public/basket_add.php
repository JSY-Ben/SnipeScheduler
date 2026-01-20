<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/inventory_client.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: catalogue.php');
    exit;
}

$modelId      = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;
$qtyRequested = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

if ($modelId <= 0 || $qtyRequested <= 0) {
    // Bad input; just go back to catalogue
    header('Location: catalogue.php');
    exit;
}

// Clamp requested quantity to something sane
if ($qtyRequested > 100) {
    $qtyRequested = 100;
}

// Enforce hardware limits from local inventory (if available)
try {
    $totalAssets = count_assets_by_model($modelId);
    $activeCheckedOut = count_checked_out_assets_by_model($modelId);
    $maxQty = $totalAssets > 0 ? max(0, $totalAssets - $activeCheckedOut) : 0;
} catch (Throwable $e) {
    $maxQty = 0; // treat as unknown (no hard cap)
}

if ($maxQty > 0 && $qtyRequested > $maxQty) {
    $qtyRequested = $maxQty;
}

// Basket is stored in session as: model_id => quantity
if (!isset($_SESSION['basket']) || !is_array($_SESSION['basket'])) {
    $_SESSION['basket'] = [];
}

$currentQty = isset($_SESSION['basket'][$modelId])
    ? (int)$_SESSION['basket'][$modelId]
    : 0;

$newQty = $currentQty + $qtyRequested;

if ($maxQty > 0 && $newQty > $maxQty) {
    $newQty = $maxQty;
}

$_SESSION['basket'][$modelId] = $newQty;

// Compute total items in basket for UI feedback
$basketCount = 0;
foreach ($_SESSION['basket'] as $q) {
    $basketCount += (int)$q;
}

// Detect AJAX / fetch request
$isAjax = (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) || (
    isset($_SERVER['HTTP_ACCEPT']) &&
    strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false
);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok'           => true,
        'basket_count' => $basketCount,
    ]);
    exit;
}

// Fallback: normal redirect if not AJAX
header('Location: catalogue.php');
exit;
