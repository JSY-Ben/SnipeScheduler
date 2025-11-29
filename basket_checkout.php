<?php
require 'auth.php';
require 'db.php';
require 'snipeit_client.php';

$user   = $currentUser;
$basket = $_SESSION['basket'] ?? [];

if (empty($basket)) {
    die('Your basket is empty.');
}

$startRaw = $_POST['start_datetime'] ?? '';
$endRaw   = $_POST['end_datetime'] ?? '';

if (!$startRaw || !$endRaw) {
    die('Start and end date/time are required.');
}

$startTs = strtotime($startRaw);
$endTs   = strtotime($endRaw);

if ($startTs === false || $endTs === false) {
    die('Invalid date/time.');
}

$start = date('Y-m-d H:i:s', $startTs);
$end   = date('Y-m-d H:i:s', $endTs);

if ($end <= $start) {
    die('End time must be after start time.');
}

// Build student info from Snipe-IT user
$studentName  = trim($user['first_name'] . ' ' . $user['last_name']);
$studentEmail = $user['email'];
$studentId    = $user['id']; // Snipe-IT user id

$pdo->beginTransaction();

try {
    $models = [];
    $totalRequestedItems = 0;

    foreach ($basket as $modelId => $qty) {
        $modelId = (int)$modelId;
        $qty     = (int)$qty;

        if ($modelId <= 0 || $qty < 1) {
            throw new Exception('Invalid model/quantity in basket.');
        }

        $model = get_model($modelId);
        if (empty($model['id'])) {
            throw new Exception('Model not found in Snipe-IT: ID ' . $modelId);
        }

        // How many units of this model are already booked for this time range?
        $sql = "
            SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
            FROM reservation_items ri
            JOIN reservations r ON r.id = ri.reservation_id
            WHERE ri.model_id = :model_id
              AND r.status IN ('pending','confirmed','completed')
              AND (r.start_datetime < :end AND r.end_datetime > :start)
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':model_id' => $modelId,
            ':start'    => $start,
            ':end'      => $end,
        ]);
        $row = $stmt->fetch();
        $existingBooked = $row ? (int)$row['booked_qty'] : 0;

        // Total physical units in Snipe-IT
        $totalHardware = get_model_hardware_count($modelId);

        if ($totalHardware > 0 && $existingBooked + $qty > $totalHardware) {
            throw new Exception(
                'Not enough units available for "' . ($model['name'] ?? ('ID '.$modelId)) . '" '
                . 'in that time period. Requested ' . $qty . ', already booked ' . $existingBooked
                . ', total available ' . $totalHardware . '.'
            );
        }

        $models[] = [
            'model' => $model,
            'qty'   => $qty,
        ];
        $totalRequestedItems += $qty;
    }

    // Reservation header summary text
    if (!empty($models)) {
        $firstName = $models[0]['model']['name'] ?? 'Multiple models';
    } else {
        $firstName = 'Multiple models';
    }

    $label = $firstName;
    if ($totalRequestedItems > 1) {
        $label .= ' +' . ($totalRequestedItems - 1) . ' more item(s)';
    }

    $insertRes = $pdo->prepare("
        INSERT INTO reservations (
            student_name, student_email, student_id, snipeit_user_id,
            asset_id, asset_name_cache,
            start_datetime, end_datetime, status
        ) VALUES (
            :student_name, :student_email, :student_id, :snipeit_user_id,
            0, :asset_name_cache,
            :start_datetime, :end_datetime, 'pending'
        )
    ");
    $insertRes->execute([
        ':student_name'     => $studentName,
        ':student_email'    => $studentEmail,
        ':student_id'       => $studentId,
        ':snipeit_user_id'  => $user['id'],
        ':asset_name_cache' => $label,
        ':start_datetime'   => $start,
        ':end_datetime'     => $end,
    ]);

    $reservationId = (int)$pdo->lastInsertId();

    // Insert reservation_items as model-level rows with quantity
    $insertItem = $pdo->prepare("
        INSERT INTO reservation_items (
            reservation_id, model_id, model_name_cache, quantity
        ) VALUES (
            :reservation_id, :model_id, :model_name_cache, :quantity
        )
    ");

    foreach ($models as $entry) {
        $model = $entry['model'];
        $qty   = (int)$entry['qty'];

        $insertItem->execute([
            ':reservation_id'   => $reservationId,
            ':model_id'         => (int)$model['id'],
            ':model_name_cache' => $model['name'] ?? ('Model #' . $model['id']),
            ':quantity'         => $qty,
        ]);
    }

    $pdo->commit();
    $_SESSION['basket'] = []; // clear basket

} catch (Exception $e) {
    $pdo->rollBack();
    die('Could not create booking: ' . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Booking submitted</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
    <h1>Thank you</h1>
    <p>Your booking has been submitted for <?= (int)$totalRequestedItems ?> item(s).</p>
    <p>
        <a href="catalogue.php" class="btn btn-primary">Book more equipment</a>
        <a href="my_bookings.php" class="btn btn-secondary">View my bookings</a>
    </p>
</div>
</body>
</html>
