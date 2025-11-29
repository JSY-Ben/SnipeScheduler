<?php
require 'auth.php';

$modelId = (int)($_GET['model_id'] ?? 0);
if ($modelId <= 0 || empty($_SESSION['basket'])) {
    header('Location: basket.php');
    exit;
}

unset($_SESSION['basket'][$modelId]);

header('Location: basket.php');
exit;
