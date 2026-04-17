<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/booking_helpers.php';

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
$defaultFromDate = $today->sub(new DateInterval('P6D'));

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

$requestedCategoryPage = max(1, (int)($_GET['category_page'] ?? 1));
$requestedModelPage = max(1, (int)($_GET['model_page'] ?? 1));
$requestedAccessoryPage = max(1, (int)($_GET['accessory_page'] ?? 1));
$requestedKitPage = max(1, (int)($_GET['kit_page'] ?? 1));
$requestedDemandPage = max(1, (int)($_GET['demand_page'] ?? 1));
$requestedCancellationsPage = max(1, (int)($_GET['cancellations_page'] ?? 1));

$normaliseSortKey = static function (string $raw, array $allowed, string $default): string {
    return in_array($raw, $allowed, true) ? $raw : $default;
};

$normaliseSortDir = static function (string $raw, string $default): string {
    $value = strtolower(trim($raw));
    return in_array($value, ['asc', 'desc'], true) ? $value : $default;
};

$categorySort = $normaliseSortKey(
    (string)($_GET['category_sort'] ?? ''),
    ['category', 'unit_hours', 'share_pct', 'model_count', 'reservation_count'],
    'unit_hours'
);
$categoryDir = $normaliseSortDir((string)($_GET['category_dir'] ?? ''), 'desc');

$modelSort = $normaliseSortKey(
    (string)($_GET['model_sort'] ?? ''),
    ['model_name', 'category', 'unit_hours', 'share_pct', 'reservation_count'],
    'unit_hours'
);
$modelDir = $normaliseSortDir((string)($_GET['model_dir'] ?? ''), 'desc');

$accessorySort = $normaliseSortKey(
    (string)($_GET['accessory_sort'] ?? ''),
    ['accessory_name', 'category', 'unit_hours', 'share_pct', 'reservation_count', 'quantity_booked'],
    'unit_hours'
);
$accessoryDir = $normaliseSortDir((string)($_GET['accessory_dir'] ?? ''), 'desc');

$kitSort = $normaliseSortKey(
    (string)($_GET['kit_sort'] ?? ''),
    ['kit_name', 'component_unit_hours', 'reservation_count', 'model_count', 'accessory_count', 'available_sets'],
    'component_unit_hours'
);
$kitDir = $normaliseSortDir((string)($_GET['kit_dir'] ?? ''), 'desc');

$demandSort = $normaliseSortKey(
    (string)($_GET['demand_sort'] ?? ''),
    ['hour', 'unit_hours', 'avg_units', 'bar_pct'],
    'hour'
);
$demandDir = $normaliseSortDir((string)($_GET['demand_dir'] ?? ''), 'asc');

$cancellationsSort = $normaliseSortKey(
    (string)($_GET['cancellations_sort'] ?? ''),
    ['report_day', 'cancelled_count', 'missed_count'],
    'report_day'
);
$cancellationsDir = $normaliseSortDir((string)($_GET['cancellations_dir'] ?? ''), 'asc');

$sortRows = static function (array &$rows, string $sortKey, string $direction): void {
    usort($rows, static function (array $a, array $b) use ($sortKey, $direction): int {
        $av = $a[$sortKey] ?? null;
        $bv = $b[$sortKey] ?? null;

        if (is_numeric($av) && is_numeric($bv)) {
            $cmp = ((float)$av <=> (float)$bv);
        } else {
            $cmp = strnatcasecmp((string)$av, (string)$bv);
        }

        return $direction === 'desc' ? -$cmp : $cmp;
    });
};

$paginateRows = static function (array $rows, int $requestedPage, int $perPage): array {
    $total = count($rows);
    $safePerPage = max(1, $perPage);
    $totalPages = max(1, (int)ceil($total / $safePerPage));
    $page = min(max(1, $requestedPage), $totalPages);
    $offset = ($page - 1) * $safePerPage;
    $slice = array_slice($rows, $offset, $safePerPage);

    if ($total === 0) {
        $startIndex = 0;
        $endIndex = 0;
    } else {
        $startIndex = $offset + 1;
        $endIndex = min($total, $offset + count($slice));
    }

    return [
        'rows' => $slice,
        'total' => $total,
        'page' => $page,
        'per_page' => $safePerPage,
        'total_pages' => $totalPages,
        'start_index' => $startIndex,
        'end_index' => $endIndex,
    ];
};

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
$modelRows = [];
$accessoryRows = [];
$kitRows = [];
$modelStatsById = [];
$accessoryStatsById = [];
$categoryLookupFailures = 0;
$accessoryLookupFailures = 0;
$kitLookupFailures = 0;
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
    $itemSql = "
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
    ";
    if (booking_reservation_items_have_typed_columns($pdo)) {
        $itemSql .= " AND COALESCE(NULLIF(ri.item_type, ''), 'model') = 'model'";
    }

    $itemStmt = $pdo->prepare($itemSql);
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
    $modelStatsById = $modelStats;

    $categoryAggregates = [];
    $totalModelMinutes = 0.0;
    foreach ($modelStats as $modelId => $modelStat) {
        $modelName = 'Model #' . (int)$modelId;
        $modelCategory = 'Unknown category';
        $modelImagePath = '';
        try {
            $model = get_model((int)$modelId);
            $name = trim((string)($model['name'] ?? ''));
            if ($name !== '') {
                $modelName = $name;
            }
            $imagePath = trim((string)($model['image'] ?? ''));
            if ($imagePath !== '') {
                $modelImagePath = $imagePath;
            }
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
        $minutes = (float)$modelStat['unit_minutes'];
        $categoryAggregates[$modelCategory]['unit_minutes'] += $minutes;
        $categoryAggregates[$modelCategory]['model_ids'][$modelId] = true;
        foreach ($modelStat['reservation_ids'] as $reservationId => $_true) {
            $categoryAggregates[$modelCategory]['reservation_ids'][(int)$reservationId] = true;
        }

        $totalModelMinutes += $minutes;
        $modelRows[] = [
            'model_id' => (int)$modelId,
            'model_name' => $modelName,
            'model_image_url' => $modelImagePath !== ''
                ? 'image_proxy.php?src=' . urlencode($modelImagePath)
                : '',
            'category' => $modelCategory,
            'unit_minutes' => $minutes,
            'reservation_count' => count($modelStat['reservation_ids']),
        ];
    }

    usort($modelRows, static function (array $a, array $b): int {
        return (float)$b['unit_minutes'] <=> (float)$a['unit_minutes'];
    });

    foreach ($modelRows as $index => $row) {
        $minutes = (float)($row['unit_minutes'] ?? 0);
        $modelRows[$index]['unit_hours'] = $minutes / 60;
        $modelRows[$index]['share_pct'] = $totalModelMinutes > 0 ? ($minutes / $totalModelMinutes) * 100 : 0;
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
    $reportErrors[] = 'Could not load category utilisation data.';
}

try {
    if (booking_reservation_items_have_typed_columns($pdo)) {
        $accessoryStmt = $pdo->prepare("
            SELECT r.id AS reservation_id,
                   ri.item_id,
                   ri.item_name_cache,
                   ri.quantity,
                   r.start_datetime,
                   r.end_datetime
              FROM reservation_items ri
              JOIN reservations r ON r.id = ri.reservation_id
             WHERE r.status IN ('pending','confirmed','completed','missed')
               AND r.start_datetime < :range_end
               AND r.end_datetime > :range_start
               AND COALESCE(NULLIF(ri.item_type, ''), 'model') = 'accessory'
        ");
        $accessoryStmt->execute([
            ':range_start' => $rangeStartSql,
            ':range_end' => $rangeEndSql,
        ]);
        $accessoryItemRows = $accessoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $accessoryStats = [];
        foreach ($accessoryItemRows as $row) {
            $accessoryId = (int)($row['item_id'] ?? 0);
            $qty = max(0, (int)($row['quantity'] ?? 0));
            $reservationId = (int)($row['reservation_id'] ?? 0);
            if ($accessoryId <= 0 || $qty <= 0 || $reservationId <= 0) {
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

            if (!isset($accessoryStats[$accessoryId])) {
                $fallbackName = trim((string)($row['item_name_cache'] ?? ''));
                $accessoryStats[$accessoryId] = [
                    'unit_minutes' => 0.0,
                    'quantity_booked' => 0,
                    'reservation_ids' => [],
                    'fallback_name' => $fallbackName !== '' ? $fallbackName : ('Accessory #' . $accessoryId),
                ];
            }
            $accessoryStats[$accessoryId]['unit_minutes'] += ($minutes * $qty);
            $accessoryStats[$accessoryId]['quantity_booked'] += $qty;
            $accessoryStats[$accessoryId]['reservation_ids'][$reservationId] = true;
        }
        $accessoryStatsById = $accessoryStats;

        $totalAccessoryMinutes = 0.0;
        foreach ($accessoryStats as $stat) {
            $totalAccessoryMinutes += (float)$stat['unit_minutes'];
        }

        foreach ($accessoryStats as $accessoryId => $stat) {
            $accessoryName = (string)($stat['fallback_name'] ?? ('Accessory #' . (int)$accessoryId));
            $accessoryCategory = 'Unknown category';
            $accessoryImagePath = '';
            try {
                $accessory = get_accessory((int)$accessoryId);
                $name = trim((string)($accessory['name'] ?? ''));
                if ($name !== '') {
                    $accessoryName = $name;
                }
                $accessoryImagePath = booking_extract_catalogue_item_image_path($accessory);
                $category = $accessory['category'] ?? null;
                if (is_array($category)) {
                    $name = trim((string)($category['name'] ?? ($category['category_name'] ?? '')));
                    if ($name !== '') {
                        $accessoryCategory = $name;
                    }
                } else {
                    $name = trim((string)($accessory['category_name'] ?? ''));
                    if ($name !== '') {
                        $accessoryCategory = $name;
                    }
                }
            } catch (Throwable $e) {
                $accessoryLookupFailures++;
            }

            $minutes = (float)$stat['unit_minutes'];
            $accessoryRows[] = [
                'accessory_id' => (int)$accessoryId,
                'accessory_name' => $accessoryName,
                'accessory_image_url' => $accessoryImagePath !== ''
                    ? 'image_proxy.php?src=' . urlencode($accessoryImagePath)
                    : '',
                'category' => $accessoryCategory,
                'unit_hours' => $minutes / 60,
                'share_pct' => $totalAccessoryMinutes > 0 ? ($minutes / $totalAccessoryMinutes) * 100 : 0,
                'reservation_count' => count($stat['reservation_ids']),
                'quantity_booked' => (int)$stat['quantity_booked'],
            ];
        }
    }
} catch (Throwable $e) {
    $reportErrors[] = 'Could not load accessory utilisation data.';
}

try {
    if (booking_reservation_items_have_typed_columns($pdo)) {
        $kits = fetch_all_kits_from_snipeit('', true);
        foreach ($kits as $kit) {
            if (!is_array($kit)) {
                continue;
            }
            $kitId = (int)($kit['id'] ?? 0);
            if ($kitId <= 0) {
                continue;
            }

            try {
                $breakdown = get_kit_booking_breakdown($kitId);
            } catch (Throwable $e) {
                $kitLookupFailures++;
                continue;
            }

            $kitName = trim((string)($breakdown['kit']['name'] ?? ($kit['name'] ?? ('Kit #' . $kitId))));
            $supportedItems = $breakdown['supported_items'] ?? [];
            if (!is_array($supportedItems) || empty($supportedItems)) {
                continue;
            }

            $modelCount = 0;
            $accessoryCount = 0;
            $componentMinutes = 0.0;
            $reservationIds = [];
            $availableSets = null;
            $availableSetsUnknown = false;

            foreach ($supportedItems as $supportedItem) {
                if (!is_array($supportedItem)) {
                    continue;
                }
                $itemType = booking_normalize_item_type((string)($supportedItem['type'] ?? 'model'));
                $itemId = (int)($supportedItem['id'] ?? 0);
                $perKitQty = max(1, (int)($supportedItem['qty'] ?? 1));
                if ($itemId <= 0) {
                    continue;
                }

                if ($itemType === 'accessory') {
                    $accessoryCount++;
                    if (isset($accessoryStatsById[$itemId])) {
                        $componentMinutes += (float)$accessoryStatsById[$itemId]['unit_minutes'];
                        foreach ($accessoryStatsById[$itemId]['reservation_ids'] as $reservationId => $_true) {
                            $reservationIds[(int)$reservationId] = true;
                        }
                    }
                } else {
                    $modelCount++;
                    if (isset($modelStatsById[$itemId])) {
                        $componentMinutes += (float)$modelStatsById[$itemId]['unit_minutes'];
                        foreach ($modelStatsById[$itemId]['reservation_ids'] as $reservationId => $_true) {
                            $reservationIds[(int)$reservationId] = true;
                        }
                    }
                }

                if (!$availableSetsUnknown) {
                    try {
                        $itemAvailableSets = intdiv(booking_get_requestable_total_for_item($itemType, $itemId), $perKitQty);
                        $availableSets = $availableSets === null
                            ? $itemAvailableSets
                            : min($availableSets, $itemAvailableSets);
                    } catch (Throwable $e) {
                        $availableSets = null;
                        $availableSetsUnknown = true;
                    }
                }
            }

            $kitRows[] = [
                'kit_id' => $kitId,
                'kit_name' => $kitName !== '' ? $kitName : ('Kit #' . $kitId),
                'component_unit_hours' => $componentMinutes / 60,
                'reservation_count' => count($reservationIds),
                'model_count' => $modelCount,
                'accessory_count' => $accessoryCount,
                'available_sets' => $availableSets,
                'unsupported_count' => count($breakdown['unsupported_items'] ?? []),
            ];
        }
    }
} catch (Throwable $e) {
    $reportErrors[] = 'Could not load kit report data.';
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

$hourDemandRows = [];
foreach ($hourlyUnitMinutes as $hour => $minutes) {
    $hourDemandRows[] = [
        'hour' => (int)$hour,
        'unit_hours' => $minutes / 60,
        'avg_units' => $rangeDays > 0 ? $minutes / ($rangeDays * 60) : 0,
        'bar_pct' => $maxHourlyMinutes > 0 ? ($minutes / $maxHourlyMinutes) * 100 : 0,
    ];
}

$sortRows($categoryRows, $categorySort, $categoryDir);
$sortRows($modelRows, $modelSort, $modelDir);
$sortRows($accessoryRows, $accessorySort, $accessoryDir);
$sortRows($kitRows, $kitSort, $kitDir);
$sortRows($hourDemandRows, $demandSort, $demandDir);
$sortRows($dailyCancelledMissedRows, $cancellationsSort, $cancellationsDir);

$categoryPagination = $paginateRows($categoryRows, $requestedCategoryPage, 15);
$categoryRowsPage = $categoryPagination['rows'];

$modelPagination = $paginateRows($modelRows, $requestedModelPage, 20);
$modelRowsPage = $modelPagination['rows'];

$accessoryPagination = $paginateRows($accessoryRows, $requestedAccessoryPage, 15);
$accessoryRowsPage = $accessoryPagination['rows'];

$kitPagination = $paginateRows($kitRows, $requestedKitPage, 10);
$kitRowsPage = $kitPagination['rows'];

$demandPagination = $paginateRows($hourDemandRows, $requestedDemandPage, 12);
$hourDemandRowsPage = $demandPagination['rows'];

$cancellationsPagination = $paginateRows($dailyCancelledMissedRows, $requestedCancellationsPage, 10);
$dailyCancelledMissedRowsPage = $cancellationsPagination['rows'];

$paginationBaseParams = [
    'from' => $fromValue,
    'to' => $toValue,
    'category_page' => $categoryPagination['page'],
    'model_page' => $modelPagination['page'],
    'accessory_page' => $accessoryPagination['page'],
    'kit_page' => $kitPagination['page'],
    'demand_page' => $demandPagination['page'],
    'cancellations_page' => $cancellationsPagination['page'],
    'category_sort' => $categorySort,
    'category_dir' => $categoryDir,
    'model_sort' => $modelSort,
    'model_dir' => $modelDir,
    'accessory_sort' => $accessorySort,
    'accessory_dir' => $accessoryDir,
    'kit_sort' => $kitSort,
    'kit_dir' => $kitDir,
    'demand_sort' => $demandSort,
    'demand_dir' => $demandDir,
    'cancellations_sort' => $cancellationsSort,
    'cancellations_dir' => $cancellationsDir,
];

$buildPageUrl = static function (string $pageKey, int $targetPage) use ($paginationBaseParams): string {
    $params = $paginationBaseParams;
    $params[$pageKey] = max(1, $targetPage);
    return 'reports.php?' . http_build_query($params);
};

$buildSortUrl = static function (
    string $sortParam,
    string $dirParam,
    string $pageParam,
    string $columnKey,
    string $currentSort,
    string $currentDir
) use ($paginationBaseParams): string {
    $params = $paginationBaseParams;
    $params[$sortParam] = $columnKey;
    $params[$dirParam] = ($currentSort === $columnKey && $currentDir === 'asc') ? 'desc' : 'asc';
    $params[$pageParam] = 1;
    return 'reports.php?' . http_build_query($params);
};

$renderPagination = static function (array $pagination, string $pageKey) use ($buildPageUrl): string {
    $totalPages = (int)($pagination['total_pages'] ?? 1);
    $currentPage = (int)($pagination['page'] ?? 1);
    if ($totalPages <= 1) {
        return '';
    }

    $window = 2;
    $startPage = max(1, $currentPage - $window);
    $endPage = min($totalPages, $currentPage + $window);

    if (($endPage - $startPage + 1) < 5) {
        if ($startPage === 1) {
            $endPage = min($totalPages, $startPage + 4);
        } elseif ($endPage === $totalPages) {
            $startPage = max(1, $endPage - 4);
        }
    }

    $html = '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';

    $prevDisabled = $currentPage <= 1 ? ' disabled' : '';
    $prevHref = $currentPage <= 1 ? '#' : h($buildPageUrl($pageKey, $currentPage - 1));
    $html .= '<li class="page-item' . $prevDisabled . '"><a class="page-link js-report-page-link" href="' . $prevHref . '">Previous</a></li>';

    for ($pageNum = $startPage; $pageNum <= $endPage; $pageNum++) {
        $isActive = $pageNum === $currentPage;
        $itemClass = 'page-item' . ($isActive ? ' active' : '');
        $href = $isActive ? '#' : h($buildPageUrl($pageKey, $pageNum));
        $html .= '<li class="' . $itemClass . '"><a class="page-link js-report-page-link" href="' . $href . '">' . $pageNum . '</a></li>';
    }

    $nextDisabled = $currentPage >= $totalPages ? ' disabled' : '';
    $nextHref = $currentPage >= $totalPages ? '#' : h($buildPageUrl($pageKey, $currentPage + 1));
    $html .= '<li class="page-item' . $nextDisabled . '"><a class="page-link js-report-page-link" href="' . $nextHref . '">Next</a></li>';
    $html .= '</ul></nav>';

    return $html;
};

$renderSortableHeader = static function (
    string $label,
    string $columnKey,
    string $currentSort,
    string $currentDir,
    string $sortParam,
    string $dirParam,
    string $pageParam
) use ($buildSortUrl): string {
    $href = h($buildSortUrl($sortParam, $dirParam, $pageParam, $columnKey, $currentSort, $currentDir));
    $isActive = $currentSort === $columnKey;
    $indicator = '';
    if ($isActive) {
        $indicator = $currentDir === 'asc' ? ' (asc)' : ' (desc)';
    }

    return '<a class="link-body-emphasis text-decoration-none js-report-sort-link" href="'
        . $href
        . '">'
        . h($label)
        . '<span class="text-muted small">'
        . h($indicator)
        . '</span></a>';
};
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports – <?= h(layout_app_name($config)) ?></title>
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
                Utilisation by category, model, and accessory, kit component demand, peak hours, and cancellation/no-show analytics.
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
                <h5 class="card-title mb-1">Utilisation by Category</h5>
                <p class="text-muted small mb-3">
                    Based on booked unit-hours from reservations overlapping the report window.
                </p>

                <?php if ((int)$categoryPagination['total'] === 0): ?>
                    <div class="text-muted small">No reservation item utilisation data found for this range.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th><?= $renderSortableHeader('Category', 'category', $categorySort, $categoryDir, 'category_sort', 'category_dir', 'category_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Unit-hours booked', 'unit_hours', $categorySort, $categoryDir, 'category_sort', 'category_dir', 'category_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Share', 'share_pct', $categorySort, $categoryDir, 'category_sort', 'category_dir', 'category_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Models', 'model_count', $categorySort, $categoryDir, 'category_sort', 'category_dir', 'category_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Reservations', 'reservation_count', $categorySort, $categoryDir, 'category_sort', 'category_dir', 'category_page') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($categoryRowsPage as $row): ?>
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
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                        <div class="form-text mb-0">
                            Showing <?= (int)$categoryPagination['start_index'] ?>-<?= (int)$categoryPagination['end_index'] ?>
                            of <?= (int)$categoryPagination['total'] ?> categories.
                        </div>
                        <?= $renderPagination($categoryPagination, 'category_page') ?>
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
                <h5 class="card-title mb-1">Utilisation by Model</h5>
                <p class="text-muted small mb-3">
                    Model-level booked unit-hours and share of total model utilisation.
                </p>

                <?php if ((int)$modelPagination['total'] === 0): ?>
                    <div class="text-muted small">No model utilisation data found for this range.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th><?= $renderSortableHeader('Model', 'model_name', $modelSort, $modelDir, 'model_sort', 'model_dir', 'model_page') ?></th>
                                <th><?= $renderSortableHeader('Category', 'category', $modelSort, $modelDir, 'model_sort', 'model_dir', 'model_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Unit-hours booked', 'unit_hours', $modelSort, $modelDir, 'model_sort', 'model_dir', 'model_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Share', 'share_pct', $modelSort, $modelDir, 'model_sort', 'model_dir', 'model_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Reservations', 'reservation_count', $modelSort, $modelDir, 'model_sort', 'model_dir', 'model_page') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($modelRowsPage as $row): ?>
                                <tr>
                                    <td>
                                        <div class="report-model-cell">
                                            <?php $modelImageUrl = trim((string)($row['model_image_url'] ?? '')); ?>
                                            <?php if ($modelImageUrl !== ''): ?>
                                                <img src="<?= h($modelImageUrl) ?>"
                                                     alt="<?= h((string)$row['model_name']) ?>"
                                                     class="report-model-thumb"
                                                     loading="lazy">
                                            <?php else: ?>
                                                <div class="report-model-thumb report-model-thumb--placeholder" aria-hidden="true">-</div>
                                            <?php endif; ?>
                                            <div class="report-model-cell__text">
                                                <div><?= h((string)$row['model_name']) ?></div>
                                                <span class="text-muted small">ID <?= (int)$row['model_id'] ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= h((string)$row['category']) ?></td>
                                    <td class="text-end"><?= number_format((float)$row['unit_hours'], 1) ?></td>
                                    <td class="text-end"><?= number_format((float)$row['share_pct'], 1) ?>%</td>
                                    <td class="text-end"><?= (int)$row['reservation_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                        <div class="form-text mb-0">
                            Showing <?= (int)$modelPagination['start_index'] ?>-<?= (int)$modelPagination['end_index'] ?>
                            of <?= (int)$modelPagination['total'] ?> models.
                        </div>
                        <?= $renderPagination($modelPagination, 'model_page') ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title mb-1">Utilisation by Accessory</h5>
                <p class="text-muted small mb-3">
                    Accessory-level booked unit-hours from typed reservation item rows.
                </p>

                <?php if ((int)$accessoryPagination['total'] === 0): ?>
                    <div class="text-muted small">No accessory utilisation data found for this range.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th><?= $renderSortableHeader('Accessory', 'accessory_name', $accessorySort, $accessoryDir, 'accessory_sort', 'accessory_dir', 'accessory_page') ?></th>
                                <th><?= $renderSortableHeader('Category', 'category', $accessorySort, $accessoryDir, 'accessory_sort', 'accessory_dir', 'accessory_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Unit-hours booked', 'unit_hours', $accessorySort, $accessoryDir, 'accessory_sort', 'accessory_dir', 'accessory_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Share', 'share_pct', $accessorySort, $accessoryDir, 'accessory_sort', 'accessory_dir', 'accessory_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Units booked', 'quantity_booked', $accessorySort, $accessoryDir, 'accessory_sort', 'accessory_dir', 'accessory_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Reservations', 'reservation_count', $accessorySort, $accessoryDir, 'accessory_sort', 'accessory_dir', 'accessory_page') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($accessoryRowsPage as $row): ?>
                                <tr>
                                    <td>
                                        <div class="report-model-cell">
                                            <?php $accessoryImageUrl = trim((string)($row['accessory_image_url'] ?? '')); ?>
                                            <?php if ($accessoryImageUrl !== ''): ?>
                                                <img src="<?= h($accessoryImageUrl) ?>"
                                                     alt="<?= h((string)$row['accessory_name']) ?>"
                                                     class="report-model-thumb"
                                                     loading="lazy">
                                            <?php else: ?>
                                                <div class="report-model-thumb report-model-thumb--placeholder" aria-hidden="true">-</div>
                                            <?php endif; ?>
                                            <div class="report-model-cell__text">
                                                <div><?= h((string)$row['accessory_name']) ?></div>
                                                <span class="text-muted small">ID <?= (int)$row['accessory_id'] ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= h((string)$row['category']) ?></td>
                                    <td class="text-end"><?= number_format((float)$row['unit_hours'], 1) ?></td>
                                    <td class="text-end"><?= number_format((float)$row['share_pct'], 1) ?>%</td>
                                    <td class="text-end"><?= (int)$row['quantity_booked'] ?></td>
                                    <td class="text-end"><?= (int)$row['reservation_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                        <div class="form-text mb-0">
                            Showing <?= (int)$accessoryPagination['start_index'] ?>-<?= (int)$accessoryPagination['end_index'] ?>
                            of <?= (int)$accessoryPagination['total'] ?> accessories.
                        </div>
                        <?= $renderPagination($accessoryPagination, 'accessory_page') ?>
                    </div>
                    <?php if ($accessoryLookupFailures > 0): ?>
                        <div class="form-text mt-2">
                            <?= (int)$accessoryLookupFailures ?> accessory <?= $accessoryLookupFailures === 1 ? 'record was' : 'records were' ?> not available from Snipe-IT and used cached reservation names.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title mb-1">Kit Component Demand</h5>
                <p class="text-muted small mb-3">
                    Kits are expanded into their model and accessory items when booked, so this maps report-window demand back to current kit contents. Shared components can appear under more than one kit.
                </p>

                <?php if ((int)$kitPagination['total'] === 0): ?>
                    <div class="text-muted small">No kit component demand found for this range.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th><?= $renderSortableHeader('Kit', 'kit_name', $kitSort, $kitDir, 'kit_sort', 'kit_dir', 'kit_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Component unit-hours', 'component_unit_hours', $kitSort, $kitDir, 'kit_sort', 'kit_dir', 'kit_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Reservations', 'reservation_count', $kitSort, $kitDir, 'kit_sort', 'kit_dir', 'kit_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Models', 'model_count', $kitSort, $kitDir, 'kit_sort', 'kit_dir', 'kit_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Accessories', 'accessory_count', $kitSort, $kitDir, 'kit_sort', 'kit_dir', 'kit_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Possible sets', 'available_sets', $kitSort, $kitDir, 'kit_sort', 'kit_dir', 'kit_page') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($kitRowsPage as $row): ?>
                                <tr>
                                    <td>
                                        <div><?= h((string)$row['kit_name']) ?></div>
                                        <span class="text-muted small">
                                            ID <?= (int)$row['kit_id'] ?>
                                            <?php if ((int)($row['unsupported_count'] ?? 0) > 0): ?>
                                                | <?= (int)$row['unsupported_count'] ?> unsupported item type<?= (int)$row['unsupported_count'] === 1 ? '' : 's' ?>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="text-end"><?= number_format((float)$row['component_unit_hours'], 1) ?></td>
                                    <td class="text-end"><?= (int)$row['reservation_count'] ?></td>
                                    <td class="text-end"><?= (int)$row['model_count'] ?></td>
                                    <td class="text-end"><?= (int)$row['accessory_count'] ?></td>
                                    <td class="text-end">
                                        <?= $row['available_sets'] === null ? 'Unknown' : number_format((int)$row['available_sets']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                        <div class="form-text mb-0">
                            Showing <?= (int)$kitPagination['start_index'] ?>-<?= (int)$kitPagination['end_index'] ?>
                            of <?= (int)$kitPagination['total'] ?> kits.
                        </div>
                        <?= $renderPagination($kitPagination, 'kit_page') ?>
                    </div>
                    <?php if ($kitLookupFailures > 0): ?>
                        <div class="form-text mt-2">
                            <?= (int)$kitLookupFailures ?> kit <?= $kitLookupFailures === 1 ? 'definition was' : 'definitions were' ?> not available from Snipe-IT.
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
                                <th><?= $renderSortableHeader('Hour', 'hour', $demandSort, $demandDir, 'demand_sort', 'demand_dir', 'demand_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Unit-hours', 'unit_hours', $demandSort, $demandDir, 'demand_sort', 'demand_dir', 'demand_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Avg concurrent units', 'avg_units', $demandSort, $demandDir, 'demand_sort', 'demand_dir', 'demand_page') ?></th>
                                <th style="width: 42%;"><?= $renderSortableHeader('Demand', 'bar_pct', $demandSort, $demandDir, 'demand_sort', 'demand_dir', 'demand_page') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($hourDemandRowsPage as $row): ?>
                                <tr>
                                    <td><?= h($formatHourRange((int)$row['hour'])) ?></td>
                                    <td class="text-end"><?= number_format((float)$row['unit_hours'], 1) ?></td>
                                    <td class="text-end"><?= number_format((float)$row['avg_units'], 2) ?></td>
                                    <td>
                                        <div class="progress" role="progressbar" aria-valuenow="<?= (int)round((float)$row['bar_pct']) ?>" aria-valuemin="0" aria-valuemax="100" style="height: .85rem;">
                                            <div class="progress-bar" style="width: <?= max(0, min(100, (float)$row['bar_pct'])) ?>%;"></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                        <div class="form-text mb-0">
                            Showing <?= (int)$demandPagination['start_index'] ?>-<?= (int)$demandPagination['end_index'] ?>
                            of <?= (int)$demandPagination['total'] ?> hourly entries.
                        </div>
                        <?= $renderPagination($demandPagination, 'demand_page') ?>
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

                <?php if ((int)$cancellationsPagination['total'] === 0): ?>
                    <div class="text-muted small">No cancellation/no-show events found for this range.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                            <tr>
                                <th><?= $renderSortableHeader('Date', 'report_day', $cancellationsSort, $cancellationsDir, 'cancellations_sort', 'cancellations_dir', 'cancellations_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('Cancelled', 'cancelled_count', $cancellationsSort, $cancellationsDir, 'cancellations_sort', 'cancellations_dir', 'cancellations_page') ?></th>
                                <th class="text-end"><?= $renderSortableHeader('No-shows (missed)', 'missed_count', $cancellationsSort, $cancellationsDir, 'cancellations_sort', 'cancellations_dir', 'cancellations_page') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($dailyCancelledMissedRowsPage as $row): ?>
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
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                        <div class="form-text mb-0">
                            Showing <?= (int)$cancellationsPagination['start_index'] ?>-<?= (int)$cancellationsPagination['end_index'] ?>
                            of <?= (int)$cancellationsPagination['total'] ?> days.
                        </div>
                        <?= $renderPagination($cancellationsPagination, 'cancellations_page') ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const scrollKey = 'snipeScheduler:reportsSortScrollY';

    function restoreScrollPosition() {
        try {
            const raw = window.sessionStorage.getItem(scrollKey);
            if (raw === null) return;
            window.sessionStorage.removeItem(scrollKey);
            const target = parseInt(raw, 10);
            if (!Number.isFinite(target)) return;

            const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
            const maxY = Math.max(0, document.documentElement.scrollHeight - Math.max(1, viewportHeight));
            const nextY = Math.max(0, Math.min(maxY, target));
            window.scrollTo(0, nextY);
        } catch (e) {
            // Ignore storage errors (private mode / blocked storage).
        }
    }

    function saveScrollPosition() {
        try {
            const y = Math.max(0, Math.round(window.scrollY || window.pageYOffset || 0));
            window.sessionStorage.setItem(scrollKey, String(y));
        } catch (e) {
            // Ignore storage errors (private mode / blocked storage).
        }
    }

    restoreScrollPosition();

    document.querySelectorAll('.js-report-sort-link, .js-report-page-link').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (event.defaultPrevented) return;
            if (event.button !== 0) return;
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            const href = link.getAttribute('href') || '';
            if (href === '' || href === '#' || href.toLowerCase().startsWith('javascript:')) return;
            saveScrollPosition();
        });
    });
});
</script>
<?php layout_footer(); ?>
</body>
</html>
