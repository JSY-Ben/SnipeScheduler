<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/favourites.php';

function favourite_toggle_redirect_target(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return 'catalogue.php';
    }

    $parts = parse_url($raw);
    if ($parts === false) {
        return 'catalogue.php';
    }

    $path = trim((string)($parts['path'] ?? ''));
    if ($path === '' || basename($path) !== 'catalogue.php') {
        return 'catalogue.php';
    }

    $query = isset($parts['query']) ? (string)$parts['query'] : '';
    if ($query !== '') {
        return 'catalogue.php?' . $query;
    }

    return 'catalogue.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: catalogue.php');
    exit;
}

$bookingOverride = $_SESSION['booking_user_override'] ?? null;
$activeUser = $bookingOverride ?: $currentUser;
$userEmail = favourites_normalize_user_email((string)($activeUser['email'] ?? ''));

$modelId = isset($_POST['model_id']) ? (int)$_POST['model_id'] : 0;
$isFavourite = (string)($_POST['is_favourite'] ?? '') === '1';
$returnUrl = favourite_toggle_redirect_target((string)($_POST['return_url'] ?? ''));

if ($userEmail !== '' && $modelId > 0) {
    favourites_set_model($pdo, $userEmail, $modelId, $isFavourite);
}

header('Location: ' . $returnUrl);
exit;
