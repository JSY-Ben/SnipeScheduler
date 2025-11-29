<?php
// snipeit_db.php - PDO connection to the Snipe-IT database.
// EDIT these values to match your Snipe-IT DB.

$dsn_snipe  = 'mysql:host=localhost;dbname=inventory;charset=utf8mb4';
$user_snipe = 'snipeit_res';
$pass_snipe = 'Snipeit123!';

$options_snipe = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $snipePdo = new PDO($dsn_snipe, $user_snipe, $pass_snipe, $options_snipe);
} catch (PDOException $e) {
    die('Snipe-IT DB connection failed: ' . $e->getMessage());
}
