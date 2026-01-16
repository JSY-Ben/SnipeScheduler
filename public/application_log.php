<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/db.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$config = load_config();
$timezone = $config['app']['timezone'] ?? 'Europe/Jersey';
$tz = null;
try {
    $tz = new DateTimeZone($timezone);
} catch (Throwable $e) {
    $tz = null;
}

$activityLogRows = [];
$activityLogError = '';
try {
    $stmt = $pdo->query("
        SELECT id,
               event_type,
               actor_name,
               actor_email,
               subject_type,
               subject_id,
               message,
               metadata,
               ip_address,
               created_at
          FROM activity_log
         ORDER BY id DESC
         LIMIT 200
    ");
    $activityLogRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $activityLogError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application Log – SnipeScheduler</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Application Log</h1>
            <div class="page-subtitle">
                Review recent activity across the application.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-1">Latest activity</h5>
                <p class="text-muted small mb-3">Showing the 200 most recent events.</p>
                <?php if ($activityLogError): ?>
                    <div class="alert alert-warning small mb-3">
                        Could not load activity log: <?= h($activityLogError) ?>
                    </div>
                <?php elseif (empty($activityLogRows)): ?>
                    <div class="text-muted small">No activity log entries available yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Event</th>
                                    <th>Actor</th>
                                    <th>Subject</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activityLogRows as $row): ?>
                                    <?php
                                    $actorLabel = trim((string)($row['actor_name'] ?? ''));
                                    if ($actorLabel === '') {
                                        $actorLabel = (string)($row['actor_email'] ?? '');
                                    }
                                    if ($actorLabel === '') {
                                        $actorLabel = 'System';
                                    }

                                    $subjectLabel = trim((string)($row['subject_type'] ?? ''));
                                    $subjectId = trim((string)($row['subject_id'] ?? ''));
                                    if ($subjectLabel !== '' && $subjectId !== '') {
                                        $subjectLabel .= ' #' . $subjectId;
                                    } elseif ($subjectLabel === '' && $subjectId !== '') {
                                        $subjectLabel = '#' . $subjectId;
                                    }

                                    $timestamp = (string)($row['created_at'] ?? '');
                                    $displayTime = $timestamp;
                                    if ($timestamp !== '') {
                                        try {
                                            $dt = new DateTime($timestamp);
                                            if ($tz) {
                                                $dt->setTimezone($tz);
                                            }
                                            $displayTime = $dt->format('d/m/Y g:i A');
                                        } catch (Throwable $e) {
                                            $displayTime = $timestamp;
                                        }
                                    }

                                    $metadataText = trim((string)($row['metadata'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="text-nowrap"><?= h($displayTime) ?></td>
                                        <td><?= h((string)($row['event_type'] ?? '')) ?></td>
                                        <td><?= h($actorLabel) ?></td>
                                        <td><?= h($subjectLabel !== '' ? $subjectLabel : '-') ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= h((string)($row['message'] ?? '')) ?></div>
                                            <?php if ($metadataText !== ''): ?>
                                                <div class="text-muted small"><code><?= h($metadataText) ?></code></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
