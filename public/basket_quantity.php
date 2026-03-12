<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/snipeit_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: basket.php');
    exit;
}

$modelId = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;
$direction = trim((string)($_POST['direction'] ?? ''));

if (
    $modelId <= 0 ||
    !isset($_SESSION['basket']) ||
    !is_array($_SESSION['basket']) ||
    !array_key_exists($modelId, $_SESSION['basket'])
) {
    header('Location: basket.php');
    exit;
}

$currentQty = (int)$_SESSION['basket'][$modelId];
if ($currentQty < 1) {
    unset($_SESSION['basket'][$modelId]);
    header('Location: basket.php');
    exit;
}

if ($direction === 'up') {
    $newQty = min(100, $currentQty + 1);
    $availabilityWindowStartTs = null;

    $startRaw = trim((string)($_SESSION['reservation_window_start'] ?? ''));
    $endRaw   = trim((string)($_SESSION['reservation_window_end'] ?? ''));
    if ($startRaw !== '' && $endRaw !== '') {
        $startTs = strtotime($startRaw);
        $endTs   = strtotime($endRaw);
        if ($startTs !== false && $endTs !== false && $endTs > $startTs) {
            $availabilityWindowStartTs = (int)$startTs;
        }
    }

    try {
        $requestableTotal = count_requestable_assets_by_model($modelId);
        $activeCheckedOut = booking_count_effective_checked_out_assets($modelId, $config, $availabilityWindowStartTs);
        $maxQty = $requestableTotal > 0 ? max(0, $requestableTotal - $activeCheckedOut) : 0;
    } catch (Throwable $e) {
        $maxQty = 0; // treat as unknown (no hard cap)
    }

    if ($maxQty > 0 && $newQty > $maxQty) {
        $newQty = $maxQty;
    }

    $_SESSION['basket'][$modelId] = max(1, $newQty);
} elseif ($direction === 'down') {
    $_SESSION['basket'][$modelId] = max(1, $currentQty - 1);
}

header('Location: basket.php');
exit;
