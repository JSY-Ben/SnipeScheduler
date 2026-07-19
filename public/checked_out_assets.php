<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/staff_group_visibility.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/booking_helpers.php';

function checked_out_attach_image_paths(PDO $pdo, array $rows): array
{
    $modelIds = [];
    $accessoryIds = [];
    foreach ($rows as $row) {
        if (checked_out_row_type_key($row) === 'accessory') {
            $id = (int)($row['accessory_id'] ?? ($row['id'] ?? 0));
            if ($id > 0) {
                $accessoryIds[$id] = true;
            }
            continue;
        }

        $modelId = (int)($row['model']['id'] ?? ($row['model_id'] ?? 0));
        if ($modelId > 0) {
            $modelIds[$modelId] = true;
        }
    }

    $modelImages = [];
    $accessoryImages = [];
    try {
        if (!empty($modelIds)) {
            $ids = array_keys($modelIds);
            $stmt = $pdo->prepare('SELECT model_id, image_path FROM catalogue_model_cache WHERE model_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $imageRow) {
                $modelImages[(int)$imageRow['model_id']] = trim((string)($imageRow['image_path'] ?? ''));
            }
        }
        if (!empty($accessoryIds)) {
            $ids = array_keys($accessoryIds);
            $stmt = $pdo->prepare('SELECT accessory_id, image_path FROM catalogue_accessory_cache WHERE accessory_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $imageRow) {
                $accessoryImages[(int)$imageRow['accessory_id']] = trim((string)($imageRow['image_path'] ?? ''));
            }
        }
    } catch (Throwable $e) {
        // Older installations may not have catalogue image caches yet.
    }

    foreach ($rows as &$row) {
        $imagePath = booking_extract_catalogue_item_image_path($row);
        if ($imagePath === '' && isset($row['model']) && is_array($row['model'])) {
            $imagePath = booking_extract_catalogue_item_image_path($row['model']);
        }
        if ($imagePath === '') {
            if (checked_out_row_type_key($row) === 'accessory') {
                $id = (int)($row['accessory_id'] ?? ($row['id'] ?? 0));
                $imagePath = $accessoryImages[$id] ?? '';
            } else {
                $modelId = (int)($row['model']['id'] ?? ($row['model_id'] ?? 0));
                $imagePath = $modelImages[$modelId] ?? '';
            }
        }
        $row['_image_path'] = $imagePath;
    }
    unset($row);

    return $rows;
}

function load_asset_labels(PDO $pdo, array $assetIds): array
{
    $assetIds = array_values(array_filter(array_map('intval', $assetIds), static function (int $id): bool {
        return $id > 0;
    }));
    if (empty($assetIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
    $stmt = $pdo->prepare("
        SELECT asset_id, asset_tag, asset_name, model_name
          FROM checked_out_asset_cache
         WHERE asset_id IN ({$placeholders})
    ");
    $stmt->execute($assetIds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    foreach ($rows as $row) {
        $assetId = (int)($row['asset_id'] ?? 0);
        if ($assetId <= 0) {
            continue;
        }
        $tag = trim((string)($row['asset_tag'] ?? ''));
        $name = trim((string)($row['asset_name'] ?? ''));
        $model = trim((string)($row['model_name'] ?? ''));
        if ($tag === '') {
            $tag = 'Asset #' . $assetId;
        }
        $suffix = $model !== '' ? $model : $name;
        $labels[$assetId] = $suffix !== '' ? ($tag . ' (' . $suffix . ')') : $tag;
    }

    return $labels;
}

function format_display_date($val): string
{
    if (is_array($val)) {
        $val = $val['datetime'] ?? ($val['date'] ?? '');
    }
    if (empty($val)) {
        return '';
    }
    return app_format_date($val);
}

function format_display_datetime($val): string
{
    if (is_array($val)) {
        $val = $val['datetime'] ?? ($val['date'] ?? '');
    }
    if (empty($val)) {
        return '';
    }
    return app_format_datetime($val);
}

function normalize_expected_datetime(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    $dateTime = app_parse_local_datetime_input($raw, app_get_timezone());
    if (!$dateTime) {
        return '';
    }
    return $dateTime->format('Y-m-d H:i');
}

function expected_to_timestamp($value): ?int
{
    if (is_array($value)) {
        $value = $value['datetime'] ?? ($value['date'] ?? '');
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
        $value .= ' 23:59:59';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return $ts;
}

function checked_out_row_type_key(array $row): string
{
    return (($row['item_type'] ?? 'asset') === 'accessory') ? 'accessory' : 'asset';
}

function checked_out_apply_display_visibility(PDO $pdo, array $rows, array $config): array
{
    $catalogue = is_array($config['catalogue'] ?? null) ? $config['catalogue'] : [];
    $showAssets = array_key_exists('show_models_tab', $catalogue)
        ? !empty($catalogue['show_models_tab'])
        : true;
    $showAccessories = array_key_exists('show_accessories_tab', $catalogue)
        ? !empty($catalogue['show_accessories_tab'])
        : true;

    $checkedOut = is_array($config['checked_out'] ?? null) ? $config['checked_out'] : [];
    $allowedCategories = [];
    foreach (($checkedOut['allowed_categories'] ?? []) as $categoryId) {
        $categoryId = (int)$categoryId;
        if ($categoryId > 0) {
            $allowedCategories[$categoryId] = true;
        }
    }

    $allowedModelIds = [];
    $allowedAccessoryIds = [];
    if (!empty($allowedCategories)) {
        $categoryIds = array_keys($allowedCategories);
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));

        $modelStmt = $pdo->prepare("SELECT model_id FROM catalogue_model_cache WHERE category_id IN ({$placeholders})");
        $modelStmt->execute($categoryIds);
        foreach ($modelStmt->fetchAll(PDO::FETCH_COLUMN) as $modelId) {
            $allowedModelIds[(int)$modelId] = true;
        }

        $accessoryStmt = $pdo->prepare("SELECT accessory_id FROM catalogue_accessory_cache WHERE category_id IN ({$placeholders})");
        $accessoryStmt->execute($categoryIds);
        foreach ($accessoryStmt->fetchAll(PDO::FETCH_COLUMN) as $accessoryId) {
            $allowedAccessoryIds[(int)$accessoryId] = true;
        }
    }

    return array_values(array_filter($rows, static function (array $row) use (
        $showAssets,
        $showAccessories,
        $allowedCategories,
        $allowedModelIds,
        $allowedAccessoryIds
    ): bool {
        if (checked_out_row_type_key($row) === 'accessory') {
            if (!$showAccessories) {
                return false;
            }
            if (empty($allowedCategories)) {
                return true;
            }

            $categoryId = (int)($row['category_id'] ?? 0);
            if ($categoryId > 0) {
                return isset($allowedCategories[$categoryId]);
            }

            $accessoryId = (int)($row['accessory_id'] ?? ($row['id'] ?? 0));
            return $accessoryId > 0 && isset($allowedAccessoryIds[$accessoryId]);
        }

        if (!$showAssets) {
            return false;
        }
        if (empty($allowedCategories)) {
            return true;
        }

        $categoryId = (int)($row['model']['category']['id'] ?? ($row['category_id'] ?? 0));
        if ($categoryId > 0) {
            return isset($allowedCategories[$categoryId]);
        }

        $modelId = (int)($row['model']['id'] ?? ($row['model_id'] ?? 0));
        return $modelId > 0 && isset($allowedModelIds[$modelId]);
    }));
}

function checked_out_row_identifier(array $row): string
{
    if (checked_out_row_type_key($row) === 'accessory') {
        $accessoryId = (int)($row['accessory_id'] ?? ($row['id'] ?? 0));
        return $accessoryId > 0 ? ('Accessory #' . $accessoryId) : 'Accessory';
    }

    $assetTag = trim((string)($row['asset_tag'] ?? ''));
    if ($assetTag !== '') {
        return $assetTag;
    }

    $assetId = (int)($row['id'] ?? 0);
    return $assetId > 0 ? ('Asset #' . $assetId) : 'Equipment';
}

function checked_out_row_name(array $row): string
{
    $name = trim((string)($row['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    return checked_out_row_identifier($row);
}

function checked_out_row_details(array $row): string
{
    if (checked_out_row_type_key($row) === 'accessory') {
        $parts = [];
        $manufacturer = trim((string)($row['manufacturer_name'] ?? ''));
        $category = trim((string)($row['category_name'] ?? ''));
        $quantity = max(1, (int)($row['assigned_qty'] ?? 1));

        if ($manufacturer !== '') {
            $parts[] = $manufacturer;
        }
        if ($category !== '') {
            $parts[] = $category;
        }
        $parts[] = 'Qty x' . $quantity;

        return implode(' • ', $parts);
    }

    return trim((string)($row['model']['name'] ?? ''));
}

function checked_out_row_user_display(array $row): string
{
    $user = $row['assigned_to'] ?? ($row['assigned_to_fullname'] ?? '');
    if (is_array($user)) {
        return trim((string)($user['name'] ?? ($user['username'] ?? ($user['email'] ?? ''))));
    }

    return trim((string)$user);
}

function checked_out_row_user_email(array $row): string
{
    $user = $row['assigned_to'] ?? [];
    if (is_array($user)) {
        $email = trim((string)($user['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }
    }

    return trim((string)($row['assigned_to_email'] ?? ''));
}

function checked_out_parse_bulk_selection($rawItems): array
{
    $items = is_array($rawItems) ? $rawItems : [];
    $assetIds = [];
    $accessories = [];
    $seenAccessories = [];

    foreach ($items as $rawItem) {
        $rawItem = trim((string)$rawItem);
        if ($rawItem === '') {
            continue;
        }

        if (preg_match('/^asset:(\d+)$/', $rawItem, $matches)) {
            $assetId = (int)$matches[1];
            if ($assetId > 0) {
                $assetIds[] = $assetId;
            }
            continue;
        }

        if (preg_match('/^accessory:(\d+):(\d+)$/', $rawItem, $matches)) {
            $accessoryId = (int)$matches[1];
            $accessoryCheckoutId = (int)$matches[2];
            if ($accessoryId > 0 && $accessoryCheckoutId > 0 && !isset($seenAccessories[$accessoryCheckoutId])) {
                $accessories[] = [
                    'accessory_id' => $accessoryId,
                    'accessory_checkout_id' => $accessoryCheckoutId,
                ];
                $seenAccessories[$accessoryCheckoutId] = true;
            }
            continue;
        }

        if (ctype_digit($rawItem)) {
            $assetId = (int)$rawItem;
            if ($assetId > 0) {
                $assetIds[] = $assetId;
            }
        }
    }

    return [
        'asset_ids' => array_values(array_unique($assetIds)),
        'accessories' => $accessories,
    ];
}

$active    = basename($_SERVER['PHP_SELF']);
$isAdmin   = !empty($currentUser['is_admin']);
$isStaff   = !empty($currentUser['is_staff']) || $isAdmin;
$config    = load_config();
$restrictReservationsToSameGroup = staff_group_visibility_restriction_enabled($config, $currentUser);
$embedded  = defined('RESERVATIONS_EMBED');
$pageBase  = $embedded ? 'reservations.php' : 'checked_out_assets.php';
$baseQuery = $embedded ? ['tab' => 'checked_out'] : [];
$messages  = [];

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$viewRaw = $_REQUEST['view'] ?? ($_REQUEST['tab'] ?? 'all');
$view    = $viewRaw === 'overdue' ? 'overdue' : 'all';
$error   = '';
$assets  = [];
$search  = trim($_GET['q'] ?? '');
$sortRaw = trim($_GET['sort'] ?? '');
$pageRaw = (int)($_GET['page'] ?? 1);
$perPageRaw = (int)($_GET['per_page'] ?? 10);
$page = $pageRaw > 0 ? $pageRaw : 1;
$perPageOptions = [10, 25, 50, 100];
$perPage = in_array($perPageRaw, $perPageOptions, true) ? $perPageRaw : 10;
$sortOptions = [
    'expected_asc',
    'expected_desc',
    'tag_asc',
    'tag_desc',
    'item_asc',
    'item_desc',
    'model_asc',
    'model_desc',
    'user_asc',
    'user_desc',
    'checkout_desc',
    'checkout_asc',
];
$sort = in_array($sortRaw, $sortOptions, true) ? $sortRaw : 'expected_asc';
$forceRefresh = isset($_REQUEST['refresh']) && $_REQUEST['refresh'] === '1';

// Handle row and bulk actions (all/overdue tabs)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check in single
    if (isset($_POST['checkin_asset_id'])) {
        $checkinId = (int)$_POST['checkin_asset_id'];
        if ($checkinId > 0) {
            try {
                $labels = load_asset_labels($pdo, [$checkinId]);
                $label = $labels[$checkinId] ?? ('Asset #' . $checkinId);

                checkin_asset($checkinId);

                $deleteStmt = $pdo->prepare('DELETE FROM checked_out_asset_cache WHERE asset_id = :asset_id');
                $deleteStmt->execute([':asset_id' => $checkinId]);

                $messages[] = "Checked in {$label}.";
                activity_log_event('asset_checked_in', 'Checked out asset checked in', [
                    'subject_type' => 'asset',
                    'subject_id'   => $checkinId,
                    'metadata'     => [
                        'assets' => [$label],
                    ],
                ]);
            } catch (Throwable $e) {
                $error = 'Could not check in equipment: ' . $e->getMessage();
            }
        } else {
            $error = 'Select valid equipment to check in.';
        }
    } elseif (isset($_POST['checkin_accessory_checkout'])) {
        $rawCheckinValue = trim((string)$_POST['checkin_accessory_checkout']);
        $accessoryId = 0;
        $accessoryCheckoutId = 0;
        if (preg_match('/^(\d+):(\d+)$/', $rawCheckinValue, $matches)) {
            $accessoryId = (int)$matches[1];
            $accessoryCheckoutId = (int)$matches[2];
        }

        if ($accessoryId > 0 && $accessoryCheckoutId > 0) {
            try {
                checkin_accessory($accessoryCheckoutId);
                $messages[] = "Checked in accessory #{$accessoryId}.";
                activity_log_event('accessory_checked_in', 'Checked out accessory checked in', [
                    'subject_type' => 'accessory',
                    'subject_id'   => $accessoryId,
                    'metadata'     => [
                        'accessory_checkout_id' => $accessoryCheckoutId,
                    ],
                ]);
            } catch (Throwable $e) {
                $error = 'Could not check in accessory: ' . $e->getMessage();
            }
        } else {
            $error = 'Select a valid accessory row to check in.';
        }
    } elseif (isset($_POST['renew_asset_id'])) {
        // Renew single
        $renewId = (int)$_POST['renew_asset_id'];
        $renewExpected = '';
        if (isset($_POST['renew_expected']) && is_array($_POST['renew_expected'])) {
            $renewExpected = $_POST['renew_expected'][$renewId] ?? '';
        }
        $normalized = normalize_expected_datetime($renewExpected);
        if ($renewId > 0 && $normalized !== '') {
            try {
                update_asset_expected_checkin($renewId, $normalized);
                $messages[] = "Extended expected check-in to " . format_display_datetime($normalized) . " for equipment #{$renewId}.";
                $labels = load_asset_labels($pdo, [$renewId]);
                $label = $labels[$renewId] ?? ('Asset #' . $renewId);
                activity_log_event('asset_renewed', 'Checked out asset renewed', [
                    'subject_type' => 'asset',
                    'subject_id'   => $renewId,
                    'metadata'     => [
                        'assets' => [$label],
                        'expected_checkin' => $normalized,
                    ],
                ]);
            } catch (Throwable $e) {
                $error = 'Could not renew equipment: ' . $e->getMessage();
            }
        } else {
            $error = 'Select a valid date/time for renewal.';
        }
    } elseif (isset($_POST['bulk_checkin']) && $_POST['bulk_checkin'] === '1') {
        $selection = checked_out_parse_bulk_selection($_POST['bulk_checked_out_items'] ?? ($_POST['bulk_asset_ids'] ?? []));
        $assetIds = $selection['asset_ids'];
        $accessorySelections = $selection['accessories'];
        if (empty($assetIds) && empty($accessorySelections)) {
            $error = 'Select at least one item to check in.';
        } else {
            $labels = load_asset_labels($pdo, $assetIds);
            $checkedInLabels = [];
            $failedLabels = [];
            $deleteStmt = $pdo->prepare('DELETE FROM checked_out_asset_cache WHERE asset_id = :asset_id');

            foreach ($assetIds as $aid) {
                $label = $labels[$aid] ?? ('Asset #' . $aid);
                try {
                    checkin_asset($aid);
                    $deleteStmt->execute([':asset_id' => $aid]);
                    $checkedInLabels[] = $label;
                } catch (Throwable $e) {
                    $failedLabels[] = $label . ' (' . $e->getMessage() . ')';
                }
            }

            foreach ($accessorySelections as $accessorySelection) {
                $accessoryId = (int)($accessorySelection['accessory_id'] ?? 0);
                $accessoryCheckoutId = (int)($accessorySelection['accessory_checkout_id'] ?? 0);
                $label = 'Accessory #' . $accessoryId;
                try {
                    checkin_accessory($accessoryCheckoutId);
                    $checkedInLabels[] = $label;
                } catch (Throwable $e) {
                    $failedLabels[] = $label . ' (' . $e->getMessage() . ')';
                }
            }

            if (!empty($checkedInLabels)) {
                $messages[] = 'Checked in ' . count($checkedInLabels) . ' item(s).';
                activity_log_event('checked_out_items_checked_in', 'Checked out items checked in', [
                    'metadata' => [
                        'items' => $checkedInLabels,
                        'count' => count($checkedInLabels),
                    ],
                ]);
            }

            if (!empty($failedLabels)) {
                $error = 'Could not check in ' . count($failedLabels) . ' item(s): ' . implode('; ', $failedLabels);
            }
        }
    } elseif (isset($_POST['bulk_renew']) && $_POST['bulk_renew'] === '1') {
        // Renew selected items
        $bulkExpected = normalize_expected_datetime($_POST['bulk_expected'] ?? '');
        $selection = checked_out_parse_bulk_selection($_POST['bulk_checked_out_items'] ?? ($_POST['bulk_asset_ids'] ?? []));
        $assetIds = $selection['asset_ids'];
        $accessorySelections = $selection['accessories'];
        if (!empty($accessorySelections)) {
            $error = 'Untick accessories before renewing; accessories cannot be renewed.';
        } elseif ($bulkExpected === '') {
            $error = 'Select a valid date/time for bulk renewal.';
        } elseif (empty($assetIds)) {
            $error = 'Select at least one equipment row to renew.';
        } else {
            try {
                $count = 0;
                foreach ($assetIds as $aid) {
                    update_asset_expected_checkin($aid, $bulkExpected);
                    $count++;
                }
                $messages[] = "Extended expected check-in to " . format_display_datetime($bulkExpected) . " for {$count} equipment item(s).";
                $labels = load_asset_labels($pdo, $assetIds);
                $assetLabels = array_values(array_filter(array_map(static function (int $id) use ($labels): string {
                    return $labels[$id] ?? ('Asset #' . $id);
                }, $assetIds)));
                activity_log_event('assets_renewed', 'Checked out assets renewed', [
                    'metadata' => [
                        'assets' => $assetLabels,
                        'expected_checkin' => $bulkExpected,
                        'count' => $count,
                    ],
                ]);
            } catch (Throwable $e) {
                $error = 'Could not renew selected equipment: ' . $e->getMessage();
            }
        }
    }
}

try {
    $assets = $forceRefresh
        ? fetch_checked_out_assets_from_snipeit(false, 0, false)
        : list_checked_out_assets(false);
    $accessories = fetch_checked_out_accessories_from_snipeit(!$forceRefresh);
    $assets = array_merge($assets, $accessories);
    $assets = checked_out_apply_display_visibility($pdo, $assets, $config);
    $assets = checked_out_attach_image_paths($pdo, $assets);
    if ($restrictReservationsToSameGroup) {
        $cachedVisibleEmails = staff_group_visibility_cached_visible_emails_for_current_user(
            $currentUser,
            $restrictReservationsToSameGroup
        );
        $fastVisibleEmails = is_array($cachedVisibleEmails)
            ? $cachedVisibleEmails
            : staff_group_visibility_visible_user_emails_for_current_user($currentUser, true);
        $visibleEmailLookup = [];
        if (is_array($fastVisibleEmails)) {
            foreach ($fastVisibleEmails as $email) {
                $email = strtolower(trim((string)$email));
                if ($email !== '') {
                    $visibleEmailLookup[$email] = $email;
                }
            }
        }
        $assets = array_values(array_filter($assets, static function (array $row) use ($currentUser, $restrictReservationsToSameGroup, $visibleEmailLookup, $cachedVisibleEmails): bool {
            $email = strtolower(trim(staff_group_visibility_checked_out_row_email($row)));
            if ($email !== '' && isset($visibleEmailLookup[$email])) {
                return true;
            }
            if (is_array($cachedVisibleEmails)) {
                return false;
            }

            return staff_group_visibility_checked_out_row_visible($row, $currentUser, $restrictReservationsToSameGroup);
        }));
    }
    if ($view === 'overdue') {
        $now = time();
        $assets = array_values(array_filter($assets, function ($row) use ($now) {
            $ts = expected_to_timestamp($row['expected_checkin'] ?? '');
            return $ts !== null && $ts <= $now;
        }));
    }
    if ($search !== '') {
        $q = mb_strtolower($search);
        $assets = array_values(array_filter($assets, function ($row) use ($q) {
            $fields = [
                checked_out_row_identifier($row),
                checked_out_row_name($row),
                checked_out_row_details($row),
                checked_out_row_user_display($row),
            ];
            foreach ($fields as $f) {
                if (mb_stripos((string)$f, $q) !== false) {
                    return true;
                }
            }

            return false;
        }));
    }
    usort($assets, function ($a, $b) use ($sort) {
        $aTag = strtolower(checked_out_row_identifier($a));
        $bTag = strtolower(checked_out_row_identifier($b));
        $aItem = strtolower(checked_out_row_name($a));
        $bItem = strtolower(checked_out_row_name($b));
        $aModel = strtolower(checked_out_row_details($a));
        $bModel = strtolower(checked_out_row_details($b));
        $aUser = strtolower(checked_out_row_user_display($a));
        $bUser = strtolower(checked_out_row_user_display($b));
        $aExpected = $a['_expected_checkin_norm'] ?? ($a['expected_checkin'] ?? '');
        $bExpected = $b['_expected_checkin_norm'] ?? ($b['expected_checkin'] ?? '');
        if (is_array($aExpected)) {
            $aExpected = $aExpected['datetime'] ?? ($aExpected['date'] ?? '');
        }
        if (is_array($bExpected)) {
            $bExpected = $bExpected['datetime'] ?? ($bExpected['date'] ?? '');
        }
        $aExpectedTs = $aExpected ? strtotime((string)$aExpected) : 0;
        $bExpectedTs = $bExpected ? strtotime((string)$bExpected) : 0;
        if ($aExpectedTs === false) {
            $aExpectedTs = 0;
        }
        if ($bExpectedTs === false) {
            $bExpectedTs = 0;
        }
        $aCheckout = $a['_last_checkout_norm'] ?? ($a['last_checkout'] ?? '');
        $bCheckout = $b['_last_checkout_norm'] ?? ($b['last_checkout'] ?? '');
        if (is_array($aCheckout)) {
            $aCheckout = $aCheckout['datetime'] ?? ($aCheckout['date'] ?? '');
        }
        if (is_array($bCheckout)) {
            $bCheckout = $bCheckout['datetime'] ?? ($bCheckout['date'] ?? '');
        }
        $aCheckoutTs = $aCheckout ? strtotime((string)$aCheckout) : 0;
        $bCheckoutTs = $bCheckout ? strtotime((string)$bCheckout) : 0;
        if ($aCheckoutTs === false) {
            $aCheckoutTs = 0;
        }
        if ($bCheckoutTs === false) {
            $bCheckoutTs = 0;
        }

        $cmpText = function (string $left, string $right) {
            return $left <=> $right;
        };
        $cmpNum = function (int $left, int $right) {
            return $left <=> $right;
        };

        switch ($sort) {
            case 'expected_desc':
                return $cmpNum($bExpectedTs ?: PHP_INT_MAX, $aExpectedTs ?: PHP_INT_MAX);
            case 'expected_asc':
                return $cmpNum($aExpectedTs ?: PHP_INT_MAX, $bExpectedTs ?: PHP_INT_MAX);
            case 'tag_desc':
                return $cmpText($bTag, $aTag);
            case 'tag_asc':
                return $cmpText($aTag, $bTag);
            case 'item_desc':
                return $cmpText($bItem, $aItem);
            case 'item_asc':
                return $cmpText($aItem, $bItem);
            case 'model_desc':
                return $cmpText($bModel, $aModel);
            case 'model_asc':
                return $cmpText($aModel, $bModel);
            case 'user_desc':
                return $cmpText($bUser, $aUser);
            case 'user_asc':
                return $cmpText($aUser, $bUser);
            case 'checkout_asc':
                return $cmpNum($aCheckoutTs, $bCheckoutTs);
            case 'checkout_desc':
                return $cmpNum($bCheckoutTs, $aCheckoutTs);
        }
        return 0;
    });
    $totalRows = count($assets);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $assets = array_slice($assets, $offset, $perPage);
} catch (Throwable $e) {
    $error = $e->getMessage();
    $totalRows = 0;
    $totalPages = 1;
}

?>
<?php
function layout_checked_out_url(string $base, array $params): string
{
    $query = http_build_query($params);
    return $query === '' ? $base : ($base . '?' . $query);
}
?>
<?php if (!$embedded): ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checked Out Reservations – <?= h(layout_app_name()) ?></title>
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
            <h1>Checked Out Reservations</h1>
            <div class="page-subtitle">
                Showing equipment and accessories currently checked out in Snipe-IT.
                <?php if ($restrictReservationsToSameGroup): ?>
                    Only users in one of your Snipe-IT groups are shown.
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$embedded): ?>
            <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <?php endif; ?>

        <?php
            $tabBaseParams = $baseQuery;
            $allParams     = array_merge($tabBaseParams, ['view' => 'all', 'per_page' => $perPage, 'sort' => $sort]);
            $overdueParams = array_merge($tabBaseParams, ['view' => 'overdue', 'per_page' => $perPage, 'sort' => $sort]);
            if ($search !== '') {
                $allParams['q']     = $search;
                $overdueParams['q'] = $search;
            }
            $allUrl     = layout_checked_out_url($pageBase, $allParams);
            $overdueUrl = layout_checked_out_url($pageBase, $overdueParams);
        ?>

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $view === 'all' ? 'active' : '' ?>"
                   href="<?= h($allUrl) ?>">All checked out</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $view === 'overdue' ? 'active' : '' ?>"
                   href="<?= h($overdueUrl) ?>">Overdue</a>
            </li>
        </ul>

        <div class="border rounded-3 p-4 mb-4">
            <form method="get" class="row g-2 mb-0 align-items-end" action="<?= h($pageBase) ?>" id="checked-out-filter-form">
            <?php foreach ($baseQuery as $k => $v): ?>
                <input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>">
            <?php endforeach; ?>
            <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
            <div class="col-md-4">
                <input type="text"
                       name="q"
                       value="<?= htmlspecialchars($search) ?>"
                       class="form-control form-control-lg"
                       placeholder="Filter by identifier, item, details, or user">
            </div>
            <div class="col-md-2">
                <select id="checked-out-sort" name="sort" class="form-select form-select-lg" aria-label="Sort checked-out items">
                    <option value="expected_asc" <?= $sort === 'expected_asc' ? 'selected' : '' ?>>Expected check-in (soonest first)</option>
                    <option value="expected_desc" <?= $sort === 'expected_desc' ? 'selected' : '' ?>>Expected check-in (latest first)</option>
                    <option value="tag_asc" <?= $sort === 'tag_asc' ? 'selected' : '' ?>>Identifier (A–Z)</option>
                    <option value="tag_desc" <?= $sort === 'tag_desc' ? 'selected' : '' ?>>Identifier (Z–A)</option>
                    <option value="item_asc" <?= $sort === 'item_asc' ? 'selected' : '' ?>>Item (A–Z)</option>
                    <option value="item_desc" <?= $sort === 'item_desc' ? 'selected' : '' ?>>Item (Z–A)</option>
                    <option value="model_asc" <?= $sort === 'model_asc' ? 'selected' : '' ?>>Details (A–Z)</option>
                    <option value="model_desc" <?= $sort === 'model_desc' ? 'selected' : '' ?>>Details (Z–A)</option>
                    <option value="user_asc" <?= $sort === 'user_asc' ? 'selected' : '' ?>>User (A–Z)</option>
                    <option value="user_desc" <?= $sort === 'user_desc' ? 'selected' : '' ?>>User (Z–A)</option>
                    <option value="checkout_desc" <?= $sort === 'checkout_desc' ? 'selected' : '' ?>>Assigned since (newest first)</option>
                    <option value="checkout_asc" <?= $sort === 'checkout_asc' ? 'selected' : '' ?>>Assigned since (oldest first)</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="per_page" class="form-select form-select-lg">
                    <?php foreach ($perPageOptions as $opt): ?>
                        <option value="<?= $opt ?>" <?= $perPage === $opt ? 'selected' : '' ?>>
                            <?= $opt ?> per page
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <?php
                    $clearParams = $tabBaseParams;
                    $clearParams['view'] = $view;
                    $clearParams['per_page'] = $perPage;
                    $clearUrl = layout_checked_out_url($pageBase, $clearParams);
                ?>
                <a href="<?= h($clearUrl) ?>" class="btn btn-outline-secondary w-100">Clear</a>
            </div>
        </form>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($messages)): ?>
            <div class="alert alert-success">
                <ul class="mb-0">
                    <?php foreach ($messages as $m): ?>
                        <li><?= h($m) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (empty($assets) && !$error): ?>
            <div class="alert alert-secondary">
                No <?= $view === 'overdue' ? 'overdue ' : '' ?>checked-out equipment or accessories.
            </div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="view" value="<?= h($view) ?>">
                <?php if ($search !== ''): ?>
                    <input type="hidden" name="q" value="<?= h($search) ?>">
                <?php endif; ?>
                <input type="hidden" name="per_page" value="<?= (int)$perPage ?>">
                <input type="hidden" name="page" value="<?= (int)$page ?>">
                <input type="hidden" name="sort" value="<?= h($sort) ?>">
                <div class="d-flex flex-wrap gap-2 align-items-end mb-2">
                    <div>
                        <label class="form-label mb-1">Renew selected to</label>
                        <input type="datetime-local"
                               name="bulk_expected"
                               id="bulk-renew-expected"
                               class="form-control form-control-sm">
                    </div>
                    <button type="submit"
                            name="bulk_renew"
                            value="1"
                            id="bulk-renew-button"
                            class="btn btn-outline-primary btn-sm">
                        Renew selected equipment
                    </button>
                    <button type="submit"
                            name="bulk_checkin"
                            value="1"
                            class="btn btn-outline-success btn-sm"
                            onclick="return confirm('Check in all selected items in Snipe-IT?');">
                        Check in selected items
                    </button>
                </div>
                <div class="text-muted small mb-2">Accessory rows can be selected and checked in with equipment. Renew is disabled when any selected row is an accessory.</div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" id="select-all-assets">
                    <label class="form-check-label" for="select-all-assets">
                        Select all check-in-capable items on this page
                    </label>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle checked-out-table">
                        <thead>
                            <tr>
                                <th></th>
                                <th><?= layout_sortable_column_header('Identifier', 'tag_asc', 'tag_desc', $sort) ?></th>
                                <th><?= layout_sortable_column_header('Item', 'item_asc', 'item_desc', $sort) ?></th>
                                <th><?= layout_sortable_column_header('Details', 'model_asc', 'model_desc', $sort) ?></th>
                                <th><?= layout_sortable_column_header('User', 'user_asc', 'user_desc', $sort) ?></th>
                                <th><?= layout_sortable_column_header('Assigned Since', 'checkout_asc', 'checkout_desc', $sort) ?></th>
                                <th><?= layout_sortable_column_header('Expected Check-in', 'expected_asc', 'expected_desc', $sort) ?></th>
                                <th>Renew to</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assets as $a): ?>
                                <?php
                                    $rowType = checked_out_row_type_key($a);
                                    $isAccessory = $rowType === 'accessory';
                                    $aid = (int)($a['id'] ?? 0);
                                    $accessoryId = $isAccessory ? (int)($a['accessory_id'] ?? $aid) : 0;
                                    $accessoryCheckoutId = $isAccessory ? (int)($a['accessory_checkout_id'] ?? 0) : 0;
                                    $canCheckinAccessory = $isAccessory && $accessoryId > 0 && $accessoryCheckoutId > 0;
                                    $identifier = checked_out_row_identifier($a);
                                    $name = checked_out_row_name($a);
                                    $details = checked_out_row_details($a);
                                    $user = checked_out_row_user_display($a);
                                    $userEmail = checked_out_row_user_email($a);
                                    $assignedQty = max(1, (int)($a['assigned_qty'] ?? 1));
                                    $checkedOut = $a['_last_checkout_norm'] ?? ($a['last_checkout'] ?? '');
                                    $expected   = $a['_expected_checkin_norm'] ?? ($a['expected_checkin'] ?? '');
                                    $checkedOutTs = $checkedOut ? strtotime($checkedOut) : 0;
                                    $expectedTs = expected_to_timestamp($expected);
                                    $isOverdue = $expectedTs !== null && $expectedTs < time();
                                    $imagePath = trim((string)($a['_image_path'] ?? ''));
                                    $imageUrl = $imagePath !== '' ? 'image_proxy.php?src=' . urlencode($imagePath) : '';
                                ?>
                                <tr data-asset-tag="<?= h(strtolower($identifier)) ?>"
                                    data-asset-name="<?= h(strtolower($name)) ?>"
                                    data-model="<?= h(strtolower($details)) ?>"
                                    data-user="<?= h(strtolower($user)) ?>"
                                    data-expected-ts="<?= (int)$expectedTs ?>"
                                    data-checkout-ts="<?= (int)$checkedOutTs ?>">
                                    <td>
                                        <input class="form-check-input checked-out-selection"
                                               type="checkbox"
                                               name="bulk_checked_out_items[]"
                                               value="<?= $isAccessory ? h('accessory:' . $accessoryId . ':' . $accessoryCheckoutId) : h('asset:' . $aid) ?>"
                                               data-item-type="<?= $isAccessory ? 'accessory' : 'asset' ?>"
                                               <?php if (($isAccessory && !$canCheckinAccessory) || (!$isAccessory && $aid <= 0)): ?>disabled<?php endif; ?>>
                                    </td>
                                    <td><?= h($identifier) ?></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($imageUrl !== ''): ?>
                                                <button type="button"
                                                        class="image-preview-trigger"
                                                        data-image-preview
                                                        data-image-title="<?= h($name) ?>"
                                                        aria-label="View full-size image of <?= h($name) ?>"
                                                        aria-haspopup="dialog">
                                                    <img src="<?= h($imageUrl) ?>"
                                                         alt="<?= h($name . ' thumbnail') ?>"
                                                         loading="lazy"
                                                         style="width:48px;height:48px;object-fit:contain;border:1px solid rgba(0,0,0,.12);border-radius:.35rem;background:#fff;flex:0 0 48px;">
                                                </button>
                                            <?php else: ?>
                                                <div class="d-flex align-items-center justify-content-center text-muted border rounded bg-white"
                                                     aria-label="No image available"
                                                     style="width:48px;height:48px;font-size:9px;text-align:center;line-height:1.1;flex:0 0 48px;">No image</div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="fw-semibold"><?= h($name) ?></div>
                                                <?php if ($isAccessory && $assignedQty > 1): ?>
                                                    <div class="text-muted small">Checked out quantity: <?= (int)$assignedQty ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= h($details) ?></td>
                                    <td><?= $user !== '' ? layout_user_identity_by_email($user, $userEmail) : '' ?></td>
                                    <td><?= h(format_display_datetime($checkedOut)) ?></td>
                                    <td class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
                                        <?= h(format_display_datetime($expected)) ?>
                                    </td>
                                    <td>
                                        <?php if ($isAccessory): ?>
                                            <span class="text-muted small">Not available</span>
                                        <?php else: ?>
                                            <input type="datetime-local"
                                                   name="renew_expected[<?= $aid ?>]"
                                                   class="form-control form-control-sm">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isAccessory): ?>
                                            <div class="d-flex flex-wrap gap-2">
                                                <button type="submit"
                                                        name="checkin_accessory_checkout"
                                                        value="<?= h($accessoryId . ':' . $accessoryCheckoutId) ?>"
                                                        class="btn btn-sm btn-outline-success"
                                                        onclick="return confirm('Check in this accessory in Snipe-IT?');"
                                                        <?php if (!$canCheckinAccessory): ?>disabled<?php endif; ?>>
                                                    Check In
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <div class="d-flex flex-wrap gap-2">
                                                <button type="submit"
                                                        name="renew_asset_id"
                                                        value="<?= $aid ?>"
                                                        class="btn btn-sm btn-outline-primary checked-out-renew-action"
                                                        <?php if ($aid <= 0): ?>disabled<?php endif; ?>>
                                                    Renew
                                                </button>
                                                <button type="submit"
                                                        name="checkin_asset_id"
                                                        value="<?= $aid ?>"
                                                        class="btn btn-sm btn-outline-success"
                                                        onclick="return confirm('Check in this equipment in Snipe-IT?');"
                                                        <?php if ($aid <= 0): ?>disabled<?php endif; ?>>
                                                    Check In
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?php if ($totalPages > 1): ?>
                <?php
                    $pagerBase = $pageBase;
                    $pagerQuery = array_merge($baseQuery, [
                        'view' => $view,
                        'q' => $search,
                        'per_page' => $perPage,
                        'sort' => $sort,
                    ]);
                ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php
                            $prevPage = max(1, $page - 1);
                            $nextPage = min($totalPages, $page + 1);
                            $pagerQuery['page'] = $prevPage;
                            $prevUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                            $pagerQuery['page'] = $nextPage;
                            $nextUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                        ?>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($prevUrl) ?>">Previous</a>
                        </li>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php
                                $pagerQuery['page'] = $p;
                                $pageUrl = $pagerBase . '?' . http_build_query($pagerQuery);
                            ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= h($pageUrl) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= h($nextUrl) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const scrollKey = 'checked_out_scroll_y';
    const savedY = sessionStorage.getItem(scrollKey);
    if (savedY !== null) {
        const y = parseInt(savedY, 10);
        if (!Number.isNaN(y)) {
            window.scrollTo(0, y);
        }
        sessionStorage.removeItem(scrollKey);
    }

    document.addEventListener('submit', () => {
        sessionStorage.setItem(scrollKey, String(window.scrollY));
    });
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a');
        if (!link) {
            return;
        }
        const href = link.getAttribute('href') || '';
        if (href.includes('page=')) {
            sessionStorage.setItem(scrollKey, String(window.scrollY));
        }
    });

    const selectAll = document.getElementById('select-all-assets');
    const bulkRenewButton = document.getElementById('bulk-renew-button');
    const bulkRenewExpected = document.getElementById('bulk-renew-expected');
    const selectionBoxes = Array.from(document.querySelectorAll('input[name="bulk_checked_out_items[]"]'));
    const renewActions = Array.from(document.querySelectorAll('.checked-out-renew-action'));
    const accessoryRenewMessage = 'Accessories cannot be renewed. Untick accessories before renewing equipment.';
    [bulkRenewButton, bulkRenewExpected, ...renewActions].forEach((control) => {
        if (control) {
            control.dataset.initiallyDisabled = control.disabled ? '1' : '0';
        }
    });

    function updateBulkSelectionState() {
        const enabledBoxes = selectionBoxes.filter((box) => !box.disabled);
        const checkedBoxes = enabledBoxes.filter((box) => box.checked);
        const accessorySelected = checkedBoxes.some((box) => box.dataset.itemType === 'accessory');

        [bulkRenewButton, bulkRenewExpected, ...renewActions].forEach((control) => {
            if (!control) {
                return;
            }
            control.disabled = control.dataset.initiallyDisabled === '1' || accessorySelected;
            control.title = accessorySelected ? accessoryRenewMessage : '';
        });
        if (bulkRenewButton) {
            bulkRenewButton.classList.toggle('btn-outline-secondary', accessorySelected);
            bulkRenewButton.classList.toggle('btn-outline-primary', !accessorySelected);
        }

        if (selectAll) {
            selectAll.checked = enabledBoxes.length > 0 && checkedBoxes.length === enabledBoxes.length;
            selectAll.indeterminate = checkedBoxes.length > 0 && checkedBoxes.length < enabledBoxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            selectionBoxes.forEach((box) => {
                if (!box.disabled) {
                    box.checked = selectAll.checked;
                }
            });
            updateBulkSelectionState();
        });
    }
    selectionBoxes.forEach((box) => {
        box.addEventListener('change', updateBulkSelectionState);
    });
    updateBulkSelectionState();

    const sortSelect = document.getElementById('checked-out-sort');
    const filterForm = document.getElementById('checked-out-filter-form');
    if (sortSelect && filterForm) {
        sortSelect.addEventListener('change', function () {
            filterForm.submit();
        });
    }
});
</script>
<?php if (!$embedded): ?>
    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
<?php endif; ?>
<?php if (!empty($messages)): ?>
<script>
    // After showing renew success, reload the overdue list with a fresh Snipe-IT fetch.
    setTimeout(() => {
        const url = new URL(window.location.href);
        url.searchParams.set('view', '<?= h($view) ?>');
        url.searchParams.set('_', Date.now().toString());
        url.searchParams.set('refresh', '1');
        window.location.replace(url.toString());
    }, 4000);
</script>
<?php endif; ?>
