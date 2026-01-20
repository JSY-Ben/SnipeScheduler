<?php
/**
 * db.php
 *
 * Single PDO connection for the booking app database.
 */

require_once __DIR__ . '/bootstrap.php';

$config = load_config();

if (!is_array($config) || empty($config['db_booking'])) {
    throw new RuntimeException('Booking database configuration (db_booking) is missing in config.php');
}

$db = $config['db_booking'];

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['dbname'],
    $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO(
        $dsn,
        $db['username'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    throw new RuntimeException('Could not connect to booking database: ' . $e->getMessage(), 0, $e);
}
