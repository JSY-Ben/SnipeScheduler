<?php
// quick_checkout.php
// Standalone bulk checkout page (ad-hoc, not tied to reservations).

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/reservation_policy.php';

$defaultEnd   = (new DateTime('tomorrow 9:00'))->format('Y-m-d\TH:i');

$endRaw   = $_POST['end_datetime'] ?? $defaultEnd;

$reservationConflicts = [];
$selectorTab = strtolower(trim((string)($_POST['active_tab'] ?? ($_GET['tab'] ?? 'assets'))));
$selectorTab = in_array($selectorTab, ['assets', 'accessories', 'kits'], true) ? $selectorTab : 'assets';
$browseSearchValue = trim((string)($_POST['browse_search'] ?? ($_GET['browse_search'] ?? '')));

// Helpers
function qc_display_datetime(?string $iso): string
{
    return app_format_datetime($iso);
}

/**
 * Return a stable session key for a quick-checkout list entry.
 */
function qc_checkout_session_key(string $entryType, int $id): string
{
    $entryType = strtolower(trim($entryType));
    if ($id <= 0 || $entryType === '') {
        throw new InvalidArgumentException('Quick checkout item key requires a valid type and ID.');
    }

    return $entryType . ':' . $id;
}

function qc_extract_status_label($status): string
{
    if (is_array($status)) {
        return trim((string)($status['name'] ?? $status['status_meta'] ?? $status['label'] ?? ''));
    }

    return trim((string)$status);
}

function qc_extract_record_image_path(array $record): string
{
    $candidates = [
        $record['image'] ?? null,
        $record['image_path'] ?? null,
        $record['image_url'] ?? null,
        $record['photo'] ?? null,
        is_array($record['image'] ?? null) ? ($record['image']['url'] ?? ($record['image']['path'] ?? ($record['image']['href'] ?? ''))) : null,
        is_array($record['category'] ?? null) ? ($record['category']['image'] ?? ($record['category']['image_path'] ?? '')) : null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }

        $value = trim($candidate);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function qc_image_proxy_url(string $imagePath): string
{
    $imagePath = trim($imagePath);
    if ($imagePath === '') {
        return '';
    }

    return 'image_proxy.php?src=' . urlencode($imagePath);
}

function qc_model_image_path(int $modelId): string
{
    static $cache = [];

    if ($modelId <= 0) {
        return '';
    }

    if (array_key_exists($modelId, $cache)) {
        return $cache[$modelId];
    }

    $record = booking_fetch_catalogue_item_record('model', $modelId);
    $cache[$modelId] = is_array($record) ? qc_extract_record_image_path($record) : '';

    return $cache[$modelId];
}

function qc_build_asset_checkout_entry(array $asset, string $sourceLabel = ''): array
{
    $assetId = (int)($asset['id'] ?? 0);
    if ($assetId <= 0) {
        throw new InvalidArgumentException('Quick checkout asset entry requires a valid asset ID.');
    }

    $modelId = isset($asset['model']) && is_array($asset['model'])
        ? (int)($asset['model']['id'] ?? 0)
        : (int)($asset['model_id'] ?? 0);
    $modelName = isset($asset['model']) && is_array($asset['model'])
        ? trim((string)($asset['model']['name'] ?? ''))
        : trim((string)($asset['model_name'] ?? ''));
    $assetTag = trim((string)($asset['asset_tag'] ?? ''));
    $assetName = trim((string)($asset['name'] ?? ''));
    $imagePath = qc_model_image_path($modelId);
    if ($imagePath === '') {
        $imagePath = qc_extract_record_image_path($asset);
    }

    return [
        'key' => qc_checkout_session_key('asset', $assetId),
        'entry_type' => 'asset',
        'item_type' => 'model',
        'asset_id' => $assetId,
        'item_id' => $modelId,
        'asset_tag' => $assetTag,
        'name' => $assetName,
        'model_id' => $modelId,
        'model_name' => $modelName,
        'status' => qc_extract_status_label($asset['status_label'] ?? ($asset['status'] ?? '')),
        'qty' => 1,
        'image_path' => $imagePath,
        'source_label' => trim($sourceLabel),
    ];
}

function qc_build_accessory_checkout_entry(array $accessory, int $quantity = 1): array
{
    $accessoryId = (int)($accessory['id'] ?? 0);
    if ($accessoryId <= 0) {
        throw new InvalidArgumentException('Quick checkout accessory entry requires a valid accessory ID.');
    }

    $manufacturer = is_array($accessory['manufacturer'] ?? null)
        ? trim((string)($accessory['manufacturer']['name'] ?? ''))
        : trim((string)($accessory['manufacturer_name'] ?? ''));
    $category = is_array($accessory['category'] ?? null)
        ? trim((string)($accessory['category']['name'] ?? ''))
        : trim((string)($accessory['category_name'] ?? ''));

    return [
        'key' => qc_checkout_session_key('accessory', $accessoryId),
        'entry_type' => 'accessory',
        'item_type' => 'accessory',
        'asset_id' => 0,
        'item_id' => $accessoryId,
        'asset_tag' => '',
        'name' => trim((string)($accessory['name'] ?? ('Accessory #' . $accessoryId))),
        'model_id' => 0,
        'model_name' => '',
        'manufacturer' => $manufacturer,
        'category' => $category,
        'status' => '',
        'qty' => max(1, $quantity),
        'image_path' => qc_extract_record_image_path($accessory),
        'source_label' => '',
    ];
}

function qc_selected_asset_id_map(array $checkoutItems): array
{
    $selected = [];

    foreach ($checkoutItems as $entry) {
        if (($entry['entry_type'] ?? '') !== 'asset') {
            continue;
        }

        $assetId = (int)($entry['asset_id'] ?? 0);
        if ($assetId > 0) {
            $selected[$assetId] = true;
        }
    }

    return $selected;
}

function qc_selected_accessory_quantities(array $checkoutItems): array
{
    $selected = [];

    foreach ($checkoutItems as $entry) {
        if (($entry['entry_type'] ?? '') !== 'accessory') {
            continue;
        }

        $accessoryId = (int)($entry['item_id'] ?? 0);
        $qty = max(0, (int)($entry['qty'] ?? 0));
        if ($accessoryId <= 0 || $qty <= 0) {
            continue;
        }

        $selected[$accessoryId] = ($selected[$accessoryId] ?? 0) + $qty;
    }

    return $selected;
}

function qc_checked_out_asset_id_map(PDO $pdo): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];

    try {
        $stmt = $pdo->query('SELECT asset_id FROM checked_out_asset_cache');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            $assetId = (int)($row['asset_id'] ?? 0);
            if ($assetId > 0) {
                $cache[$assetId] = true;
            }
        }
    } catch (Throwable $e) {
        $cache = [];
    }

    return $cache;
}

function qc_available_assets_for_model_now(PDO $pdo, int $modelId, array $excludedAssetIds = []): array
{
    static $baseCache = [];

    if ($modelId <= 0) {
        return [];
    }

    if (!isset($baseCache[$modelId])) {
        $allowedStatusMap = snipeit_catalogue_allowed_status_labels();
        $checkedOutMap = qc_checked_out_asset_id_map($pdo);
        $rows = list_assets_by_model($modelId, 500);
        $available = [];

        foreach ($rows as $asset) {
            $assetId = (int)($asset['id'] ?? 0);
            if ($assetId <= 0) {
                continue;
            }
            if (!snipeit_asset_allowed_for_catalogue_availability($asset, $allowedStatusMap)) {
                continue;
            }
            if (isset($checkedOutMap[$assetId])) {
                continue;
            }

            $available[] = $asset;
        }

        usort($available, static function (array $a, array $b): int {
            return strcasecmp((string)($a['asset_tag'] ?? ''), (string)($b['asset_tag'] ?? ''));
        });
        $baseCache[$modelId] = $available;
    }

    if (empty($excludedAssetIds)) {
        return $baseCache[$modelId];
    }

    return array_values(array_filter($baseCache[$modelId], static function (array $asset) use ($excludedAssetIds): bool {
        $assetId = (int)($asset['id'] ?? 0);
        return $assetId > 0 && !isset($excludedAssetIds[$assetId]);
    }));
}

function qc_resolve_assets_for_model_now(PDO $pdo, int $modelId, int $quantity, array $excludedAssetIds = []): array
{
    $quantity = max(0, $quantity);
    if ($modelId <= 0 || $quantity <= 0) {
        return [];
    }

    $available = qc_available_assets_for_model_now($pdo, $modelId, $excludedAssetIds);
    if (count($available) < $quantity) {
        throw new RuntimeException('Not enough available assets remain for model #' . $modelId . '.');
    }

    return array_slice($available, 0, $quantity);
}

function qc_aggregate_supported_items(array $supportedItems): array
{
    $totals = [];

    foreach ($supportedItems as $item) {
        $itemType = booking_normalize_item_type((string)($item['type'] ?? 'model'));
        $itemId = (int)($item['id'] ?? 0);
        $qty = max(1, (int)($item['qty'] ?? 1));

        if ($itemId <= 0) {
            continue;
        }

        $key = booking_catalogue_item_key($itemType, $itemId);
        if (!isset($totals[$key])) {
            $totals[$key] = [
                'type' => $itemType,
                'id' => $itemId,
                'name' => trim((string)($item['name'] ?? '')),
                'qty' => 0,
            ];
        }

        $totals[$key]['qty'] += $qty;
    }

    return array_values($totals);
}

function qc_format_kit_summary(array $breakdown): string
{
    $parts = [];
    $modelCount = count($breakdown['models'] ?? []);
    $accessoryCount = count($breakdown['accessories'] ?? []);

    if ($modelCount > 0) {
        $parts[] = $modelCount . ' model' . ($modelCount === 1 ? '' : 's');
    }
    if ($accessoryCount > 0) {
        $parts[] = $accessoryCount . ' accessor' . ($accessoryCount === 1 ? 'y' : 'ies');
    }

    return !empty($parts) ? implode(', ', $parts) : 'No supported items';
}

function qc_kit_image_path(array $kit, array $breakdown): string
{
    $directPath = qc_extract_record_image_path($kit);
    if ($directPath !== '') {
        return $directPath;
    }

    foreach (($breakdown['models'] ?? []) as $modelRow) {
        $modelId = (int)($modelRow['id'] ?? 0);
        $imagePath = qc_model_image_path($modelId);
        if ($imagePath !== '') {
            return $imagePath;
        }
    }

    foreach (($breakdown['accessories'] ?? []) as $accessoryRow) {
        $imagePath = qc_extract_record_image_path($accessoryRow);
        if ($imagePath !== '') {
            return $imagePath;
        }
    }

    return '';
}

function qc_kit_available_copy_count(PDO $pdo, array $breakdown, array $checkoutItems): int
{
    if (!empty($breakdown['unsupported_items'] ?? [])) {
        return 0;
    }

    $supportedItems = qc_aggregate_supported_items($breakdown['supported_items'] ?? []);
    if (empty($supportedItems)) {
        return 0;
    }

    $selectedAssetIds = qc_selected_asset_id_map($checkoutItems);
    $selectedAccessoryQty = qc_selected_accessory_quantities($checkoutItems);
    $maxKits = PHP_INT_MAX;

    foreach ($supportedItems as $item) {
        $itemType = booking_normalize_item_type((string)($item['type'] ?? 'model'));
        $itemId = (int)($item['id'] ?? 0);
        $perKitQty = max(1, (int)($item['qty'] ?? 1));
        if ($itemId <= 0) {
            continue;
        }

        if ($itemType === 'accessory') {
            $remainingUnits = max(0, count_available_accessory_units($itemId) - ($selectedAccessoryQty[$itemId] ?? 0));
        } else {
            $remainingUnits = count(qc_available_assets_for_model_now($pdo, $itemId, $selectedAssetIds));
        }

        $kitsForItem = intdiv($remainingUnits, $perKitQty);
        $maxKits = min($maxKits, $kitsForItem);
    }

    if ($maxKits === PHP_INT_MAX) {
        return 0;
    }

    return max(0, $maxKits);
}

function qc_expand_kit_entries(PDO $pdo, int $kitId, int $quantity, array $checkoutItems): array
{
    $quantity = max(1, $quantity);
    $breakdown = get_kit_booking_breakdown($kitId);
    $kitName = trim((string)($breakdown['kit']['name'] ?? ('Kit #' . $kitId)));

    if (empty($breakdown['supported_items'] ?? [])) {
        throw new RuntimeException('This kit does not contain any supported model or accessory items.');
    }

    $unsupportedLabels = [];
    foreach (($breakdown['unsupported_items'] ?? []) as $unsupported) {
        $typeLabel = trim((string)($unsupported['type'] ?? 'item'));
        $countLabel = (int)($unsupported['count'] ?? 0);
        $unsupportedLabels[] = $countLabel > 0 ? ($typeLabel . ' x' . $countLabel) : $typeLabel;
    }
    if (!empty($unsupportedLabels)) {
        throw new RuntimeException(
            'This kit includes unsupported contents for quick checkout: ' . implode(', ', $unsupportedLabels) . '.'
        );
    }

    $supportedItems = qc_aggregate_supported_items($breakdown['supported_items'] ?? []);
    $selectedAssetIds = qc_selected_asset_id_map($checkoutItems);
    $selectedAccessoryQty = qc_selected_accessory_quantities($checkoutItems);
    $assetEntries = [];
    $accessoryEntries = [];

    foreach ($supportedItems as $item) {
        $itemType = booking_normalize_item_type((string)($item['type'] ?? 'model'));
        $itemId = (int)($item['id'] ?? 0);
        $itemName = trim((string)($item['name'] ?? ''));
        $totalRequired = max(1, (int)($item['qty'] ?? 1)) * $quantity;
        if ($itemId <= 0 || $totalRequired <= 0) {
            continue;
        }

        if ($itemType === 'accessory') {
            $remainingUnits = max(0, count_available_accessory_units($itemId) - ($selectedAccessoryQty[$itemId] ?? 0));
            if ($remainingUnits < $totalRequired) {
                throw new RuntimeException(
                    'Only ' . $remainingUnits . ' accessory unit(s) remain for ' . ($itemName !== '' ? $itemName : ('Accessory #' . $itemId)) . '.'
                );
            }

            $record = get_accessory($itemId);
            $entry = qc_build_accessory_checkout_entry($record, $totalRequired);
            $accessoryEntries[$entry['key']] = $entry;
            $selectedAccessoryQty[$itemId] = ($selectedAccessoryQty[$itemId] ?? 0) + $totalRequired;
            continue;
        }

        $assets = qc_resolve_assets_for_model_now($pdo, $itemId, $totalRequired, $selectedAssetIds);
        foreach ($assets as $asset) {
            $entry = qc_build_asset_checkout_entry($asset, $kitName);
            $assetEntries[$entry['key']] = $entry;
            $selectedAssetIds[(int)$entry['asset_id']] = true;
        }
    }

    return [
        'kit_name' => $kitName,
        'breakdown' => $breakdown,
        'asset_entries' => $assetEntries,
        'accessory_entries' => $accessoryEntries,
    ];
}

function qc_accessory_browser_results(array $checkoutItems, string $search = '', int $limit = 12): array
{
    $rows = fetch_all_accessories_from_snipeit($search);
    $selectedAccessoryQty = qc_selected_accessory_quantities($checkoutItems);

    usort($rows, static function (array $a, array $b): int {
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $results = [];
    foreach ($rows as $row) {
        $accessoryId = (int)($row['id'] ?? 0);
        if ($accessoryId <= 0) {
            continue;
        }

        $remainingQty = max(0, snipeit_accessory_available_quantity_from_payload($row) - ($selectedAccessoryQty[$accessoryId] ?? 0));
        if ($remainingQty <= 0) {
            continue;
        }

        $manufacturer = is_array($row['manufacturer'] ?? null)
            ? trim((string)($row['manufacturer']['name'] ?? ''))
            : trim((string)($row['manufacturer_name'] ?? ''));
        $category = is_array($row['category'] ?? null)
            ? trim((string)($row['category']['name'] ?? ''))
            : trim((string)($row['category_name'] ?? ''));
        $subtitleParts = array_values(array_filter([$manufacturer, $category]));
        $results[] = [
            'id' => $accessoryId,
            'name' => trim((string)($row['name'] ?? ('Accessory #' . $accessoryId))),
            'subtitle' => !empty($subtitleParts) ? implode(' • ', $subtitleParts) : '',
            'available_qty' => $remainingQty,
            'image_url' => qc_image_proxy_url(qc_extract_record_image_path($row)),
        ];

        if (count($results) >= $limit) {
            break;
        }
    }

    return $results;
}

function qc_kit_browser_results(PDO $pdo, array $checkoutItems, string $search = '', int $limit = 12): array
{
    $rows = fetch_all_kits_from_snipeit($search);

    usort($rows, static function (array $a, array $b): int {
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    $results = [];
    foreach ($rows as $row) {
        $kitId = (int)($row['id'] ?? 0);
        if ($kitId <= 0) {
            continue;
        }

        try {
            $breakdown = get_kit_booking_breakdown($kitId);
            $availableQty = qc_kit_available_copy_count($pdo, $breakdown, $checkoutItems);
            if ($availableQty <= 0) {
                continue;
            }

            $results[] = [
                'id' => $kitId,
                'name' => trim((string)($row['name'] ?? ('Kit #' . $kitId))),
                'summary' => qc_format_kit_summary($breakdown),
                'available_qty' => $availableQty,
                'image_url' => qc_image_proxy_url(qc_kit_image_path($row, $breakdown)),
            ];
        } catch (Throwable $e) {
            continue;
        }

        if (count($results) >= $limit) {
            break;
        }
    }

    return $results;
}

/**
 * Return reservations (pending/confirmed) for a given item that overlap the checkout period.
 */
function qc_reservations_for_item_window(PDO $pdo, string $itemType, int $itemId, string $startIso, string $endIso): array
{
    $itemType = booking_normalize_item_type($itemType);
    if ($itemId <= 0 || $startIso === '' || $endIso === '') {
        return [];
    }

    $sql = "
        SELECT r.id,
               r.user_name,
               r.user_email,
               r.start_datetime,
               r.end_datetime,
               r.status,
               ri.quantity
          FROM reservation_items ri
          JOIN reservations r ON r.id = ri.reservation_id
         WHERE " . booking_reservation_item_match_sql($pdo, 'ri', ':item_type', ':item_id') . "
           AND r.status IN ('pending','confirmed')
           AND (r.start_datetime < :end AND r.end_datetime > :start)
         ORDER BY r.start_datetime ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':item_type' => $itemType,
        ':item_id' => $itemId,
        ':start'    => $startIso,
        ':end'      => $endIso,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function qc_checkout_entry_display_label(array $entry): string
{
    if (($entry['entry_type'] ?? '') === 'accessory') {
        $name = trim((string)($entry['name'] ?? 'Accessory'));
        $qty = max(1, (int)($entry['qty'] ?? 1));
        return $qty > 1 ? ($name . ' (x' . $qty . ')') : $name;
    }

    $assetTag = trim((string)($entry['asset_tag'] ?? ''));
    $modelName = trim((string)($entry['model_name'] ?? ''));
    if ($assetTag !== '' && $modelName !== '') {
        return $assetTag . ' (' . $modelName . ')';
    }
    if ($assetTag !== '') {
        return $assetTag;
    }

    return trim((string)($entry['name'] ?? 'Asset'));
}

function qc_history_items_from_checkout_items(array $checkoutItems): array
{
    $grouped = [];

    foreach ($checkoutItems as $entry) {
        $entryType = (string)($entry['entry_type'] ?? '');

        if ($entryType === 'accessory') {
            $itemType = 'accessory';
            $itemId = (int)($entry['item_id'] ?? 0);
            $itemName = trim((string)($entry['name'] ?? ('Accessory #' . $itemId)));
            $qty = max(1, (int)($entry['qty'] ?? 1));
            $modelId = 0;
            $modelName = '';
        } else {
            $itemType = 'model';
            $itemId = (int)($entry['model_id'] ?? 0);
            $itemName = trim((string)($entry['model_name'] ?? ('Model #' . $itemId)));
            $qty = 1;
            $modelId = $itemId;
            $modelName = $itemName;
        }

        if ($itemId <= 0 || $qty <= 0) {
            continue;
        }

        $key = booking_catalogue_item_key($itemType, $itemId);
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'item_type' => $itemType,
                'item_id' => $itemId,
                'item_name_cache' => $itemName,
                'model_id' => $modelId,
                'model_name_cache' => $modelName,
                'quantity' => 0,
            ];
        }

        $grouped[$key]['quantity'] += $qty;
    }

    return array_values($grouped);
}

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (($_GET['ajax'] ?? '') === 'asset_search') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        $rows = search_assets($q, 20, true);
        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'asset_tag' => $row['asset_tag'] ?? '',
                'name'      => $row['name'] ?? '',
                'model'     => $row['model']['name'] ?? '',
            ];
        }
        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Asset search failed.']);
    }
    exit;
}

if (!isset($_SESSION['quick_checkout_items']) || !is_array($_SESSION['quick_checkout_items'])) {
    $_SESSION['quick_checkout_items'] = [];
}

if (
    !empty($_SESSION['quick_checkout_assets'])
    && is_array($_SESSION['quick_checkout_assets'])
    && empty($_SESSION['quick_checkout_items'])
) {
    foreach ($_SESSION['quick_checkout_assets'] as $legacyAsset) {
        $assetId = (int)($legacyAsset['id'] ?? 0);
        if ($assetId <= 0) {
            continue;
        }

        try {
            $entry = qc_build_asset_checkout_entry([
                'id' => $assetId,
                'asset_tag' => $legacyAsset['asset_tag'] ?? '',
                'name' => $legacyAsset['name'] ?? '',
                'model' => [
                    'id' => (int)($legacyAsset['model_id'] ?? 0),
                    'name' => $legacyAsset['model'] ?? '',
                ],
                'status_label' => $legacyAsset['status'] ?? '',
            ]);
            $_SESSION['quick_checkout_items'][$entry['key']] = $entry;
        } catch (Throwable $e) {
            continue;
        }
    }
}
unset($_SESSION['quick_checkout_assets']);

$checkoutItems = &$_SESSION['quick_checkout_items'];

$messages = [];
$errors   = [];
$warnings = [];
$pendingUserCandidates = [];
$checkoutToValue = '';
$noteValue = '';
$selectedUserId = 0;
$overrideValue = false;
$accessoryBrowserResults = [];
$kitBrowserResults = [];
$reservationPolicy = reservation_policy_get(load_config());

if (isset($_GET['remove'])) {
    $removeKey = trim((string)$_GET['remove']);
    if ($removeKey !== '' && isset($checkoutItems[$removeKey])) {
        unset($checkoutItems[$removeKey]);
    }

    $redirectParams = [];
    if ($selectorTab !== 'assets') {
        $redirectParams['tab'] = $selectorTab;
    }
    if ($browseSearchValue !== '') {
        $redirectParams['browse_search'] = $browseSearchValue;
    }

    $redirectUrl = 'quick_checkout.php';
    if (!empty($redirectParams)) {
        $redirectUrl .= '?' . http_build_query($redirectParams);
    }

    header('Location: ' . $redirectUrl);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = trim((string)($_POST['mode'] ?? ''));

    if ($mode === 'add_asset') {
        $tag = trim((string)($_POST['asset_tag'] ?? ''));
        if ($tag === '') {
            $errors[] = 'Please scan or enter an asset tag.';
        } else {
            try {
                $asset = find_asset_by_tag($tag);
                if (empty($asset['requestable'])) {
                    throw new RuntimeException('This asset is not requestable in Snipe-IT.');
                }

                $entry = qc_build_asset_checkout_entry($asset);
                if (isset($checkoutItems[$entry['key']])) {
                    $messages[] = 'Asset ' . ($entry['asset_tag'] !== '' ? $entry['asset_tag'] : ('ID ' . $entry['asset_id'])) . ' is already in the checkout list.';
                } else {
                    $checkoutItems[$entry['key']] = $entry;
                    $messages[] = 'Added asset ' . qc_checkout_entry_display_label($entry) . ' to checkout list.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Could not add asset: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'add_accessory') {
        $accessoryId = (int)($_POST['accessory_id'] ?? 0);
        $qtyRequested = max(1, (int)($_POST['quantity'] ?? 1));

        if ($accessoryId <= 0) {
            $errors[] = 'Please choose an accessory to add.';
        } else {
            try {
                $selectedAccessoryQty = qc_selected_accessory_quantities($checkoutItems);
                $remainingQty = max(0, count_available_accessory_units($accessoryId) - ($selectedAccessoryQty[$accessoryId] ?? 0));
                if ($remainingQty < $qtyRequested) {
                    throw new RuntimeException('Only ' . $remainingQty . ' accessory unit(s) remain available right now.');
                }

                $entry = qc_build_accessory_checkout_entry(get_accessory($accessoryId), $qtyRequested);
                if (isset($checkoutItems[$entry['key']])) {
                    $checkoutItems[$entry['key']]['qty'] += $qtyRequested;
                } else {
                    $checkoutItems[$entry['key']] = $entry;
                }

                $messages[] = 'Added ' . ($qtyRequested > 1 ? ($entry['name'] . ' (x' . $qtyRequested . ')') : $entry['name']) . ' to checkout list.';
            } catch (Throwable $e) {
                $errors[] = 'Could not add accessory: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'add_kit') {
        $kitId = (int)($_POST['kit_id'] ?? 0);
        $qtyRequested = max(1, (int)($_POST['quantity'] ?? 1));

        if ($kitId <= 0) {
            $errors[] = 'Please choose a kit to add.';
        } else {
            try {
                $expanded = qc_expand_kit_entries($pdo, $kitId, $qtyRequested, $checkoutItems);
                foreach ($expanded['asset_entries'] as $key => $entry) {
                    $checkoutItems[$key] = $entry;
                }
                foreach ($expanded['accessory_entries'] as $key => $entry) {
                    if (isset($checkoutItems[$key])) {
                        $checkoutItems[$key]['qty'] += (int)($entry['qty'] ?? 0);
                    } else {
                        $checkoutItems[$key] = $entry;
                    }
                }

                $addedAssetCount = count($expanded['asset_entries']);
                $addedAccessoryUnits = 0;
                foreach ($expanded['accessory_entries'] as $entry) {
                    $addedAccessoryUnits += max(0, (int)($entry['qty'] ?? 0));
                }

                $detailParts = [];
                if ($addedAssetCount > 0) {
                    $detailParts[] = $addedAssetCount . ' asset' . ($addedAssetCount === 1 ? '' : 's');
                }
                if ($addedAccessoryUnits > 0) {
                    $detailParts[] = $addedAccessoryUnits . ' accessory unit' . ($addedAccessoryUnits === 1 ? '' : 's');
                }

                $kitLabel = $qtyRequested === 1
                    ? ('Added kit ' . $expanded['kit_name'] . ' to checkout list')
                    : ('Added ' . $qtyRequested . ' kits of ' . $expanded['kit_name'] . ' to checkout list');
                if (!empty($detailParts)) {
                    $kitLabel .= ' (' . implode(', ', $detailParts) . ').';
                } else {
                    $kitLabel .= '.';
                }
                $messages[] = $kitLabel;
            } catch (Throwable $e) {
                $errors[] = 'Could not add kit: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'checkout') {
        $checkoutTo      = trim((string)($_POST['checkout_to'] ?? ''));
        $note            = trim((string)($_POST['note'] ?? ''));
        $overrideAllowed = isset($_POST['override_conflicts']) && $_POST['override_conflicts'] === '1';
        $selectedUserId  = (int)($_POST['checkout_user_id'] ?? 0);
        $checkoutToValue = $checkoutTo;
        $noteValue       = $note;
        $overrideValue   = $overrideAllowed;
        $endRaw          = trim((string)($_POST['end_datetime'] ?? $endRaw));

        $startTs = time();
        $endTs   = strtotime($endRaw);
        $checkoutEntries = array_values($checkoutItems);
        $assetEntries = array_values(array_filter($checkoutEntries, static function (array $entry): bool {
            return ($entry['entry_type'] ?? '') === 'asset';
        }));
        $accessoryEntries = array_values(array_filter($checkoutEntries, static function (array $entry): bool {
            return ($entry['entry_type'] ?? '') === 'accessory';
        }));
        $hasNonModelItems = !empty($accessoryEntries);

        if ($checkoutTo === '') {
            $errors[] = 'Please enter the Snipe-IT user (email or name) to check out to.';
        } elseif (empty($checkoutEntries)) {
            $errors[] = 'There are no items in the checkout list.';
        } elseif ($hasNonModelItems && !booking_reservation_items_have_typed_columns($pdo)) {
            $errors[] = 'This installation must run the latest database upgrade before accessories can be quick checked out.';
        } elseif ($endTs === false) {
            $errors[] = 'Invalid return date/time.';
        } elseif ($endTs <= $startTs) {
            $errors[] = 'Return date/time must be after the current time.';
        } else {
            try {
                $user = null;
                $result = find_user_by_email_or_name_with_candidates($checkoutTo);
                if (!empty($result['user'])) {
                    $user = $result['user'];
                } else {
                    $pendingUserCandidates = $result['candidates'];
                    if ($selectedUserId > 0) {
                        foreach ($pendingUserCandidates as $candidate) {
                            if ((int)($candidate['id'] ?? 0) === $selectedUserId) {
                                $user = $candidate;
                                break;
                            }
                        }
                        if (!$user) {
                            $errors[] = 'Selected user is not available for this query. Please choose again.';
                        }
                    } else {
                        $warnings[] = "Multiple Snipe-IT users matched '{$checkoutTo}'. Please choose which account to use.";
                    }
                }

                if ($user) {
                    $userId   = (int)($user['id'] ?? 0);
                    $userName = $user['name'] ?? ($user['username'] ?? $checkoutTo);
                    if ($userId <= 0) {
                        throw new RuntimeException('Matched user has no valid ID.');
                    }

                    $policyViolations = reservation_policy_validate_booking($pdo, $reservationPolicy, [
                        'start_ts' => $startTs,
                        'end_ts' => $endTs,
                        'target_user_id' => (string)$userId,
                        'target_user_email' => (string)($user['email'] ?? ''),
                        'is_admin' => $isAdmin,
                        'is_staff' => $isStaff,
                        'is_on_behalf' => true,
                        'is_quick_checkout' => true,
                    ]);
                    if (!empty($policyViolations)) {
                        foreach ($policyViolations as $violation) {
                            $errors[] = $violation;
                        }
                    }

                    if (empty($policyViolations)) {
                        $windowStartIso = date('Y-m-d H:i:s', $startTs);
                        $windowEndIso   = date('Y-m-d H:i:s', $endTs);
                        $reservationConflicts = [];

                        $checkoutModelCounts = [];
                        foreach ($assetEntries as $entry) {
                            $modelId = (int)($entry['model_id'] ?? 0);
                            if ($modelId > 0) {
                                $checkoutModelCounts[$modelId] = ($checkoutModelCounts[$modelId] ?? 0) + 1;
                            }
                        }

                        foreach ($checkoutModelCounts as $modelId => $checkoutQty) {
                            $conflicts = qc_reservations_for_item_window($pdo, 'model', $modelId, $windowStartIso, $windowEndIso);
                            if (empty($conflicts)) {
                                continue;
                            }

                            $reservedQty = 0;
                            foreach ($conflicts as $row) {
                                $reservedQty += (int)($row['quantity'] ?? 0);
                            }

                            $availabilityUnknown = false;
                            try {
                                $available = count(qc_available_assets_for_model_now($pdo, $modelId));
                            } catch (Throwable $e) {
                                $availabilityUnknown = true;
                                $available = 0;
                            }

                            if (!$availabilityUnknown && ($reservedQty + $checkoutQty) <= $available) {
                                continue;
                            }

                            foreach ($assetEntries as $entry) {
                                if ((int)($entry['model_id'] ?? 0) === $modelId) {
                                    $reservationConflicts[(string)($entry['key'] ?? '')] = $conflicts;
                                }
                            }
                        }

                        $checkoutAccessoryCounts = [];
                        foreach ($accessoryEntries as $entry) {
                            $accessoryId = (int)($entry['item_id'] ?? 0);
                            $qty = max(1, (int)($entry['qty'] ?? 1));
                            if ($accessoryId > 0) {
                                $checkoutAccessoryCounts[$accessoryId] = ($checkoutAccessoryCounts[$accessoryId] ?? 0) + $qty;
                            }
                        }

                        foreach ($checkoutAccessoryCounts as $accessoryId => $checkoutQty) {
                            $conflicts = qc_reservations_for_item_window($pdo, 'accessory', $accessoryId, $windowStartIso, $windowEndIso);
                            if (empty($conflicts)) {
                                continue;
                            }

                            $reservedQty = 0;
                            foreach ($conflicts as $row) {
                                $reservedQty += (int)($row['quantity'] ?? 0);
                            }

                            $availabilityUnknown = false;
                            try {
                                $available = count_available_accessory_units($accessoryId);
                            } catch (Throwable $e) {
                                $availabilityUnknown = true;
                                $available = 0;
                            }

                            if (!$availabilityUnknown && ($reservedQty + $checkoutQty) <= $available) {
                                continue;
                            }

                            foreach ($accessoryEntries as $entry) {
                                if ((int)($entry['item_id'] ?? 0) === $accessoryId) {
                                    $reservationConflicts[(string)($entry['key'] ?? '')] = $conflicts;
                                }
                            }
                        }

                        if (!empty($reservationConflicts) && !$overrideAllowed) {
                            $errors[] = 'Some items are reserved during this checkout period. Review who reserved them below or tick "Override" to proceed anyway.';
                        } else {
                            $expectedCheckinIso = date('Y-m-d H:i:s', $endTs);
                            $checkedOutLabels = [];

                            foreach ($assetEntries as $entry) {
                                $assetId = (int)($entry['asset_id'] ?? 0);
                                $assetTag = trim((string)($entry['asset_tag'] ?? ''));
                                if ($assetId <= 0) {
                                    continue;
                                }

                                try {
                                    checkout_asset_to_user($assetId, $userId, $note, $expectedCheckinIso);
                                    $label = qc_checkout_entry_display_label($entry);
                                    $messages[] = 'Checked out asset ' . ($assetTag !== '' ? $assetTag : $label) . ' to ' . $userName . '.' . (!empty($reservationConflicts[$entry['key'] ?? '']) ? ' (Override used)' : '');
                                    $checkedOutLabels[] = $label;
                                } catch (Throwable $e) {
                                    $errors[] = 'Failed to check out ' . ($assetTag !== '' ? $assetTag : ('asset #' . $assetId)) . ': ' . $e->getMessage();
                                }
                            }

                            foreach ($accessoryEntries as $entry) {
                                $accessoryId = (int)($entry['item_id'] ?? 0);
                                $qty = max(1, (int)($entry['qty'] ?? 1));
                                $name = trim((string)($entry['name'] ?? ('Accessory #' . $accessoryId)));
                                if ($accessoryId <= 0) {
                                    continue;
                                }

                                try {
                                    checkout_accessory_to_user($accessoryId, $userId, $qty, $note);
                                    $label = qc_checkout_entry_display_label($entry);
                                    $messages[] = 'Checked out ' . $label . ' to ' . $userName . '.' . (!empty($reservationConflicts[$entry['key'] ?? '']) ? ' (Override used)' : '');
                                    $checkedOutLabels[] = $label;
                                } catch (Throwable $e) {
                                    $errors[] = 'Failed to check out ' . ($name !== '' ? $name : ('accessory #' . $accessoryId)) . ': ' . $e->getMessage();
                                }
                            }
                        }
                    }

                    if (empty($errors)) {
                        $reservationStart = date('Y-m-d H:i:s', $startTs);
                        $reservationEnd   = date('Y-m-d H:i:s', $endTs);
                        $historyItems     = qc_history_items_from_checkout_items($checkoutItems);
                        $itemsText        = implode(', ', $checkedOutLabels);
                        $reservationId    = 0;

                        try {
                            $pdo->beginTransaction();

                            $insertRes = $pdo->prepare("
                                INSERT INTO reservations (
                                    user_name, user_email, user_id, snipeit_user_id,
                                    asset_id, asset_name_cache,
                                    start_datetime, end_datetime, status
                                ) VALUES (
                                    :user_name, :user_email, :user_id, :snipeit_user_id,
                                    0, :asset_name_cache,
                                    :start_datetime, :end_datetime, 'completed'
                                )
                            ");
                            $insertRes->execute([
                                ':user_name'        => $userName,
                                ':user_email'       => $user['email'] ?? '',
                                ':user_id'          => (string)$userId,
                                ':snipeit_user_id'  => $userId,
                                ':asset_name_cache' => $itemsText,
                                ':start_datetime'   => $reservationStart,
                                ':end_datetime'     => $reservationEnd,
                            ]);

                            $reservationId = (int)$pdo->lastInsertId();
                            if ($reservationId > 0 && !empty($historyItems)) {
                                if (booking_reservation_items_have_typed_columns($pdo)) {
                                    $insertItem = $pdo->prepare("
                                        INSERT INTO reservation_items (
                                            reservation_id, item_type, item_id, item_name_cache,
                                            model_id, model_name_cache, quantity
                                        ) VALUES (
                                            :reservation_id, :item_type, :item_id, :item_name_cache,
                                            :model_id, :model_name_cache, :quantity
                                        )
                                    ");

                                    foreach ($historyItems as $historyItem) {
                                        $insertItem->execute([
                                            ':reservation_id' => $reservationId,
                                            ':item_type' => $historyItem['item_type'],
                                            ':item_id' => $historyItem['item_id'],
                                            ':item_name_cache' => $historyItem['item_name_cache'],
                                            ':model_id' => $historyItem['model_id'],
                                            ':model_name_cache' => $historyItem['model_name_cache'],
                                            ':quantity' => $historyItem['quantity'],
                                        ]);
                                    }
                                } else {
                                    $insertItem = $pdo->prepare("
                                        INSERT INTO reservation_items (
                                            reservation_id, model_id, model_name_cache, quantity
                                        ) VALUES (
                                            :reservation_id, :model_id, :model_name_cache, :quantity
                                        )
                                    ");

                                    foreach ($historyItems as $historyItem) {
                                        if (($historyItem['item_type'] ?? 'model') !== 'model') {
                                            continue;
                                        }

                                        $insertItem->execute([
                                            ':reservation_id' => $reservationId,
                                            ':model_id' => $historyItem['model_id'],
                                            ':model_name_cache' => $historyItem['model_name_cache'],
                                            ':quantity' => $historyItem['quantity'],
                                        ]);
                                    }
                                }
                            }

                            $pdo->commit();
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            $errors[] = 'Quick checkout completed, but could not record reservation history: ' . $e->getMessage();
                        }

                        activity_log_event('quick_checkout', 'Quick checkout completed', [
                            'subject_type' => 'reservation',
                            'subject_id'   => $reservationId > 0 ? $reservationId : null,
                            'metadata'     => [
                                'checked_out_to' => $userName,
                                'items'          => $checkedOutLabels,
                                'start'          => $reservationStart,
                                'end'            => $reservationEnd,
                                'note'           => $note,
                            ],
                        ]);

                        $userEmail = $user['email'] ?? '';
                        $staffEmail = $currentUser['email'] ?? '';
                        $staffName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
                        $staffDisplayName = $staffName !== '' ? $staffName : ($currentUser['email'] ?? 'Staff');
                        $dueDisplay = app_format_datetime($endTs);
                        $bodyLines = [
                            'Items checked out:',
                            $itemsText,
                            'Return by: ' . $dueDisplay,
                            $note !== '' ? ('Note: ' . $note) : '',
                        ];

                        $notificationConfig = load_config();
                        $userBodyLines = $bodyLines;
                        $staffBodyLines = $bodyLines;
                        $userPortalLinkLine = layout_my_reservations_link_line($notificationConfig);
                        if ($userPortalLinkLine !== null) {
                            $userBodyLines[] = $userPortalLinkLine;
                        }
                        $staffPortalLinkLine = layout_staff_reservations_link_line($notificationConfig);
                        if ($staffPortalLinkLine !== null) {
                            $staffBodyLines[] = $staffPortalLinkLine;
                        }

                        $appCfg = $notificationConfig['app'] ?? [];
                        $notifyEnabled = array_key_exists('notification_quick_checkout_enabled', $appCfg)
                            ? !empty($appCfg['notification_quick_checkout_enabled'])
                            : true;
                        $sendUserDefault = array_key_exists('notification_quick_checkout_send_user', $appCfg)
                            ? !empty($appCfg['notification_quick_checkout_send_user'])
                            : true;
                        $sendStaffDefault = array_key_exists('notification_quick_checkout_send_staff', $appCfg)
                            ? !empty($appCfg['notification_quick_checkout_send_staff'])
                            : true;

                        if ($notifyEnabled) {
                            $defaultEmails = [];

                            if ($sendUserDefault && $userEmail !== '') {
                                layout_send_notification($userEmail, $userName, 'Items checked out', $userBodyLines, $notificationConfig);
                                $defaultEmails[] = $userEmail;
                            }

                            if ($sendStaffDefault && $staffEmail !== '') {
                                $staffBody = array_merge(
                                    [
                                        'You checked out items for ' . $userName,
                                    ],
                                    $staffBodyLines
                                );
                                layout_send_notification($staffEmail, $staffDisplayName, 'You checked out items', $staffBody, $notificationConfig);
                                $defaultEmails[] = $staffEmail;
                            }

                            $extraRecipients = layout_extra_notification_recipients(
                                (string)($appCfg['notification_quick_checkout_extra_emails'] ?? ''),
                                $defaultEmails
                            );
                            foreach ($extraRecipients as $recipient) {
                                layout_send_notification(
                                    $recipient['email'],
                                    $recipient['name'],
                                    'Items checked out',
                                    $staffBodyLines,
                                    $notificationConfig
                                );
                            }
                        }

                        $checkoutItems = [];
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'Could not find user in Snipe-IT: ' . $e->getMessage();
            }
        }
    }
}

if ($selectorTab === 'accessories') {
    try {
        $accessoryBrowserResults = qc_accessory_browser_results($checkoutItems, $browseSearchValue, 12);
    } catch (Throwable $e) {
        $errors[] = 'Could not load accessory list: ' . $e->getMessage();
    }
} elseif ($selectorTab === 'kits') {
    try {
        $kitBrowserResults = qc_kit_browser_results($pdo, $checkoutItems, $browseSearchValue, 12);
    } catch (Throwable $e) {
        $errors[] = 'Could not load kit list: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quick Checkout – <?= h(layout_app_name()) ?></title>
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
            <h1>Quick Checkout</h1>
            <div class="page-subtitle">
                Ad-hoc bulk checkout via Snipe-IT (not tied to a reservation).
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <?php if (!empty($messages)): ?>
            <div class="alert alert-success">
                <ul class="mb-0">
                    <?php foreach ($messages as $m): ?>
                        <li><?= h($m) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($warnings)): ?>
            <div class="alert alert-warning">
                <ul class="mb-0">
                    <?php foreach ($warnings as $w): ?>
                        <li><?= h($w) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?= h($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <h5 class="card-title">Bulk checkout (via Snipe-IT)</h5>
                <p class="card-text">
                    Use the tabs below to scan assets, add available accessories, or expand available kits
                    into concrete checkout items. When ready, enter the Snipe-IT user (email or name) and
                    check out everything in one go.
                </p>
                <?php
                    $assetTabUrl = 'quick_checkout.php';
                    $accessoryTabParams = ['tab' => 'accessories'];
                    if ($selectorTab === 'accessories' && $browseSearchValue !== '') {
                        $accessoryTabParams['browse_search'] = $browseSearchValue;
                    }
                    $accessoryTabUrl = 'quick_checkout.php?' . http_build_query($accessoryTabParams);
                    $kitTabParams = ['tab' => 'kits'];
                    if ($selectorTab === 'kits' && $browseSearchValue !== '') {
                        $kitTabParams['browse_search'] = $browseSearchValue;
                    }
                    $kitTabUrl = 'quick_checkout.php?' . http_build_query($kitTabParams);
                    $checkoutEntryCount = count($checkoutItems);
                    $checkoutUnitCount = 0;
                    foreach ($checkoutItems as $entry) {
                        $checkoutUnitCount += max(1, (int)($entry['qty'] ?? 1));
                    }
                ?>

                <ul class="nav reservations-subtabs quick-checkout-tabs mb-3">
                    <li class="nav-item">
                        <a class="nav-link <?= $selectorTab === 'assets' ? 'active' : '' ?>" href="<?= h($assetTabUrl) ?>">Assets</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $selectorTab === 'accessories' ? 'active' : '' ?>" href="<?= h($accessoryTabUrl) ?>">Accessories</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $selectorTab === 'kits' ? 'active' : '' ?>" href="<?= h($kitTabUrl) ?>">Kits</a>
                    </li>
                </ul>

                <div class="quick-checkout-panel quick-checkout-panel--picker filter-panel filter-panel--compact">
                    <div class="filter-panel__header d-flex align-items-center gap-3">
                        <span class="filter-panel__dot"></span>
                        <div>
                            <div class="filter-panel__title">QUICK CHECKOUT</div>
                            <div class="quick-checkout-panel__intro">Switch tabs to browse different Snipe-IT item types.</div>
                        </div>
                    </div>

                    <div class="quick-checkout-picker-surface">
                        <?php if ($selectorTab === 'assets'): ?>
                            <form method="post" class="row g-2 mb-0">
                                <input type="hidden" name="mode" value="add_asset">
                                <input type="hidden" name="active_tab" value="assets">
                                <div class="col-md-6">
                                    <label class="form-label">Asset tag</label>
                                    <div class="position-relative asset-autocomplete-wrapper">
                                        <div class="input-group filter-search">
                                            <span class="input-group-text filter-search__icon" aria-hidden="true">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/>
                                                    <line x1="15.5" y1="15.5" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                                </svg>
                                            </span>
                                            <input type="text"
                                                   name="asset_tag"
                                                   class="form-control form-control-lg filter-search__input asset-autocomplete"
                                                   autocomplete="off"
                                                   placeholder="Scan or type asset tag..."
                                                   autofocus>
                                        </div>
                                        <div class="list-group position-absolute w-100"
                                             data-asset-suggestions
                                             style="z-index: 1050; max-height: 220px; overflow-y: auto; display: none;"></div>
                                    </div>
                                </div>
                                <div class="col-md-3 quick-checkout-asset-submit">
                                    <button type="submit" class="btn btn-primary w-100 quick-checkout-asset-submit__button quick-checkout-submit-button">
                                        Add to checkout list
                                    </button>
                                </div>
                            </form>
                        <?php elseif ($selectorTab === 'accessories'): ?>
                            <div class="quick-checkout-browser">
                                <form method="get" class="row g-2 align-items-end mb-3">
                                    <input type="hidden" name="tab" value="accessories">
                                    <div class="col-md-6">
                                        <label class="form-label">Search accessories</label>
                                        <div class="input-group filter-search">
                                            <span class="input-group-text filter-search__icon" aria-hidden="true">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/>
                                                    <line x1="15.5" y1="15.5" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                                </svg>
                                            </span>
                                            <input type="search"
                                                   name="browse_search"
                                                   class="form-control form-control-lg filter-search__input"
                                                   value="<?= h($browseSearchValue) ?>"
                                                   placeholder="Search by accessory name or manufacturer"
                                                   autofocus>
                                        </div>
                                    </div>
                                    <div class="col-md-2 d-grid">
                                        <button type="submit" class="btn btn-primary">Search</button>
                                    </div>
                                    <div class="col-md-2 d-grid">
                                        <a href="quick_checkout.php?tab=accessories" class="btn btn-outline-secondary">Reset</a>
                                    </div>
                                </form>

                                <?php if (empty($accessoryBrowserResults)): ?>
                                    <div class="alert alert-secondary">
                                        No available accessories found<?= $browseSearchValue !== '' ? ' for that search' : '' ?>.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive mb-0">
                                        <table class="table table-sm align-middle mb-0 quick-checkout-browser-table">
                                            <thead>
                                                <tr>
                                                    <th>Accessory</th>
                                                    <th class="text-nowrap">Available now</th>
                                                    <th style="width: 220px;">Add</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($accessoryBrowserResults as $row): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="report-model-cell">
                                                                <?php if ($row['image_url'] !== ''): ?>
                                                                    <img src="<?= h($row['image_url']) ?>"
                                                                         alt=""
                                                                         class="report-model-thumb"
                                                                         loading="lazy">
                                                                <?php else: ?>
                                                                    <div class="report-model-thumb report-model-thumb--placeholder" aria-hidden="true">A</div>
                                                                <?php endif; ?>
                                                                <div class="report-model-cell__text">
                                                                    <div class="fw-semibold"><?= h($row['name']) ?></div>
                                                                    <?php if ($row['subtitle'] !== ''): ?>
                                                                        <div class="text-muted small"><?= h($row['subtitle']) ?></div>
                                                                    <?php endif; ?>
                                                                    <span class="text-muted small">ID <?= (int)$row['id'] ?></span>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="text-nowrap"><?= (int)$row['available_qty'] ?></td>
                                                        <td>
                                                            <form method="post" class="d-flex gap-2 align-items-center quick-checkout-inline-form">
                                                                <input type="hidden" name="mode" value="add_accessory">
                                                                <input type="hidden" name="active_tab" value="accessories">
                                                                <input type="hidden" name="browse_search" value="<?= h($browseSearchValue) ?>">
                                                                <input type="hidden" name="accessory_id" value="<?= (int)$row['id'] ?>">
                                                                <input type="number"
                                                                       name="quantity"
                                                                       class="form-control form-control-sm"
                                                                       value="1"
                                                                       min="1"
                                                                       max="<?= (int)$row['available_qty'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Add</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="quick-checkout-browser">
                                <form method="get" class="row g-2 align-items-end mb-3">
                                    <input type="hidden" name="tab" value="kits">
                                    <div class="col-md-6">
                                        <label class="form-label">Search kits</label>
                                        <div class="input-group filter-search">
                                            <span class="input-group-text filter-search__icon" aria-hidden="true">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                    <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/>
                                                    <line x1="15.5" y1="15.5" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                                </svg>
                                            </span>
                                            <input type="search"
                                                   name="browse_search"
                                                   class="form-control form-control-lg filter-search__input"
                                                   value="<?= h($browseSearchValue) ?>"
                                                   placeholder="Search by kit name"
                                                   autofocus>
                                        </div>
                                    </div>
                                    <div class="col-md-2 d-grid">
                                        <button type="submit" class="btn btn-primary">Search</button>
                                    </div>
                                    <div class="col-md-2 d-grid">
                                        <a href="quick_checkout.php?tab=kits" class="btn btn-outline-secondary">Reset</a>
                                    </div>
                                </form>

                                <?php if (empty($kitBrowserResults)): ?>
                                    <div class="alert alert-secondary">
                                        No complete kits are available<?= $browseSearchValue !== '' ? ' for that search' : '' ?>.
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive mb-0">
                                        <table class="table table-sm align-middle mb-0 quick-checkout-browser-table">
                                            <thead>
                                                <tr>
                                                    <th>Kit</th>
                                                    <th class="text-nowrap">Complete kits now</th>
                                                    <th style="width: 220px;">Add</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($kitBrowserResults as $row): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="report-model-cell">
                                                                <?php if ($row['image_url'] !== ''): ?>
                                                                    <img src="<?= h($row['image_url']) ?>"
                                                                         alt=""
                                                                         class="report-model-thumb"
                                                                         loading="lazy">
                                                                <?php else: ?>
                                                                    <div class="report-model-thumb report-model-thumb--placeholder" aria-hidden="true">K</div>
                                                                <?php endif; ?>
                                                                <div class="report-model-cell__text">
                                                                    <div class="fw-semibold"><?= h($row['name']) ?></div>
                                                                    <div class="text-muted small"><?= h($row['summary']) ?></div>
                                                                    <span class="text-muted small">ID <?= (int)$row['id'] ?></span>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="text-nowrap"><?= (int)$row['available_qty'] ?></td>
                                                        <td>
                                                            <form method="post" class="d-flex gap-2 align-items-center quick-checkout-inline-form">
                                                                <input type="hidden" name="mode" value="add_kit">
                                                                <input type="hidden" name="active_tab" value="kits">
                                                                <input type="hidden" name="browse_search" value="<?= h($browseSearchValue) ?>">
                                                                <input type="hidden" name="kit_id" value="<?= (int)$row['id'] ?>">
                                                                <input type="number"
                                                                       name="quantity"
                                                                       class="form-control form-control-sm"
                                                                       value="1"
                                                                       min="1"
                                                                       max="<?= (int)$row['available_qty'] ?>">
                                                                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Add</button>
                                                            </form>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="quick-checkout-panel quick-checkout-panel--shared filter-panel filter-panel--compact mt-4">
                    <div class="quick-checkout-panel__header quick-checkout-panel__header--basket d-flex align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <span class="filter-panel__dot"></span>
                            <div class="filter-panel__title">BASKET</div>
                        </div>
                        <div class="quick-checkout-panel__meta">
                            <span class="quick-checkout-panel__count"><?= (int)$checkoutEntryCount ?> item<?= $checkoutEntryCount === 1 ? '' : 's' ?>, <?= (int)$checkoutUnitCount ?> unit<?= $checkoutUnitCount === 1 ? '' : 's' ?></span>
                        </div>
                    </div>
                    <div class="quick-checkout-panel__subtitle">Items stay here while you switch between Assets, Accessories, and Kits.</div>

                    <div class="quick-checkout-basket-surface">
                    <?php if (empty($checkoutItems)): ?>
                        <div class="alert alert-secondary mb-0">
                            No items in the checkout list yet. Add assets, accessories, or kits above.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead>
                                            <tr>
                                                <th>Type</th>
                                                <th>Item</th>
                                                <th>Qty</th>
                                                <th>Details</th>
                                                <th style="width: 90px;"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($checkoutItems as $entry): ?>
                                                <?php
                                                    $isAccessoryEntry = ($entry['entry_type'] ?? '') === 'accessory';
                                                    $itemImageUrl = qc_image_proxy_url((string)($entry['image_path'] ?? ''));
                                                    $detailParts = [];
                                                    if ($isAccessoryEntry) {
                                                        if (!empty($entry['manufacturer'])) {
                                                            $detailParts[] = (string)$entry['manufacturer'];
                                                        }
                                                        if (!empty($entry['category'])) {
                                                            $detailParts[] = (string)$entry['category'];
                                                        }
                                                    } else {
                                                        if (!empty($entry['status'])) {
                                                            $detailParts[] = (string)$entry['status'];
                                                        }
                                                        if (!empty($entry['source_label'])) {
                                                            $detailParts[] = 'Added via kit: ' . (string)$entry['source_label'];
                                                        }
                                                    }
                                                    $removeParams = ['remove' => (string)($entry['key'] ?? '')];
                                                    if ($selectorTab !== 'assets') {
                                                        $removeParams['tab'] = $selectorTab;
                                                    }
                                                    if ($browseSearchValue !== '') {
                                                        $removeParams['browse_search'] = $browseSearchValue;
                                                    }
                                                ?>
                                                <tr>
                                                    <td class="text-nowrap"><?= $isAccessoryEntry ? 'Accessory' : 'Asset' ?></td>
                                                    <td>
                                                        <div class="report-model-cell">
                                                            <?php if ($itemImageUrl !== ''): ?>
                                                                <img src="<?= h($itemImageUrl) ?>"
                                                                     alt=""
                                                                     class="report-model-thumb"
                                                                     loading="lazy">
                                                            <?php else: ?>
                                                                <div class="report-model-thumb report-model-thumb--placeholder" aria-hidden="true"><?= $isAccessoryEntry ? 'A' : 'M' ?></div>
                                                            <?php endif; ?>
                                                            <div class="report-model-cell__text">
                                                                <?php if ($isAccessoryEntry): ?>
                                                                    <div class="fw-semibold"><?= h((string)($entry['name'] ?? 'Accessory')) ?></div>
                                                                    <span class="text-muted small">ID <?= (int)($entry['item_id'] ?? 0) ?></span>
                                                                <?php else: ?>
                                                                    <div class="fw-semibold"><?= h((string)($entry['asset_tag'] ?? ('Asset #' . (int)($entry['asset_id'] ?? 0)))) ?></div>
                                                                    <div class="text-muted small"><?= h((string)($entry['model_name'] ?? '')) ?></div>
                                                                    <span class="text-muted small">Asset ID <?= (int)($entry['asset_id'] ?? 0) ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?= (int)($entry['qty'] ?? 1) ?></td>
                                                    <td class="small text-muted">
                                                        <?= h(!empty($detailParts) ? implode(' • ', $detailParts) : ($isAccessoryEntry ? 'Accessory checkout in Snipe-IT' : 'Asset checkout in Snipe-IT')) ?>
                                                    </td>
                                                    <td>
                                                        <a href="quick_checkout.php?<?= h(http_build_query($removeParams)) ?>"
                                                           class="btn btn-sm btn-outline-danger">
                                                            Remove
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                        <?php if (!empty($reservationConflicts)): ?>
                            <div class="alert alert-warning">
                                <div class="fw-semibold mb-1">Some listed items are reserved before the selected return time.</div>
                                <div class="small mb-2">Review who has them reserved during this checkout period before overriding.</div>
                                <ul class="mb-0">
                                    <?php foreach ($checkoutItems as $entry): ?>
                                        <?php $entryKey = (string)($entry['key'] ?? ''); ?>
                                        <?php if ($entryKey === '' || empty($reservationConflicts[$entryKey])) continue; ?>
                                        <li class="mb-1">
                                            <strong><?= h(qc_checkout_entry_display_label($entry)) ?></strong>
                                            <div class="small text-muted">
                                                <?php foreach ($reservationConflicts[$entryKey] as $conf): ?>
                                                    Reserved by <?= h($conf['user_name'] ?? 'Unknown') ?>
                                                    (<?= h($conf['user_email'] ?? '') ?>)
                                                    from <?= h(qc_display_datetime($conf['start_datetime'] ?? '')) ?>
                                                    to <?= h(qc_display_datetime($conf['end_datetime'] ?? '')) ?>.
                                                    Quantity: <?= (int)($conf['quantity'] ?? 0) ?>.
                                                    <br>
                                                <?php endforeach; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="post" class="border-top pt-3">
                            <input type="hidden" name="mode" value="checkout">
                            <input type="hidden" name="active_tab" value="<?= h($selectorTab) ?>">
                            <input type="hidden" name="browse_search" value="<?= h($browseSearchValue) ?>">

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">
                                        Check out to (Snipe-IT user email or name)
                                    </label>
                                    <div class="position-relative user-autocomplete-wrapper">
                                        <input type="text"
                                               name="checkout_to"
                                               class="form-control user-autocomplete"
                                               autocomplete="off"
                                               placeholder="Start typing email or name"
                                               value="<?= h($checkoutToValue) ?>">
                                        <div class="list-group position-absolute w-100"
                                             data-suggestions
                                             style="z-index: 1050; max-height: 220px; overflow-y: auto; display: none;"></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Note (optional)</label>
                                    <input type="text"
                                           name="note"
                                           class="form-control"
                                           placeholder="Optional note to store with checkout"
                                           value="<?= h($noteValue) ?>">
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <?php if (!empty($pendingUserCandidates)): ?>
                                    <div class="col-md-6">
                                        <label class="form-label">Select matching Snipe-IT user</label>
                                        <select name="checkout_user_id" class="form-select" required>
                                            <option value="">-- Choose user --</option>
                                            <?php foreach ($pendingUserCandidates as $candidate): ?>
                                                <?php
                                                    $cid = (int)($candidate['id'] ?? 0);
                                                    $cEmail = $candidate['email'] ?? '';
                                                    $cName = $candidate['name'] ?? ($candidate['username'] ?? '');
                                                    $cLabel = $cName !== '' && $cEmail !== '' ? "{$cName} ({$cEmail})" : ($cName !== '' ? $cName : $cEmail);
                                                    $selectedAttr = $selectedUserId === $cid ? 'selected' : '';
                                                ?>
                                                <option value="<?= $cid ?>" <?= $selectedAttr ?>><?= h($cLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">Multiple users matched the search. Choose which account to use.</div>
                                    </div>
                                <?php endif; ?>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Return date &amp; time</label>
                                    <input type="datetime-local"
                                           name="end_datetime"
                                           class="form-control"
                                           value="<?= h($endRaw) ?>">
                                    <div class="form-text">Checkout happens immediately. Defaults to tomorrow at 09:00.</div>
                                </div>
                            </div>

                            <?php if (!empty($reservationConflicts)): ?>
                                <div class="form-check mb-3">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           value="1"
                                           id="override_conflicts"
                                           name="override_conflicts"
                                           <?= $overrideValue ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="override_conflicts">
                                        Override reservations during this checkout period and check out anyway
                                    </label>
                                </div>
                            <?php endif; ?>

                            <button type="submit" class="btn btn-primary quick-checkout-submit-button">
                                Check out all listed items
                            </button>
                        </form>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
(function () {
    const assetWrappers = document.querySelectorAll('.asset-autocomplete-wrapper');
    assetWrappers.forEach((wrapper) => {
        const input = wrapper.querySelector('.asset-autocomplete');
        const list  = wrapper.querySelector('[data-asset-suggestions]');
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
            timer = setTimeout(() => fetchSuggestions(q), 200);
        });

        input.addEventListener('blur', () => {
            setTimeout(hideSuggestions, 150);
        });

        function fetchSuggestions(q) {
            lastQuery = q;
            fetch('quick_checkout.php?ajax=asset_search&q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then((res) => res.ok ? res.json() : Promise.reject())
                .then((data) => {
                    if (lastQuery !== q) return;
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
                const tag = item.asset_tag || '';
                const model = item.model || '';
                const label = model !== '' ? `${tag} [${model}]` : tag;

                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'list-group-item list-group-item-action';
                btn.textContent = label;
                btn.dataset.value = tag;

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
            setTimeout(hideSuggestions, 150);
        });

        function fetchSuggestions(q) {
            lastQuery = q;
            fetch('staff_checkout.php?ajax=user_search&q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then((res) => res.ok ? res.json() : Promise.reject())
                .then((data) => {
                    if (lastQuery !== q) return;
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
})();
</script>
<?php layout_footer(); ?>
</body>
</html>
