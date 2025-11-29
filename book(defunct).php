<?php
require 'auth.php';
require 'snipeit_client.php';

$assetId = (int)($_GET['asset_id'] ?? 0);
if (!$assetId) {
    die('No asset selected.');
}

try {
    $asset = get_asset($assetId);
} catch (Exception $e) {
    die('Error loading asset from Snipe-IT: ' . htmlspecialchars($e->getMessage()));
}

if (empty($asset['id'])) {
    die('Asset not found in Snipe-IT.');
}

$user = $currentUser; // from auth.php
$fullName = trim($user['first_name'] . ' ' . $user['last_name']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Book <?= htmlspecialchars($asset['name'] ?? 'Item') ?></title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="p-4">
<div class="container">
    <h1>Book: <?= htmlspecialchars($asset['name'] ?? 'Item') ?></h1>
    <p><a href="catalogue.php">&larr; Back to catalogue</a></p>

    <div class="mb-3">
        <strong>Logged in as:</strong>
        <?= htmlspecialchars($fullName) ?>
        (<?= htmlspecialchars($user['email']) ?>)
        <a href="logout.php" class="ms-2 small">Log out</a>
    </div>

    <form method="post" action="book_submit.php">
        <input type="hidden" name="asset_id"
               value="<?= (int)$assetId ?>">

        <div class="mb-3">
            <label class="form-label">Start date &amp; time</label>
            <input type="datetime-local" name="start_datetime"
                   class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">End date &amp; time</label>
            <input type="datetime-local" name="end_datetime"
                   class="form-control" required>
        </div>

        <button class="btn btn-primary">Submit booking</button>
    </form>
</div>
</body>
</html>
