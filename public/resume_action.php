<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

$pending = $_SESSION['pending_login_action'] ?? null;
unset($_SESSION['pending_login_action']);

if (!is_array($pending)
    || ($pending['method'] ?? '') !== 'POST'
    || ($pending['target'] ?? '') !== 'basket_add.php'
    || (time() - (int)($pending['created_at'] ?? 0)) > 1800
    || !is_array($pending['payload'] ?? null)) {
    header('Location: index.php');
    exit;
}

$_POST = $pending['payload'];
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
require __DIR__ . '/basket_add.php';
