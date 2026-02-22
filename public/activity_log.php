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

$eventLabels = [
    'user_login' => 'User Login',
    'user_logout' => 'User Logout',
    'reservation_submitted' => 'Reservation Submitted',
    'reservation_updated' => 'Reservation Updated',
    'reservation_deleted' => 'Reservation Deleted',
    'reservation_cancelled' => 'Reservation Cancelled',
    'reservation_missed' => 'Reservation Missed',
    'reservation_restored' => 'Reservation Restored',
    'reservation_checked_out' => 'Reservation Checked Out',
    'quick_checkout' => 'Quick Checkout',
    'quick_checkin' => 'Quick Checkin',
    'asset_renewed' => 'Asset Renewed',
    'assets_renewed' => 'Assets Renewed',
];

$metadataLabels = [
    'checked_out_to' => 'Checked out to',
    'assets' => 'Assets',
    'checked_in_from' => 'Checked in from',
    'expected_checkin' => 'Expected check-in',
    'count' => 'Count',
    'cutoff_minutes' => 'Cutoff minutes',
    'note' => 'Note',
    'provider' => 'Provider',
    'start' => 'Start',
    'end' => 'End',
    'booked_for' => 'Booked for',
    'asset_id' => 'Asset ID',
    'asset_name' => 'Asset name',
    'items' => 'Items',
];

function format_activity_metadata(?string $metadataJson, array $labelMap, ?DateTimeZone $tz = null): array
{
    if (!$metadataJson) {
        return [];
    }

    $decoded = json_decode($metadataJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $lines = [];
    foreach ($decoded as $key => $value) {
        $label = $labelMap[$key] ?? ucwords(str_replace('_', ' ', (string)$key));
        if (is_array($value)) {
            $value = implode(', ', array_map(static function ($item): string {
                return is_scalar($item) ? (string)$item : json_encode($item, JSON_UNESCAPED_SLASHES);
            }, $value));
        } elseif (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        } elseif ($value === null) {
            $value = '';
        } else {
            $value = (string)$value;
            if ($value !== '' && in_array($key, ['start', 'end', 'expected_checkin'], true)) {
                try {
                    $value = app_format_datetime($value, null, $tz);
                } catch (Throwable $e) {
                    // Keep raw value on parse errors.
                }
            }
        }

        if ($value === '') {
            continue;
        }
        $lines[] = $label . ': ' . $value;
    }

    return $lines;
}

$qRaw    = trim($_GET['q'] ?? '');
$eventRaw = trim($_GET['event_type'] ?? '');
$fromRaw = trim($_GET['from'] ?? '');
$toRaw   = trim($_GET['to'] ?? '');
$pageRaw = (int)($_GET['page'] ?? 1);
$perPageRaw = (int)($_GET['per_page'] ?? 25);
$sortRaw = trim($_GET['sort'] ?? '');

$q        = $qRaw !== '' ? $qRaw : null;
$eventType = $eventRaw !== '' ? $eventRaw : null;
$dateFrom = $fromRaw !== '' ? $fromRaw : null;
$dateTo   = $toRaw !== '' ? $toRaw : null;
$page     = $pageRaw > 0 ? $pageRaw : 1;
$perPageOptions = [10, 25, 50, 100, 200];
$perPage = in_array($perPageRaw, $perPageOptions, true) ? $perPageRaw : 25;
$sortOptions = [
    'time_desc' => 'created_at DESC',
    'time_asc'  => 'created_at ASC',
    'event_asc' => 'event_type ASC',
    'event_desc' => 'event_type DESC',
    'actor_asc' => 'actor_name ASC',
    'actor_desc' => 'actor_name DESC',
    'subject_asc' => 'subject_type ASC',
    'subject_desc' => 'subject_type DESC',
    'id_desc' => 'id DESC',
    'id_asc'  => 'id ASC',
];
$sort = array_key_exists($sortRaw, $sortOptions) ? $sortRaw : 'time_desc';

$activityLogRows = [];
$activityLogError = '';
$totalRows = 0;
$totalPages = 1;
$eventTypeOptions = [];
try {
    $eventStmt = $pdo->query('SELECT DISTINCT event_type FROM activity_log ORDER BY event_type ASC');
    $eventTypeOptions = array_values(array_filter(array_map('trim', $eventStmt->fetchAll(PDO::FETCH_COLUMN))));

    $where  = [];
    $params = [];

    if ($q !== null) {
        $where[] = '(event_type LIKE :q OR actor_name LIKE :q OR actor_email LIKE :q OR subject_type LIKE :q OR subject_id LIKE :q OR message LIKE :q OR metadata LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    if ($eventType !== null) {
        $where[] = 'event_type = :event_type';
        $params[':event_type'] = $eventType;
    }

    if ($dateFrom !== null) {
        $where[] = 'created_at >= :from';
        $params[':from'] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== null) {
        $where[] = 'created_at <= :to';
        $params[':to'] = $dateTo . ' 23:59:59';
    }

    $sql = "
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
    ";
    $countSql = 'SELECT COUNT(*) FROM activity_log';

    if (!empty($where)) {
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $sql .= $whereSql;
        $countSql .= $whereSql;
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $sql .= ' ORDER BY ' . $sortOptions[$sort] . ' LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
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
    <title>Activity Log – SnipeScheduler</title>

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
            <h1>Activity Log</h1>
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

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link active" href="activity_log.php">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="settings.php">Settings</a>
            </li>
        </ul>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-1">Latest activity</h5>
                <p class="text-muted small mb-3">View, filter, and sort activity events.</p>
                <div class="border rounded-3 p-4 mb-4">
                    <form class="row g-3 mb-0 align-items-end" method="get" action="activity_log.php" id="activity-log-filter-form">
                        <div class="col-12 col-lg-4">
                            <input type="text"
                                   name="q"
                                   class="form-control form-control-lg"
                                   placeholder="Search by user, event, subject, or details..."
                                   value="<?= h($qRaw) ?>">
                        </div>
                        <div class="col-12 col-md-6 col-lg-2">
                            <select name="event_type" class="form-select form-select-lg" aria-label="Filter event type">
                                <option value="">All event types</option>
                                <?php foreach ($eventTypeOptions as $opt): ?>
                                    <?php
                                        $label = $eventLabels[$opt] ?? ucwords(str_replace('_', ' ', $opt));
                                    ?>
                                    <option value="<?= h($opt) ?>" <?= $eventType === $opt ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-2">
                            <input type="date"
                                   name="from"
                                   class="form-control form-control-lg"
                                   value="<?= h($fromRaw) ?>"
                                   placeholder="From date">
                        </div>
                        <div class="col-12 col-md-6 col-lg-2">
                            <input type="date"
                                   name="to"
                                   class="form-control form-control-lg"
                                   value="<?= h($toRaw) ?>"
                                   placeholder="To date">
                        </div>
                        <div class="col-12 col-md-6 col-lg-2">
                            <select name="sort" class="form-select form-select-lg" aria-label="Sort activity log">
                                <option value="time_desc" <?= $sort === 'time_desc' ? 'selected' : '' ?>>Time (newest first)</option>
                                <option value="time_asc" <?= $sort === 'time_asc' ? 'selected' : '' ?>>Time (oldest first)</option>
                                <option value="event_asc" <?= $sort === 'event_asc' ? 'selected' : '' ?>>Event (A–Z)</option>
                                <option value="event_desc" <?= $sort === 'event_desc' ? 'selected' : '' ?>>Event (Z–A)</option>
                                <option value="actor_asc" <?= $sort === 'actor_asc' ? 'selected' : '' ?>>User (A–Z)</option>
                                <option value="actor_desc" <?= $sort === 'actor_desc' ? 'selected' : '' ?>>User (Z–A)</option>
                                <option value="subject_asc" <?= $sort === 'subject_asc' ? 'selected' : '' ?>>Subject (A–Z)</option>
                                <option value="subject_desc" <?= $sort === 'subject_desc' ? 'selected' : '' ?>>Subject (Z–A)</option>
                                <option value="id_desc" <?= $sort === 'id_desc' ? 'selected' : '' ?>>Log ID (high → low)</option>
                                <option value="id_asc" <?= $sort === 'id_asc' ? 'selected' : '' ?>>Log ID (low → high)</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-2">
                            <select name="per_page" class="form-select form-select-lg">
                                <?php foreach ($perPageOptions as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>>
                                        <?= $opt ?> per page
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6 col-lg-2 d-flex gap-2">
                            <button class="btn btn-primary w-100" type="submit">Filter</button>
                            <a href="activity_log.php" class="btn btn-outline-secondary w-100">Clear</a>
                        </div>
                    </form>
                </div>
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
                                    <th>User</th>
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

                                    $eventTypeValue = (string)($row['event_type'] ?? '');
                                    $eventLabel = $eventLabels[$eventTypeValue] ?? ucwords(str_replace('_', ' ', $eventTypeValue));

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
                                            $displayTime = app_format_datetime($timestamp, null, $tz);
                                        } catch (Throwable $e) {
                                            $displayTime = $timestamp;
                                        }
                                    }

                                    $metadataText = trim((string)($row['metadata'] ?? ''));
                                    $metadataLines = format_activity_metadata($metadataText, $metadataLabels, $tz);
                                    $subjectDetailHtml = '';
                                    if ($subjectLabel !== '' && ($row['subject_type'] ?? '') === 'reservation' && $subjectId !== '') {
                                        $reservationId = (int)$subjectId;
                                        if ($reservationId > 0) {
                                            if (($row['event_type'] ?? '') === 'reservation_deleted') {
                                                $subjectDetailHtml = 'Reservation Number: #' . $reservationId;
                                            } else {
                                                $subjectDetailHtml = 'Reservation Number: <a href="reservation_detail.php?id='
                                                    . $reservationId . '" target="_blank" rel="noopener noreferrer">#' . $reservationId . '</a>';
                                            }
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td class="text-nowrap"><?= h($displayTime) ?></td>
                                        <td><?= h($eventLabel) ?></td>
                                        <td><?= h($actorLabel) ?></td>
                                        <td>
                                            <div class="fw-semibold"><?= h((string)($row['message'] ?? '')) ?></div>
                                            <?php if ($subjectDetailHtml !== ''): ?>
                                                <div class="text-muted small"><?= $subjectDetailHtml ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($metadataLines)): ?>
                                                <div class="text-muted small">
                                                    <?php foreach ($metadataLines as $line): ?>
                                                        <div><?= h($line) ?></div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php elseif ($metadataText !== ''): ?>
                                                <div class="text-muted small"><code><?= h($metadataText) ?></code></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <?php
                            $pagerQuery = [
                                'q' => $qRaw,
                                'event_type' => $eventRaw,
                                'from' => $fromRaw,
                                'to' => $toRaw,
                                'per_page' => $perPage,
                                'sort' => $sort,
                            ];
                        ?>
                        <nav class="mt-3">
                            <ul class="pagination justify-content-center">
                                <?php
                                    $prevPage = max(1, $page - 1);
                                    $nextPage = min($totalPages, $page + 1);
                                    $pagerQuery['page'] = $prevPage;
                                    $prevUrl = 'activity_log.php?' . http_build_query($pagerQuery);
                                    $pagerQuery['page'] = $nextPage;
                                    $nextUrl = 'activity_log.php?' . http_build_query($pagerQuery);

                                    $windowSize = 2; // show current page +/- 2
                                    $rangeStart = max(1, $page - $windowSize);
                                    $rangeEnd = min($totalPages, $page + $windowSize);

                                    if ($rangeStart <= 3) {
                                        $rangeStart = 1;
                                        $rangeEnd = min($totalPages, 1 + ($windowSize * 2));
                                    }
                                    if ($rangeEnd >= ($totalPages - 2)) {
                                        $rangeEnd = $totalPages;
                                        $rangeStart = max(1, $totalPages - ($windowSize * 2));
                                    }
                                ?>
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= h($prevUrl) ?>">Previous</a>
                                </li>

                                <?php if ($rangeStart > 1): ?>
                                    <?php
                                        $pagerQuery['page'] = 1;
                                        $firstUrl = 'activity_log.php?' . http_build_query($pagerQuery);
                                    ?>
                                    <li class="page-item <?= $page === 1 ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= h($firstUrl) ?>">1</a>
                                    </li>
                                    <?php if ($rangeStart > 2): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">&hellip;</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($p = $rangeStart; $p <= $rangeEnd; $p++): ?>
                                    <?php
                                        $pagerQuery['page'] = $p;
                                        $pageUrl = 'activity_log.php?' . http_build_query($pagerQuery);
                                    ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= h($pageUrl) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($rangeEnd < $totalPages): ?>
                                    <?php if ($rangeEnd < ($totalPages - 1)): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">&hellip;</span>
                                        </li>
                                    <?php endif; ?>
                                    <?php
                                        $pagerQuery['page'] = $totalPages;
                                        $lastUrl = 'activity_log.php?' . http_build_query($pagerQuery);
                                    ?>
                                    <li class="page-item <?= $page === $totalPages ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= h($lastUrl) ?>"><?= $totalPages ?></a>
                                    </li>
                                <?php endif; ?>

                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= h($nextUrl) ?>">Next</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('activity-log-filter-form');
    const sortSelect = form ? form.querySelector('select[name="sort"]') : null;
    const eventSelect = form ? form.querySelector('select[name="event_type"]') : null;
    if (form && sortSelect) {
        sortSelect.addEventListener('change', function () {
            form.submit();
        });
    }
    if (form && eventSelect) {
        eventSelect.addEventListener('change', function () {
            form.submit();
        });
    }
});
</script>
