<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$config = load_config();
$tz = app_get_timezone($config);
if (!$tz) {
    $tz = new DateTimeZone('UTC');
}
$timezoneLabel = (string)($config['app']['timezone'] ?? $tz->getName());
$timeFormat = app_get_time_format($config);

$parseDateInput = static function (string $raw, DateTimeZone $timezone): ?DateTimeImmutable {
    $value = trim($raw);
    if ($value === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
    if (!$dt) {
        return null;
    }
    $errors = DateTimeImmutable::getLastErrors();
    if (
        is_array($errors)
        && ((int)($errors['warning_count'] ?? 0) > 0 || (int)($errors['error_count'] ?? 0) > 0)
    ) {
        return null;
    }
    return $dt;
};

$parseDbDateTime = static function (string $raw, DateTimeZone $timezone): ?DateTimeImmutable {
    $value = trim($raw);
    if ($value === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
    if ($dt instanceof DateTimeImmutable) {
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !is_array($errors)
            || ((int)($errors['warning_count'] ?? 0) === 0 && (int)($errors['error_count'] ?? 0) === 0)
        ) {
            return $dt;
        }
    }

    $fallback = app_parse_datetime_value($value, $timezone);
    if (!$fallback) {
        return null;
    }
    return DateTimeImmutable::createFromMutable($fallback);
};

$overlapMinutes = static function (
    DateTimeImmutable $itemStart,
    DateTimeImmutable $itemEnd,
    DateTimeImmutable $rangeStart,
    DateTimeImmutable $rangeEnd
): float {
    $startTs = max($itemStart->getTimestamp(), $rangeStart->getTimestamp());
    $endTs = min($itemEnd->getTimestamp(), $rangeEnd->getTimestamp());
    if ($endTs <= $startTs) {
        return 0.0;
    }
    return ($endTs - $startTs) / 60;
};

$formatHourRange = static function (int $hour) use ($timeFormat, $tz): string {
    $hour = max(0, min(23, $hour));
    $base = (new DateTimeImmutable('2000-01-01 00:00:00', $tz))->setTime($hour, 0, 0);
    $next = $base->modify('+1 hour');
    return $base->format($timeFormat) . ' - ' . $next->format($timeFormat);
};

$today = new DateTimeImmutable('today', $tz);
$defaultToDate = $today;
$defaultFromDate = $today->sub(new DateInterval('P29D'));

$fromRaw = trim((string)($_GET['from'] ?? ''));
$toRaw = trim((string)($_GET['to'] ?? ''));

$fromDate = $parseDateInput($fromRaw, $tz) ?? $defaultFromDate;
$toDate = $parseDateInput($toRaw, $tz) ?? $defaultToDate;

if ($toDate < $fromDate) {
    $tmp = $fromDate;
    $fromDate = $toDate;
    $toDate = $tmp;
}

$fromValue = $fromDate->format('Y-m-d');
$toValue = $toDate->format('Y-m-d');

$rangeStart = $fromDate->setTime(0, 0, 0);
$rangeEnd = $toDate->modify('+1 day')->setTime(0, 0, 0); // exclusive
$rangeStartSql = $rangeStart->format('Y-m-d H:i:s');
$rangeEndSql = $rangeEnd->format('Y-m-d H:i:s');
$rangeDays = max(1, (int)$fromDate->diff($toDate)->days + 1);

$reportErrors = [];

$statusCounts = [
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'missed' => 0,
];

$dailyCancelledMissedRows = [];
$hourlyUnitMinutes = array_fill(0, 24, 0.0);
$categoryRows = [];
$categoryLookupFailures = 0;
$legacyReservationsWithoutItems = 0;

try {
    $statusStmt = $pdo->prepare("
        SELECT status, COUNT(*) AS c
          FROM reservations
         WHERE start_datetime >= :range_start
           AND start_datetime < :range_end
         GROUP BY status
    ");
    $statusStmt->execute([
        ':range_start' => $rangeStartSql,
        ':range_end' => $rangeEndSql,
    ]);
    foreach ($statusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = strtolower(trim((string)($row['status'] ?? '')));
        $count = (int)($row['c'] ?? 0);
        if (array_key_exists($status, $statusCounts)) {
            $statusCounts[$status] = $count;
        }
    }
} catch (Throwable $e) {
    $reportErrors[] = 'Could not load cancellation/no-show totals.';
}

try {
    $dailyStmt = $pdo->prepare("
        SELECT DATE(start_datetime) AS report_day,
               SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
               SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) AS missed_count
          FROM reservations
         WHERE start_datetime >= :range_start
           AND start_datetime < :range_end
         GROUP BY DATE(start_datetime)
         ORDER BY DATE(start_datetime) ASC
    ");
    $dailyStmt->execute([
        ':range_start' => $rangeStartSql,
        ':range_end' => $rangeEndSql,
    ]);
    $dailyCancelledMissedRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $reportErrors[] = 'Could not load daily cancellation/no-show trend.';
}

try {
    $demandStmt = $pdo->prepare("
        SELECT r.id,
               r.start_datetime,
               r.end_datetime,
               MAX(r.asset_id) AS asset_id,
               COALESCE(SUM(ri.quantity), 0) AS qty
          FROM reservations r
          LEFT JOIN reservation_items ri ON ri.reservation_id = r.id
         WHERE r.status IN ('pending','confirmed','completed','missed')
           AND r.start_datetime < :range_end
           AND r.end_datetime > :range_start
         GROUP BY r.id, r.start_datetime, r.end_datetime
    ");
    $demandStmt->execute([
        ':range_start' => $rangeStartSql,
        ':range_end' => $rangeEndSql,
    ]);
    $demandRows = $demandStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($demandRows as $row) {
        $startDt = $parseDbDateTime((string)($row['start_datetime'] ?? ''), $tz);
        $endDt = $parseDbDateTime((string)($row['end_datetime'] ?? ''), $tz);
        if (!$startDt || !$endDt || $endDt <= $startDt) {
            continue;
        }

        $qty = (int)($row['qty'] ?? 0);
        $assetId = (int)($row['asset_id'] ?? 0);
        if ($qty <= 0 && $assetId > 0) {
            $qty = 1; // legacy single-asset reservations without reservation_items rows
            $legacyReservationsWithoutItems++;
        }
        if ($qty <= 0) {
            continue;
        }

        $windowStartTs = max($startDt->getTimestamp(), $rangeStart->getTimestamp());
        $windowEndTs = min($endDt->getTimestamp(), $rangeEnd->getTimestamp());
        if ($windowEndTs <= $windowStartTs) {
            continue;
        }

        $cursorTs = $windowStartTs;
        while ($cursorTs < $windowEndTs) {
            $cursorDt = (new DateTimeImmutable('@' . $cursorTs))->setTimezone($tz);
            $hour = (int)$cursorDt->format('G');
            $nextHourTs = $cursorDt->modify('+1 hour')->getTimestamp();
            if ($nextHourTs <= $cursorTs) {
                $nextHourTs = $cursorTs + 3600;
            }

            $chunkEndTs = min($windowEndTs, $nextHourTs);
            $chunkMinutes = ($chunkEndTs - $cursorTs) / 60;
            if ($chunkMinutes > 0 && isset($hourlyUnitMinutes[$hour])) {
                $hourlyUnitMinutes[$hour] += ($chunkMinutes * $qty);
            }
            $cursorTs = $chunkEndTs;
        }
    }
} catch (Throwable $e) {
    $reportErrors[] = 'Could not load peak demand data.';
}

try {
    $itemStmt = $pdo->prepare("
        SELECT r.id AS reservation_id,
               ri.model_id,
               ri.quantity,
               r.start_datetime,
               r.end_datetime
          FROM reservation_items ri
          JOIN reservations r ON r.id = ri.reservation_id
         WHERE r.status IN ('pending','confirmed','completed','missed')
           AND r.start_datetime < :range_end
           AND r.end_datetime > :range_start
    ");
    $itemStmt->execute([
        ':range_start' => $rangeStartSql,
        ':range_end' => $rangeEndSql,
    ]);
    $itemRows = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $modelStats = [];
    foreach ($itemRows as $row) {
        $modelId = (int)($row['model_id'] ?? 0);
        $qty = max(0, (int)($row['quantity'] ?? 0));
        $reservationId = (int)($row['reservation_id'] ?? 0);
        if ($modelId <= 0 || $qty <= 0 || $reservationId <= 0) {
            continue;
        }

        $startDt = $parseDbDateTime((string)($row['start_datetime'] ?? ''), $tz);
        $endDt = $parseDbDateTime((string)($row['end_datetime'] ?? ''), $tz);
        if (!$startDt || !$endDt || $endDt <= $startDt) {
            continue;
        }

        $minutes = $overlapMinutes($startDt, $endDt, $rangeStart, $rangeEnd);
        if ($minutes <= 0) {
            continue;
        }

        if (!isset($modelStats[$modelId])) {
            $modelStats[$modelId] = [
                'unit_minutes' => 0.0,
                'reservation_ids' => [],
            ];
        }
        $modelStats[$modelId]['unit_minutes'] += ($minutes * $qty);
        $modelStats[$modelId]['reservation_ids'][$reservationId] = true;
    }

    $categoryAggregates = [];
    foreach ($modelStats as $modelId => $modelStat) {
        $modelCategory = 'Unknown category';
        try {
            $model = get_model((int)$modelId);
            $category = $model['category'] ?? null;
            if (is_array($category)) {
                $name = trim((string)($category['name'] ?? ($category['category_name'] ?? '')));
                if ($name !== '') {
                    $modelCategory = $name;
                }
            }
        } catch (Throwable $e) {
            $categoryLookupFailures++;
        }

        if (!isset($categoryAggregates[$modelCategory])) {
            $categoryAggregates[$modelCategory] = [
                'unit_minutes' => 0.0,
                'model_ids' => [],
                'reservation_ids' => [],
            ];
        }
        $categoryAggregates[$modelCategory]['unit_minutes'] += (float)$modelStat['unit_minutes'];
        $categoryAggregates[$modelCategory]['model_ids'][$modelId] = true;
        foreach ($modelStat['reservation_ids'] as $reservationId => $_true) {
            $categoryAggregates[$modelCategory]['reservation_ids'][(int)$reservationId] = true;
        }
    }

    uasort($categoryAggregates, static function (array $a, array $b): int {
        return $b['unit_minutes'] <=> $a['unit_minutes'];
    });

    $totalCategoryMinutes = 0.0;
    foreach ($categoryAggregates as $aggregate) {
        $totalCategoryMinutes += (float)$aggregate['unit_minutes'];
    }

    foreach ($categoryAggregates as $categoryName => $aggregate) {
        $minutes = (float)$aggregate['unit_minutes'];
        $categoryRows[] = [
            'category' => (string)$categoryName,
            'unit_hours' => $minutes / 60,
            'share_pct' => $totalCategoryMinutes > 0 ? ($minutes / $totalCategoryMinutes) * 100 : 0,
            'model_count' => count($aggregate['model_ids']),
            'reservation_count' => count($aggregate['reservation_ids']),
        ];
    }
} catch (Throwable $e) {
    $reportErrors[] = 'Could not load category utilization data.';
}

$totalReservations = array_sum($statusCounts);
$cancelledCount = (int)($statusCounts['cancelled'] ?? 0);
$missedCount = (int)($statusCounts['missed'] ?? 0);
$cancelledRate = $totalReservations > 0 ? ($cancelledCount / $totalReservations) * 100 : 0;
$missedRate = $totalReservations > 0 ? ($missedCount / $totalReservations) * 100 : 0;

$maxHourlyMinutes = 0.0;
$peakHour = 0;
foreach ($hourlyUnitMinutes as $hour => $minutes) {
    if ($minutes > $maxHourlyMinutes) {
        $maxHourlyMinutes = $minutes;
        $peakHour = (int)$hour;
    }
}
$peakAvgUnits = $rangeDays > 0 ? ($maxHourlyMinutes / ($rangeDays * 60)) : 0.0;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports – SnipeScheduler</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles($config) ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag($config) ?>
        <div class="page-header">
            <h1>Reports</h1>
            <div class="page-subtitle">
                Utilization by category, peak demand hours, and cancellation/no-show analytics.
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

        <?php if (!empty($reportErrors)): ?>
            <div class="alert alert-warning">
                <?= implode('<br>', array_map('h', $reportErrors)) ?>
            </div>
        <?php endif; ?>

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link" href="activity_log.php">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="settings.php">Settings</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="announcements.php">Announcements</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="reports.php">Reports</a>
            </li>
        </ul>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title mb-1">Report Window</h5>
                <p class="text-muted small mb-3">
                    Showing data from <strong><?= h(app_format_date($fromValue . ' 00:00:00', $config, $tz)) ?></strong>
                    to <strong><?= h(app_format_date($toValue . ' 00:00:00', $config, $tz)) ?></strong> (<?= (int)$rangeDays ?> day<?= $rangeDays === 1 ? '' : 's' ?>).
                </p>
                <div class="form-text mb-3">Time zone: <?= h($timezoneLabel) ?>.</div>
                <form method="get" action="reports.php" class="row g-3 align-items-end">
                    <div class="col-12 col-md-4 col-lg-3">
                        <label class="form-label">From date</label>
                        <input type="date" name="from" class="form-control form-control-lg" value="<?= h($fromValue) ?>">
                    </div>
                    <div class="col-12 col-md-4 col-lg-3">
                        <label class="form-label">To date</label>
                        <input type="date" name="to" class="form-control form-control-lg" value="<?= h($toValue) ?>">
                    </div>
                    <div class="col-12 col-md-4 col-lg-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">Apply</button>
                        <a href="reports.php" class="btn btn-outline-secondary w-100">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold">Reservations in window</div>
                        <div class="display-6 mt-1 mb-0"><?= (int)$totalReservations ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold">Cancelled</div>
                        <div class="display-6 mt-1 mb-0"><?= (int)$cancelledCount ?></div>
                        <div class="text-muted small mt-1"><?= number_format($cancelledRate, 1) ?>% of reservations</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold">No-shows (missed)</div>
                        <div class="display-6 mt-1 mb-0"><?= (int)$missedCount ?></div>
                        <div class="text-muted small mt-1"><?= number_format($missedRate, 1) ?>% of reservations</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title mb-1">Utilization by Category</h5>
                <p class="text-muted small mb-3">
                    Based on booked unit-hours from reservations overlapping the report window.
                </p>

                <?php if (empty($categoryRows)): ?>
                    <div class="text-muted small">No reservation item utilization data found for this range.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Category</th>
                                <th class="text-end">Unit-hours booked</th>
                                <th class="text-end">Share</th>
                                <th class="text-end">Models</th>
                                <th class="text-end">Reservations</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($categoryRows as $row): ?>
                                <tr>
                                    <td><?= h($row['category']) ?></td>
                                    <td class="text-end"><?= number_format((float)$row['unit_hours'], 1) ?></td>
                                    <td class="text-end"><?= number_format((float)$row['share_pct'], 1) ?>%</td>
                                    <td class="text-end"><?= (int)$row['model_count'] ?></td>
                                    <td class="text-end"><?= (int)$row['reservation_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($categoryLookupFailures > 0): ?>
                        <div class="form-text mt-2">
                            <?= (int)$categoryLookupFailures ?> model<?= $categoryLookupFailures === 1 ? '' : 's' ?> could not be mapped to a Snipe-IT category and were grouped as "Unknown category".
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title mb-1">Peak Demand Hours</h5>
                <p class="text-muted small mb-3">
                    Hour-of-day demand measured as booked unit-hours and average concurrent units.
                </p>

                <?php if ($maxHourlyMinutes <= 0): ?>
                    <div class="text-muted small">No overlapping reservation demand found for this range.</div>
                <?php else: ?>
                    <div class="alert alert-light border small mb-3">
                        Peak hour: <strong><?= h($formatHourRange($peakHour)) ?></strong>
                        with an average of <strong><?= number_format($peakAvgUnits, 2) ?></strong> units booked.
                        <?php if ($legacyReservationsWithoutItems > 0): ?>
                            Legacy single-asset reservations without item rows included: <?= (int)$legacyReservationsWithoutItems ?>.
                        <?php endif; ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Hour</th>
                                <th class="text-end">Unit-hours</th>
                                <th class="text-end">Avg concurrent units</th>
                                <th style="width: 42%;">Demand</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($hourlyUnitMinutes as $hour => $minutes): ?>
                                <?php
                                $unitHours = $minutes / 60;
                                $avgUnits = $rangeDays > 0 ? $minutes / ($rangeDays * 60) : 0;
                                $barPct = $maxHourlyMinutes > 0 ? ($minutes / $maxHourlyMinutes) * 100 : 0;
                                ?>
                                <tr>
                                    <td><?= h($formatHourRange((int)$hour)) ?></td>
                                    <td class="text-end"><?= number_format($unitHours, 1) ?></td>
                                    <td class="text-end"><?= number_format($avgUnits, 2) ?></td>
                                    <td>
                                        <div class="progress" role="progressbar" aria-valuenow="<?= (int)round($barPct) ?>" aria-valuemin="0" aria-valuemax="100" style="height: .85rem;">
                                            <div class="progress-bar" style="width: <?= max(0, min(100, $barPct)) ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title mb-1">Cancellations and No-shows</h5>
                <p class="text-muted small mb-3">
                    Daily counts by reservation start date.
                </p>

                <?php if (empty($dailyCancelledMissedRows)): ?>
                    <div class="text-muted small">No cancellation/no-show events found for this range.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-end">Cancelled</th>
                                <th class="text-end">No-shows (missed)</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($dailyCancelledMissedRows as $row): ?>
                                <?php
                                $dayText = trim((string)($row['report_day'] ?? ''));
                                $displayDay = $dayText !== ''
                                    ? app_format_date($dayText . ' 00:00:00', $config, $tz)
                                    : '';
                                ?>
                                <tr>
                                    <td><?= h($displayDay) ?></td>
                                    <td class="text-end"><?= (int)($row['cancelled_count'] ?? 0) ?></td>
                                    <td class="text-end"><?= (int)($row['missed_count'] ?? 0) ?></td>
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
