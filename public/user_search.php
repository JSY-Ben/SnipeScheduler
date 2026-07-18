<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';

$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
if (!$isStaff) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

header('Content-Type: application/json');
$query = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($query) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

try {
    echo json_encode(['results' => search_snipeit_users($query, 10)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Snipe-IT user search failed.']);
}
