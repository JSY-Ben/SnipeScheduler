<?php
// staff_checkout.php
//
// Staff-only page that:
// 1) Shows today's bookings from the booking app.
// 2) Provides a bulk checkout panel that uses the Snipe-IT API to
//    check out scanned asset tags to a Snipe-IT user.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/layout.php';

$config     = load_config();
$timezone   = $config['app']['timezone'] ?? 'Europe/Jersey';
$embedded   = defined('RESERVATIONS_EMBED');
$pageBase   = $embedded ? 'reservations.php' : 'staff_checkout.php';
$baseQuery  = $embedded ? ['tab' => 'today'] : [];
$selfUrl    = $pageBase . (!empty($baseQuery) ? '?' . http_build_query($baseQuery) : '');
$active     = basename($_SERVER['PHP_SELF']);
$isAdmin    = !empty($currentUser['is_admin']);
$isStaff    = !empty($currentUser['is_staff']) || $isAdmin;
$tz       = new DateTimeZone($timezone);
$now      = new DateTime('now', $tz);
$todayStr = $now->format('Y-m-d');

// Only staff/admin allowed
if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// ---------------------------------------------------------------------
// AJAX: user search for autocomplete
// ---------------------------------------------------------------------
if (($_GET['ajax'] ?? '') === 'user_search') {
    header('Content-Type: application/json');

    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        $data = snipeit_request('GET', 'users', [
            'search' => $q,
            'limit'  => 10,
        ]);

        $rows = $data['rows'] ?? [];
        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id'       => $row['id'] ?? null,
                'name'     => $row['name'] ?? '',
                'email'    => $row['email'] ?? '',
                'username' => $row['username'] ?? '',
            ];
        }

        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $allowedKeys = array_keys($baseQuery);
    $extraKeys = array_diff(array_keys($_GET), $allowedKeys);
    if (empty($extraKeys)) {
        if (!empty($_SESSION['selected_reservation_fresh'])) {
            unset($_SESSION['selected_reservation_fresh']);
        } else {
            unset($_SESSION['selected_reservation_id']);
            unset($_SESSION['reservation_selected_assets']);
        }
    }
}

// ---------------------------------------------------------------------
// Helper: UK date/time display from Y-m-d H:i:s
// ---------------------------------------------------------------------
function uk_datetime_display(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $iso);
    if (!$dt) {
        return $iso;
    }
    return $dt->format('d/m/Y H:i');
}

function uk_datetime_display_12h(?string $iso): string
{
    if (!$iso) {
        return '';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $iso);
    if (!$dt) {
        return $iso;
    }
    return $dt->format('d/m/Y h:i A');
}

/**
 * Check if a model is booked in another reservation overlapping the window.
 */
function model_booked_elsewhere(PDO $pdo, int $modelId, string $start, string $end, ?int $excludeReservationId = null): bool
{
    if ($modelId <= 0 || $start === '' || $end === '') {
        return false;
    }

    $sql = "
        SELECT COALESCE(SUM(ri.quantity), 0) AS booked_qty
        FROM reservation_items ri
        JOIN reservations r ON r.id = ri.reservation_id
        WHERE ri.model_id = :model_id
          AND r.start_datetime < :end
          AND r.end_datetime > :start
          AND r.status IN ('pending', 'confirmed', 'completed')
    ";

    $params = [
        ':model_id' => $modelId,
        ':start'    => $start,
        ':end'      => $end,
    ];

    if ($excludeReservationId) {
        $sql .= " AND r.id <> :exclude_id";
        $params[':exclude_id'] = $excludeReservationId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return ((int)($row['booked_qty'] ?? 0)) > 0;
}

// ---------------------------------------------------------------------
// Load today's bookings from reservations table
// ---------------------------------------------------------------------
$todayBookings = [];
$todayError    = '';

try {
    $sql = "
        SELECT *
        FROM reservations
        WHERE DATE(start_datetime) = :today
          AND status IN ('pending','confirmed')
        ORDER BY start_datetime ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':today' => $todayStr]);
    $todayBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $todayBookings = [];
    $todayError    = $e->getMessage();
}

// ---------------------------------------------------------------------
// Bulk checkout session basket
// ---------------------------------------------------------------------
if (!isset($_SESSION['bulk_checkout_assets'])) {
    $_SESSION['bulk_checkout_assets'] = [];
}
$checkoutAssets = &$_SESSION['bulk_checkout_assets'];
if (!isset($_SESSION['reservation_selected_assets'])) {
    $_SESSION['reservation_selected_assets'] = [];
}

// Selected reservation for checkout (today only)
$selectedReservationId = isset($_SESSION['selected_reservation_id'])
    ? (int)$_SESSION['selected_reservation_id']
    : null;

// Messages
$checkoutMessages = [];
$checkoutErrors   = [];
$checkoutWarnings = [];
$reservationUserCandidates = [];
$bulkUserCandidates = [];
$selectedReservationUserId = 0;
$selectedBulkUserId = 0;
$bulkCheckoutToValue = '';
$bulkNoteValue = '';
$reservationNoteValue = '';

// Current counts per model already in checkout list (for quota enforcement)
$currentModelCounts = [];
foreach ($checkoutAssets as $existing) {
    $mid = isset($existing['model_id']) ? (int)$existing['model_id'] : 0;
    if ($mid > 0) {
        $currentModelCounts[$mid] = ($currentModelCounts[$mid] ?? 0) + 1;
    }
}

// Handle reservation selection (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'select_reservation') {
    $selectedReservationId = (int)($_POST['reservation_id'] ?? 0);
    if ($selectedReservationId > 0) {
        $_SESSION['selected_reservation_id'] = $selectedReservationId;
        $_SESSION['selected_reservation_fresh'] = 1;
    } else {
        unset($_SESSION['selected_reservation_id']);
        $selectedReservationId = null;
    }
    // Reset checkout basket when changing reservation
    $checkoutAssets = [];
    $_SESSION['reservation_selected_assets'] = [];
    header('Location: ' . $selfUrl);
    exit;
}

// Remove single asset from checkout list via GET ?remove=ID
if (isset($_GET['remove'])) {
    $removeId = (int)$_GET['remove'];
    if ($removeId > 0 && isset($checkoutAssets[$removeId])) {
        unset($checkoutAssets[$removeId]);
    }
    header('Location: ' . $selfUrl);
    exit;
}

// ---------------------------------------------------------------------
// Selected reservation details (today only)
// ---------------------------------------------------------------------
$selectedReservation = null;
$selectedItems       = [];
$modelLimits         = [];
$selectedStart       = '';
$selectedEnd         = '';
$modelAssets         = [];
$presetSelections    = [];
$selectedTotalQty    = 0;

if ($selectedReservationId) {
    $stmt = $pdo->prepare("
        SELECT *
        FROM reservations
        WHERE id = :id
          AND DATE(start_datetime) = :today
    ");
    $stmt->execute([
        ':id'    => $selectedReservationId,
        ':today' => $todayStr,
    ]);
    $selectedReservation = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($selectedReservation) {
        $selectedStart = $selectedReservation['start_datetime'] ?? '';
        $selectedEnd   = $selectedReservation['end_datetime'] ?? '';
        $selectedItems = get_reservation_items_with_names($pdo, $selectedReservationId);
        foreach ($selectedItems as $item) {
            $selectedTotalQty += (int)($item['qty'] ?? 0);
        }
        $storedSelections = $_SESSION['reservation_selected_assets'][$selectedReservationId] ?? [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_assets']) && is_array($_POST['selected_assets'])) {
            $normalizedSelections = [];
            foreach ($_POST['selected_assets'] as $midRaw => $choices) {
                $mid = (int)$midRaw;
                if ($mid <= 0 || !is_array($choices)) {
                    continue;
                }
                $normalizedSelections[$mid] = [];
                foreach ($choices as $idx => $choice) {
                    $normalizedSelections[$mid][(int)$idx] = (int)$choice;
                }
                $normalizedSelections[$mid] = array_values($normalizedSelections[$mid]);
            }
            $presetSelections = $normalizedSelections;
        } elseif (is_array($storedSelections)) {
            $presetSelections = $storedSelections;
        }
        foreach ($selectedItems as $item) {
            $mid          = (int)($item['model_id'] ?? 0);
            $qty          = (int)($item['qty'] ?? 0);
            if ($mid > 0 && $qty > 0) {
                $modelLimits[$mid] = $qty;
                try {
                    // Only include assets not already checked out/assigned
                    $assetsRaw = list_assets_by_model($mid, 300);
                    $filtered  = [];
                    foreach ($assetsRaw as $a) {
                        if (empty($a['requestable'])) {
                            continue; // skip non-requestable assets
                        }
                        $assigned = $a['assigned_to'] ?? ($a['assigned_to_fullname'] ?? '');
                        $statusRaw = $a['status_label'] ?? '';
                        if (is_array($statusRaw)) {
                            $statusRaw = $statusRaw['name'] ?? ($statusRaw['status_meta'] ?? '');
                        }
                        $status = strtolower((string)$statusRaw);
                        if (!empty($assigned)) {
                            continue;
                        }
                        if (strpos($status, 'checked out') !== false) {
                            continue;
                        }
                        $filtered[] = $a;
                    }
                    $modelAssets[$mid] = $filtered;
                } catch (Throwable $e) {
                    $modelAssets[$mid] = [];
                }
            }
        }
    } else {
        unset($_SESSION['selected_reservation_id']);
        $selectedReservationId = null;
    }
}

// ---------------------------------------------------------------------
// Handle POST actions: add_asset or checkout
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';

    if (isset($_POST['remove_model_id_all']) || isset($_POST['remove_slot'])) {
        $removeAll = isset($_POST['remove_model_id_all']);
        $removeModelId = 0;
        $removeSlot = null;
        if ($removeAll) {
            $removeModelId = (int)($_POST['remove_model_id_all'] ?? 0);
        } elseif (isset($_POST['remove_slot'])) {
            $rawSlot = trim((string)$_POST['remove_slot']);
            if (preg_match('/^(\\d+):(\\d+)$/', $rawSlot, $m)) {
                $removeModelId = (int)$m[1];
                $removeSlot = (int)$m[2];
            }
        }
        if (!$selectedReservation) {
            $checkoutErrors[] = 'Please select a reservation for today before removing items.';
        } elseif ($removeModelId <= 0) {
            $checkoutErrors[] = 'Invalid model to remove.';
        } else {
            $submittedSelections = $_POST['selected_assets'] ?? [];
            $normalizedSelections = [];
            if (is_array($submittedSelections)) {
                foreach ($submittedSelections as $midRaw => $choices) {
                    $mid = (int)$midRaw;
                    if ($mid <= 0 || !is_array($choices)) {
                        continue;
                    }
                    $normalizedSelections[$mid] = [];
                    foreach ($choices as $idx => $choice) {
                        $normalizedSelections[$mid][(int)$idx] = (int)$choice;
                    }
                    $normalizedSelections[$mid] = array_values($normalizedSelections[$mid]);
                }
            }

            if ($removeAll) {
                unset($normalizedSelections[$removeModelId]);
            } elseif (isset($normalizedSelections[$removeModelId])) {
                if ($removeSlot !== null && $removeSlot >= 0 && isset($normalizedSelections[$removeModelId][$removeSlot])) {
                    array_splice($normalizedSelections[$removeModelId], $removeSlot, 1);
                } else {
                    array_pop($normalizedSelections[$removeModelId]);
                }
                $normalizedSelections[$removeModelId] = array_values($normalizedSelections[$removeModelId]);
            }
            if ($selectedReservationId) {
                $_SESSION['reservation_selected_assets'][$selectedReservationId] = $normalizedSelections;
            }

            try {
                $totalStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(quantity), 0)
                      FROM reservation_items
                     WHERE reservation_id = :rid
                ");
                $totalStmt->execute([':rid' => $selectedReservationId]);
                $totalQtyBefore = (int)$totalStmt->fetchColumn();

                $stmt = $pdo->prepare("
                    SELECT quantity
                      FROM reservation_items
                     WHERE reservation_id = :rid
                       AND model_id = :mid
                     LIMIT 1
                ");
                $stmt->execute([
                    ':rid' => $selectedReservationId,
                    ':mid' => $removeModelId,
                ]);
                $currentQty = (int)$stmt->fetchColumn();

                $willDeleteReservation = false;
                if ($removeAll) {
                    $willDeleteReservation = $currentQty > 0 && ($totalQtyBefore - $currentQty) <= 0;
                } else {
                    $willDeleteReservation = $totalQtyBefore <= 1;
                }

                if ($willDeleteReservation && ($_POST['confirm_delete'] ?? '') !== '1') {
                    throw new RuntimeException('Confirmation required to delete the reservation.');
                }

                if ($currentQty <= 1 || $removeAll) {
                    $del = $pdo->prepare("
                        DELETE FROM reservation_items
                         WHERE reservation_id = :rid
                           AND model_id = :mid
                    ");
                    $del->execute([
                        ':rid' => $selectedReservationId,
                        ':mid' => $removeModelId,
                    ]);
                } else {
                    $upd = $pdo->prepare("
                        UPDATE reservation_items
                           SET quantity = :qty
                         WHERE reservation_id = :rid
                           AND model_id = :mid
                    ");
                    $upd->execute([
                        ':qty' => $currentQty - 1,
                        ':rid' => $selectedReservationId,
                        ':mid' => $removeModelId,
                    ]);
                }

                if ($willDeleteReservation) {
                    $deletedReservationId = $selectedReservationId;
                    $delRes = $pdo->prepare("DELETE FROM reservations WHERE id = :id");
                    $delRes->execute([':id' => $selectedReservationId]);
                    activity_log_event('reservation_deleted', 'Reservation deleted', [
                        'subject_type' => 'reservation',
                        'subject_id'   => $deletedReservationId,
                        'metadata'     => [
                            'via' => 'staff_checkout',
                        ],
                    ]);
                    unset($_SESSION['reservation_selected_assets'][$selectedReservationId]);
                    unset($_SESSION['selected_reservation_id']);
                    $selectedReservationId = null;
                }

                if ($selectedReservationId) {
                    $_SESSION['selected_reservation_fresh'] = 1;
                }

                header('Location: ' . $selfUrl);
                exit;
            } catch (Throwable $e) {
                $checkoutErrors[] = 'Could not update reservation: ' . $e->getMessage();
            }
        }
    }

    if ($mode === 'add_asset') {
        $tag = trim($_POST['asset_tag'] ?? '');
        if (!$selectedReservation) {
            $checkoutErrors[] = 'Please select a reservation for today before adding assets.';
        } elseif ($tag === '') {
            $checkoutErrors[] = 'Please scan or enter an asset tag.';
        } else {
            try {
                $asset = find_asset_by_tag($tag);

                $assetId   = (int)($asset['id'] ?? 0);
                $assetTag  = $asset['asset_tag'] ?? '';
                $assetName = $asset['name'] ?? '';
                $modelName = $asset['model']['name'] ?? '';
                $modelId   = (int)($asset['model']['id'] ?? 0);
                $status    = $asset['status_label'] ?? '';
                $isRequestable = !empty($asset['requestable']);

                // Normalise status label to a string (API may return array/object)
                if (is_array($status)) {
                    $status = $status['name'] ?? $status['status_meta'] ?? $status['label'] ?? '';
                }

                if ($assetId <= 0 || $assetTag === '') {
                    throw new Exception('Asset record from Snipe-IT is missing id/asset_tag.');
                }
                if ($modelId <= 0) {
                    throw new Exception('Asset record from Snipe-IT is missing model information.');
                }
                if (!$isRequestable) {
                    throw new Exception('This asset is not requestable in Snipe-IT.');
                }

                // Enforce that the asset's model is in the selected reservation and within quantity.
                $allowedQty   = $modelLimits[$modelId] ?? 0;
                $alreadyAdded = $currentModelCounts[$modelId] ?? 0;

                if ($allowedQty > 0 && $alreadyAdded >= $allowedQty) {
                    throw new Exception("Reservation allows {$allowedQty} of this model; you already added {$alreadyAdded}.");
                }

                if ($allowedQty === 0 && $selectedStart && $selectedEnd) {
                    // Not part of reservation: only allow if model isn't booked elsewhere for this window
                    $bookedElsewhere = model_booked_elsewhere($pdo, $modelId, $selectedStart, $selectedEnd, $selectedReservationId);
                    if ($bookedElsewhere) {
                        throw new Exception('This model is booked in another reservation for this time window.');
                    }
                }

                // Avoid duplicates: overwrite existing entry for same asset id
                $checkoutAssets[$assetId] = [
                    'id'         => $assetId,
                    'asset_tag'  => $assetTag,
                    'name'       => $assetName,
                    'model'      => $modelName,
                    'model_id'   => $modelId,
                    'status'     => $status,
                ];
                $currentModelCounts[$modelId] = ($currentModelCounts[$modelId] ?? 0) + 1;

                $checkoutMessages[] = "Added asset {$assetTag} ({$assetName}) to checkout list.";
            } catch (Throwable $e) {
                $checkoutErrors[] = 'Could not add asset: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'reservation_checkout') {
        if (!$selectedReservation) {
            $checkoutErrors[] = 'Please select a reservation for today before checking out.';
        } else {
            $checkoutTo = trim($selectedReservation['user_email'] ?? '');
            if ($checkoutTo === '') {
                $checkoutTo = trim($selectedReservation['user_name'] ?? '');
            }
            $note = trim($_POST['reservation_note'] ?? '');
            $reservationNoteValue = $note;
            $selectedReservationUserId = (int)($_POST['reservation_user_id'] ?? 0);
            if ($checkoutTo === '') {
                $checkoutErrors[] = 'This reservation has no associated user name.';
            }

            $selectedAssetsInput = $_POST['selected_assets'] ?? [];
            $assetsToCheckout    = [];

            // Validate selections against required quantities
            foreach ($selectedItems as $item) {
                $mid    = (int)$item['model_id'];
                $qty    = (int)$item['qty'];
                $choices = $modelAssets[$mid] ?? [];
                $choicesById = [];
                foreach ($choices as $c) {
                    if (!empty($c['requestable'])) {
                        $choicesById[(int)($c['id'] ?? 0)] = $c;
                    }
                }

                $selectedForModel = isset($selectedAssetsInput[$mid]) && is_array($selectedAssetsInput[$mid])
                    ? array_values($selectedAssetsInput[$mid])
                    : [];

                if (count($selectedForModel) < $qty) {
                    $checkoutErrors[] = "Please select {$qty} asset(s) for model {$item['name']}.";
                    continue;
                }

                $seen = [];
                for ($i = 0; $i < $qty; $i++) {
                    $assetIdSel = (int)($selectedForModel[$i] ?? 0);
                    if ($assetIdSel <= 0 || !isset($choicesById[$assetIdSel])) {
                        $checkoutErrors[] = "Invalid asset selection for model {$item['name']}.";
                        continue;
                    }
                    if (isset($seen[$assetIdSel])) {
                        $checkoutErrors[] = "Duplicate asset selected for model {$item['name']}.";
                        continue;
                    }
                    $seen[$assetIdSel] = true;
                    $assetsToCheckout[] = [
                        'asset_id'   => $assetIdSel,
                        'asset_tag'  => $choicesById[$assetIdSel]['asset_tag'] ?? ('ID ' . $assetIdSel),
                        'model_name' => $item['name'] ?? '',
                    ];
                }
            }

            if (empty($checkoutErrors) && !empty($assetsToCheckout)) {
                try {
                    $user = null;
                    $result = find_user_by_email_or_name_with_candidates($checkoutTo);
                    if (!empty($result['user'])) {
                        $user = $result['user'];
                    } else {
                        $reservationUserCandidates = $result['candidates'];
                        if ($selectedReservationUserId > 0) {
                            foreach ($reservationUserCandidates as $candidate) {
                                if ((int)($candidate['id'] ?? 0) === $selectedReservationUserId) {
                                    $user = $candidate;
                                    break;
                                }
                            }
                            if (!$user) {
                                $checkoutErrors[] = 'Selected user is not available for this reservation. Please choose again.';
                            }
                        } else {
                            $checkoutWarnings[] = "Multiple Snipe-IT users matched '{$checkoutTo}'. Please choose which account to use.";
                        }
                    }

                    if ($user) {
                        $userId   = (int)($user['id'] ?? 0);
                        $userName = $user['name'] ?? ($user['username'] ?? $checkoutTo);

                        if ($userId <= 0) {
                            throw new Exception('Matched user has no valid ID.');
                        }

                        foreach ($assetsToCheckout as $a) {
                            checkout_asset_to_user((int)$a['asset_id'], $userId, $note, $selectedEnd);
                            $checkoutMessages[] = "Checked out asset {$a['asset_tag']} to {$userName}.";
                        }

                        // Mark reservation as checked out and store asset tags
                        $assetTags = array_map(function ($a) {
                            $tag   = $a['asset_tag'] ?? '';
                            $model = $a['model_name'] ?? '';
                            return $model !== '' ? "{$tag} ({$model})" : $tag;
                        }, $assetsToCheckout);
                        $assetsText = implode(', ', array_filter($assetTags));

                        $upd = $pdo->prepare("
                            UPDATE reservations
                               SET status = 'completed',
                                   asset_name_cache = :assets_text
                             WHERE id = :id
                        ");
                        $upd->execute([
                            ':id'          => $selectedReservationId,
                            ':assets_text' => $assetsText,
                        ]);
                        $checkoutMessages[] = 'Reservation marked as checked out.';
                        if ($selectedReservationId) {
                            unset($_SESSION['reservation_selected_assets'][$selectedReservationId]);
                        }

                        activity_log_event('reservation_checked_out', 'Reservation checked out', [
                            'subject_type' => 'reservation',
                            'subject_id'   => $selectedReservationId,
                            'metadata'     => [
                                'checked_out_to' => $userName,
                                'assets'         => $assetTags,
                                'note'           => $note,
                            ],
                        ]);

                        // Email notifications
                        $userEmail = $selectedReservation['user_email'] ?? '';
                        $userName  = $selectedReservation['user_name'] ?? ($selectedReservation['user_email'] ?? 'User');
                        $staffEmail = $currentUser['email'] ?? '';
                        $staffName  = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
                        $dueDate    = $selectedReservation['end_datetime'] ?? '';
                        $dueDisplay = $dueDate ? uk_datetime_display_12h($dueDate) : 'N/A';

                        $assetLines = $assetsText !== '' ? $assetsText : implode(', ', array_filter($assetTags));
                        $bodyLines = [
                            "Reservation #{$selectedReservationId} has been checked out.",
                            "Items: {$assetLines}",
                            "Return by: {$dueDisplay}",
                            $note !== '' ? "Note: {$note}" : '',
                            "Checked out by: {$staffName}",
                        ];
                        if ($userEmail !== '') {
                            layout_send_notification($userEmail, $userName, 'Your reservation has been checked out', $bodyLines);
                        }
                        if ($staffEmail !== '') {
                            layout_send_notification($staffEmail, $staffName !== '' ? $staffName : $staffEmail, 'You checked out a reservation', $bodyLines);
                        }

                        // Clear selected reservation to avoid repeat
                        unset($_SESSION['selected_reservation_id']);
                        $selectedReservationId = null;
                        $selectedReservation = null;
                        $selectedItems = [];
                        $modelAssets = [];
                        $presetSelections = [];
                        $selectedTotalQty = 0;
                        $reservationUserCandidates = [];
                        $selectedReservationUserId = 0;
                        $reservationNoteValue = '';
                    }
                } catch (Throwable $e) {
                    $checkoutErrors[] = 'Reservation checkout failed: ' . $e->getMessage();
                }
            }
        }
    } elseif ($mode === 'checkout') {
        $checkoutTo = trim($_POST['checkout_to'] ?? '');
        $note       = trim($_POST['note'] ?? '');
        $selectedBulkUserId = (int)($_POST['checkout_user_id'] ?? 0);
        $bulkCheckoutToValue = $checkoutTo;
        $bulkNoteValue = $note;

        if (!$selectedReservation) {
            $checkoutErrors[] = 'Please select a reservation for today before checking out.';
        } elseif ($checkoutTo === '') {
            $checkoutErrors[] = 'Please enter the Snipe-IT user (email or name) to check out to.';
        } elseif (empty($checkoutAssets)) {
            $checkoutErrors[] = 'There are no assets in the checkout list.';
        } else {
            try {
                $user = null;
                $result = find_user_by_email_or_name_with_candidates($checkoutTo);
                if (!empty($result['user'])) {
                    $user = $result['user'];
                } else {
                    $bulkUserCandidates = $result['candidates'];
                    if ($selectedBulkUserId > 0) {
                        foreach ($bulkUserCandidates as $candidate) {
                            if ((int)($candidate['id'] ?? 0) === $selectedBulkUserId) {
                                $user = $candidate;
                                break;
                            }
                        }
                        if (!$user) {
                            $checkoutErrors[] = 'Selected user is not available for this checkout. Please choose again.';
                        }
                    } else {
                        $checkoutWarnings[] = "Multiple Snipe-IT users matched '{$checkoutTo}'. Please choose which account to use.";
                    }
                }

                if ($user) {
                    $userId   = (int)($user['id'] ?? 0);
                    $userName = $user['name'] ?? ($user['username'] ?? $checkoutTo);

                    if ($userId <= 0) {
                        throw new Exception('Matched user has no valid ID.');
                    }

                    // Attempt to check out each asset
                    foreach ($checkoutAssets as $asset) {
                        $assetId  = (int)$asset['id'];
                        $assetTag = $asset['asset_tag'] ?? '';
                        $modelId  = isset($asset['model_id']) ? (int)$asset['model_id'] : 0;

                        // Re-check quotas before checkout
                        if ($modelId > 0 && isset($modelLimits[$modelId])) {
                            $allowed = $modelLimits[$modelId];
                            $countForModel = 0;
                            foreach ($checkoutAssets as $a2) {
                                if ((int)($a2['model_id'] ?? 0) === $modelId) {
                                    $countForModel++;
                                }
                            }
                            if ($countForModel > $allowed) {
                                throw new Exception("Too many assets of model {$asset['model']} for this reservation (allowed {$allowed}).");
                            }
                        } elseif ($modelId > 0 && $selectedStart && $selectedEnd) {
                            if (model_booked_elsewhere($pdo, $modelId, $selectedStart, $selectedEnd, $selectedReservationId)) {
                                throw new Exception("Model {$asset['model']} is booked in another reservation for this window.");
                            }
                        }

                        try {
                            // Pass expected end datetime to Snipe-IT so time is preserved
                            checkout_asset_to_user($assetId, $userId, $note, $selectedEnd);
                            $checkoutMessages[] = "Checked out asset {$assetTag} to {$userName}.";
                        } catch (Throwable $e) {
                            $checkoutErrors[] = "Failed to check out {$assetTag}: " . $e->getMessage();
                        }
                    }

                    // If no errors, clear the list
                    if (empty($checkoutErrors)) {
                        $assetTags = array_map(static function ($asset): string {
                            $tag = $asset['asset_tag'] ?? '';
                            $model = $asset['model'] ?? '';
                            return $model !== '' ? ($tag . ' (' . $model . ')') : $tag;
                        }, $checkoutAssets);

                        activity_log_event('reservation_checked_out', 'Assets checked out from reservation', [
                            'subject_type' => 'reservation',
                            'subject_id'   => $selectedReservationId,
                            'metadata'     => [
                                'checked_out_to' => $userName,
                                'assets'         => $assetTags,
                                'note'           => $note,
                            ],
                        ]);

                        $checkoutAssets = [];
                    }
                }
            } catch (Throwable $e) {
                $checkoutErrors[] = 'Could not find user in Snipe-IT: ' . $e->getMessage();
            }
        }
    }
}

// ---------------------------------------------------------------------
// View data
// ---------------------------------------------------------------------
$active  = basename($_SERVER['PHP_SELF']);
?>
<?php if (!$embedded): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Today’s Reservations (Checkout)</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
<?php endif; ?>
        <div class="page-header">
            <h1>Today’s Reservations (Checkout)</h1>
            <div class="page-subtitle">
                View today’s reservations and perform bulk checkouts via Snipe-IT.
            </div>
        </div>

        <!-- App navigation -->
        <?php if (!$embedded): ?>
            <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <?php endif; ?>

        <!-- Top bar -->
        <?php if (!$embedded): ?>
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
        <?php endif; ?>

        <!-- Reservation selector (today only) -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="post" class="row g-3 align-items-end" action="<?= h($selfUrl) ?>">
                    <?php foreach ($baseQuery as $k => $v): ?>
                        <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="mode" value="select_reservation">
                    <div class="col-md-8">
                        <label class="form-label">Select today’s reservation to check out</label>
                        <select name="reservation_id" class="form-select">
                            <option value="0">-- No reservation selected --</option>
                            <?php foreach ($todayBookings as $res): ?>
                                <?php
                        $resId   = (int)$res['id'];
                        $items   = get_reservation_items_with_names($pdo, $resId);
                        $summary = build_items_summary_text($items);
                        $start   = uk_datetime_display($res['start_datetime'] ?? '');
                        $end     = uk_datetime_display($res['end_datetime'] ?? '');
                                ?>
                                <option value="<?= $resId ?>" <?= $resId === $selectedReservationId ? 'selected' : '' ?>>
                                    #<?= $resId ?> – <?= h($res['user_name'] ?? '') ?> (<?= h($start) ?> → <?= h($end) ?>): <?= h($summary) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Use reservation</button>
                        <button type="submit" name="reservation_id" value="0" class="btn btn-outline-secondary">Clear</button>
                    </div>
                </form>

                <?php if ($selectedReservation): ?>
                    <div class="mt-3 alert alert-info mb-0">
                        <div><strong>Selected:</strong> #<?= (int)$selectedReservation['id'] ?> – <?= h($selectedReservation['user_name'] ?? '') ?></div>
                        <div>When: <?= h(uk_datetime_display($selectedReservation['start_datetime'] ?? '')) ?> → <?= h(uk_datetime_display($selectedReservation['end_datetime'] ?? '')) ?></div>
                        <?php if (!empty($selectedItems)): ?>
                            <div>Models &amp; quantities: <?= h(build_items_summary_text($selectedItems)) ?></div>
                        <?php else: ?>
                            <div>This reservation has no items recorded.</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Feedback messages -->
        <?php if (!empty($checkoutMessages)): ?>
            <div class="alert alert-success">
                <ul class="mb-0">
                    <?php foreach ($checkoutMessages as $m): ?>
                        <li><?= h($m) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($checkoutWarnings)): ?>
            <div class="alert alert-warning">
                <ul class="mb-0">
                    <?php foreach ($checkoutWarnings as $w): ?>
                        <li><?= h($w) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($checkoutErrors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($checkoutErrors as $e): ?>
                        <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Reservation checkout (per booking) -->
        <?php if ($selectedReservation): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title">Reservation checkout</h5>
                    <p class="card-text">
                        Choose assets for each model in reservation #<?= (int)$selectedReservation['id'] ?>.
                    </p>

                    <form method="post" action="<?= h($selfUrl) ?>">
                        <?php foreach ($baseQuery as $k => $v): ?>
                            <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
                        <?php endforeach; ?>

                        <?php
                            $reservationUserName = trim($selectedReservation['user_name'] ?? '');
                            $reservationUserEmail = trim($selectedReservation['user_email'] ?? '');
                            if ($reservationUserName !== '' && $reservationUserEmail !== '') {
                                $reservationUserDisplay = $reservationUserName . ' (' . $reservationUserEmail . ')';
                            } else {
                                $reservationUserDisplay = $reservationUserEmail !== '' ? $reservationUserEmail : $reservationUserName;
                            }
                        ?>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Check out to (reservation user)</label>
                                <input type="text"
                                       class="form-control"
                                       value="<?= h($reservationUserDisplay) ?>"
                                       readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Note (optional)</label>
                                <input type="text"
                                       name="reservation_note"
                                       class="form-control"
                                       placeholder="Optional note to store with checkout"
                                       value="<?= h($reservationNoteValue) ?>">
                            </div>
                        </div>

                        <?php if (!empty($reservationUserCandidates)): ?>
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Select matching Snipe-IT user</label>
                                    <select name="reservation_user_id" class="form-select" required>
                                        <option value="">-- Choose user --</option>
                                        <?php foreach ($reservationUserCandidates as $candidate): ?>
                                            <?php
                                                $cid = (int)($candidate['id'] ?? 0);
                                                $cEmail = $candidate['email'] ?? '';
                                                $cName = $candidate['name'] ?? ($candidate['username'] ?? '');
                                                $cLabel = $cName !== '' && $cEmail !== '' ? "{$cName} ({$cEmail})" : ($cName !== '' ? $cName : $cEmail);
                                                $selectedAttr = $selectedReservationUserId === $cid ? 'selected' : '';
                                            ?>
                                            <option value="<?= $cid ?>" <?= $selectedAttr ?>><?= h($cLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Multiple users matched the reservation user. Choose which account to use.</div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($selectedItems as $item): ?>
                            <?php
                                $mid     = (int)$item['model_id'];
                                $qty     = (int)$item['qty'];
                                $options = $modelAssets[$mid] ?? [];
                                $imagePath = $item['image'] ?? '';
                                $proxiedImage = $imagePath !== ''
                                    ? 'image_proxy.php?src=' . urlencode($imagePath)
                                    : '';
                            ?>
                            <div class="mb-3">
                                <table class="table table-sm align-middle reservation-model-table">
                                    <tbody>
                                        <tr>
                                            <td class="reservation-model-cell">
                                                <div class="reservation-model-header">
                                                    <?php if ($proxiedImage !== ''): ?>
                                                        <img src="<?= h($proxiedImage) ?>"
                                                             alt="<?= h($item['name'] ?? ('Model #' . $mid)) ?>"
                                                             class="reservation-model-image">
                                                    <?php else: ?>
                                                        <div class="reservation-model-image reservation-model-image--placeholder">
                                                            No image
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="reservation-model-title">
                                                        <div class="form-label mb-1">
                                                            <?= h($item['name'] ?? ('Model #' . $mid)) ?> (need <?= $qty ?>)
                                                        </div>
                                                        <div class="mt-2">
                                                            <?php $removeAllDeletes = $selectedTotalQty > 0 && $selectedTotalQty <= $qty; ?>
                                                            <button type="submit"
                                                                    name="remove_model_id_all"
                                                                    value="<?= $mid ?>"
                                                                    class="btn btn-sm btn-outline-danger"
                                                                    <?= $removeAllDeletes ? 'data-confirm-delete="1"' : '' ?>>
                                                                Remove all
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (empty($options)): ?>
                                                    <div class="alert alert-warning mb-0">
                                                        No assets found in Snipe-IT for this model.
                                                    </div>
                                                <?php else: ?>
                                                    <div class="d-flex flex-column gap-2">
                                                        <?php for ($i = 0; $i < $qty; $i++): ?>
                                                            <div class="d-flex gap-2 align-items-center">
                                                                <select class="form-select"
                                                                        name="selected_assets[<?= $mid ?>][]"
                                                                        data-model-select="<?= $mid ?>">
                                                                    <option value="">-- Select asset --</option>
                                                                    <?php foreach ($options as $opt): ?>
                                                                        <?php
                                                                        $aid   = (int)($opt['id'] ?? 0);
                                                                        $atag  = $opt['asset_tag'] ?? ('ID ' . $aid);
                                                                        $aname = $opt['name'] ?? '';
                                                                        $label = $aname !== ''
                                                                            ? trim($atag . ' – ' . $aname)
                                                                            : $atag;
                                                                        $selectedId = $presetSelections[$mid][$i] ?? 0;
                                                                        $selectedAttr = $aid > 0 && $selectedId === $aid ? 'selected' : '';
                                                                        ?>
                                                                        <option value="<?= $aid ?>" <?= $selectedAttr ?>><?= h($label) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <?php $removeOneDeletes = $selectedTotalQty <= 1; ?>
                                                                <button type="submit"
                                                                        name="remove_slot"
                                                                        value="<?= $mid ?>:<?= $i ?>"
                                                                        class="btn btn-sm btn-outline-danger"
                                                                        <?= $removeOneDeletes ? 'data-confirm-delete="1"' : '' ?>>
                                                                    Remove
                                                                </button>
                                                            </div>
                                                        <?php endfor; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
<?php endforeach; ?>

                        <button type="submit" name="mode" value="reservation_checkout" class="btn btn-primary">
                            Check out selected assets for this reservation
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

<?php if (!$embedded): ?>
    </div>
</div>
<?php endif; ?>

<?php
    $ajaxBase = $selfUrl . (strpos($selfUrl, '?') !== false ? '&' : '?');
?>

<script>
(function () {
    const scrollKey = 'staff_checkout_scroll_y';
    const savedY = sessionStorage.getItem(scrollKey);
    if (savedY !== null) {
        const y = parseInt(savedY, 10);
        if (!Number.isNaN(y)) {
            window.scrollTo(0, y);
        }
        sessionStorage.removeItem(scrollKey);
    }

    document.addEventListener('click', (event) => {
        const btn = event.target.closest('button[data-confirm-delete]');
        if (!btn) {
            return;
        }
        const ok = window.confirm('This will delete the entire reservation. Continue?');
        if (!ok) {
            event.preventDefault();
            return;
        }
        const form = btn.form;
        if (form && !form.querySelector('input[name=\"confirm_delete\"]')) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'confirm_delete';
            input.value = '1';
            form.appendChild(input);
        }
    });

    const wrappers = document.querySelectorAll('.user-autocomplete-wrapper');
    wrappers.forEach((wrapper) => {
        const input = wrapper.querySelector('.user-autocomplete');
        const list  = wrapper.querySelector('[data-suggestions]');
        if (!input || !list) return;

        let timer = null;
        let lastQuery = '';

        input.addEventListener('input', () => {
            const q = input.value.trim();
            if (q.length < 2) {
                hideSuggestions();
                return;
            }
            if (timer) clearTimeout(timer);
            timer = setTimeout(() => fetchSuggestions(q), 250);
        });

        input.addEventListener('blur', () => {
            setTimeout(hideSuggestions, 150); // allow click
        });

        function fetchSuggestions(q) {
            lastQuery = q;
            fetch('<?= h($ajaxBase) ?>ajax=user_search&q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then((res) => res.ok ? res.json() : Promise.reject())
                .then((data) => {
                    if (lastQuery !== q) return; // stale
                    renderSuggestions(data.results || []);
                })
                .catch(() => {
                    renderSuggestions([]);
                });
        }

        function renderSuggestions(items) {
            list.innerHTML = '';
            if (!items || !items.length) {
                hideSuggestions();
                return;
            }

            items.forEach((item) => {
                const email = item.email || '';
                const name = item.name || item.username || email;
                const label = (name && email && name !== email) ? `${name} (${email})` : (name || email);
                const value = email || name;

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action';
                btn.textContent = label;
                btn.dataset.value = value;

                btn.addEventListener('click', () => {
                    input.value = btn.dataset.value;
                    hideSuggestions();
                    input.focus();
                });

                list.appendChild(btn);
            });

            list.style.display = 'block';
        }

        function hideSuggestions() {
            list.style.display = 'none';
            list.innerHTML = '';
        }
    });

    document.addEventListener('submit', () => {
        sessionStorage.setItem(scrollKey, String(window.scrollY));
    });

    const reservationSelectForm = document.querySelector('form input[name="mode"][value="select_reservation"]');
    if (reservationSelectForm) {
        const form = reservationSelectForm.closest('form');
        const select = form ? form.querySelector('select[name="reservation_id"]') : null;
        if (form && select) {
            select.addEventListener('change', () => {
                form.submit();
            });
        }
    }
})();

// Prevent selecting the same asset twice for a model
(function () {
    const groups = {};
    document.querySelectorAll('[data-model-select]').forEach((sel) => {
        const mid = sel.getAttribute('data-model-select');
        if (!groups[mid]) groups[mid] = [];
        groups[mid].push(sel);
        sel.addEventListener('change', () => syncGroup(mid));
    });

    function syncGroup(mid) {
        const selects = groups[mid] || [];
        const chosen  = new Set();
        selects.forEach((s) => {
            if (s.value) chosen.add(s.value);
        });
        selects.forEach((s) => {
            Array.from(s.options).forEach((opt) => {
                if (!opt.value) {
                    opt.disabled = false;
                    return;
                }
                if (opt.selected) {
                    opt.disabled = false;
                    return;
                }
                opt.disabled = chosen.has(opt.value);
            });
        });
    }

    Object.keys(groups).forEach(syncGroup);
})();
</script>
<?php if (!$embedded): ?>
<?php layout_footer(); ?>
</body>
</html>
<?php endif; ?>
