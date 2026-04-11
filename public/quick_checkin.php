<?php
// quick_checkin.php
// Standalone quick check-in page (quick scan style).

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/email.php';
require_once SRC_PATH . '/layout.php';

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

if (!isset($_SESSION['quick_checkin_items'])) {
    $_SESSION['quick_checkin_items'] = [];
}
$checkinItems = &$_SESSION['quick_checkin_items'];

$selectorTabRaw = strtolower(trim((string)($_POST['active_tab'] ?? ($_GET['tab'] ?? 'equipment'))));
$selectorTab = in_array($selectorTabRaw, ['equipment', 'accessories'], true) ? $selectorTabRaw : 'equipment';
$accessorySearchValue = trim((string)($_POST['accessory_search'] ?? ($_GET['accessory_search'] ?? '')));
$accessoryUserValue = trim((string)($_POST['accessory_user'] ?? ($_GET['accessory_user'] ?? '')));
$accessoryCategoryValue = trim((string)($_POST['accessory_category'] ?? ($_GET['accessory_category'] ?? '')));

$messages = [];
$errors   = [];

function qci_extract_record_image_path(array $record): string
{
    $candidates = [
        $record['image_path'] ?? null,
        $record['image'] ?? null,
        $record['image_url'] ?? null,
        $record['thumbnail'] ?? null,
        $record['thumbnail_url'] ?? null,
        is_array($record['image'] ?? null) ? ($record['image']['url'] ?? ($record['image']['path'] ?? ($record['image']['href'] ?? ''))) : null,
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

function qci_image_proxy_url(string $imagePath): string
{
    $imagePath = trim($imagePath);
    if ($imagePath === '') {
        return '';
    }

    return 'image_proxy.php?src=' . urlencode($imagePath);
}

function qci_accessory_category_name(array $item): string
{
    $category = $item['category_name'] ?? ($item['category'] ?? '');
    if (is_array($category)) {
        $category = $category['name'] ?? '';
    }

    return trim((string)$category);
}

function qci_assigned_user_fields($assigned): array
{
    if (!is_array($assigned)) {
        return [
            'name' => trim((string)$assigned),
            'email' => '',
        ];
    }

    return [
        'name' => trim((string)($assigned['name'] ?? '')),
        'email' => trim((string)($assigned['email'] ?? ($assigned['username'] ?? ''))),
    ];
}

// Remove single item
if (isset($_GET['remove'])) {
    $rid = (string)$_GET['remove'];
    if ($rid !== '' && isset($checkinItems[$rid])) {
        unset($checkinItems[$rid]);
    }
    $redirectTab = $_GET['tab'] ?? 'equipment';
    header('Location: quick_checkin.php?tab=' . urlencode($redirectTab));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode = $_POST['mode'] ?? '';

    if ($mode === 'add_asset') {
        $tag = trim($_POST['asset_tag'] ?? '');
        if ($tag === '') {
            $errors[] = 'Please scan or enter an asset tag.';
        } else {
            try {
                $asset = find_asset_by_tag($tag);
                $assetId   = (int)($asset['id'] ?? 0);
                $assetTag  = $asset['asset_tag'] ?? '';
                $assetName = $asset['name'] ?? '';
                $modelName = $asset['model']['name'] ?? '';
                $status    = $asset['status_label'] ?? '';
                if (is_array($status)) {
                    $status = $status['name'] ?? $status['status_meta'] ?? $status['label'] ?? '';
                }

                if ($assetId <= 0 || $assetTag === '') {
                    throw new Exception('Asset record from Snipe-IT is missing id/asset_tag.');
                }

                $assigned = $asset['assigned_to'] ?? null;
                if (empty($assigned) && isset($asset['assigned_to_fullname'])) {
                    $assigned = $asset['assigned_to_fullname'];
                }
                $assignedEmail = '';
                $assignedName  = '';
                $assignedId    = 0;
                if (is_array($assigned)) {
                    $assignedId    = (int)($assigned['id'] ?? 0);
                    $assignedEmail = $assigned['email'] ?? ($assigned['username'] ?? '');
                    $assignedName  = $assigned['name'] ?? ($assigned['username'] ?? ($assigned['email'] ?? ''));
                } elseif (is_string($assigned)) {
                    $assignedName = $assigned;
                }

                $checkinItems[$assetId] = [
                    'id'         => $assetId,
                    'item_type'  => 'asset',
                    'asset_tag'  => $assetTag,
                    'name'       => $assetName,
                    'model'      => $modelName,
                    'status'     => $status,
                    'assigned_id'    => $assignedId,
                    'assigned_email' => $assignedEmail,
                    'assigned_name'  => $assignedName,
                ];
                $label = $modelName !== '' ? $modelName : $assetName;
                $messages[] = "Added asset {$assetTag} ({$label}) to check-in list.";
            } catch (Throwable $e) {
                $errors[] = 'Could not add asset: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'add_accessory') {
        $accessoryId = (int)($_POST['accessory_id'] ?? 0);
        $accessoryCheckoutId = (int)($_POST['accessory_checkout_id'] ?? 0);
        if ($accessoryId <= 0) {
            $errors[] = 'Invalid accessory ID.';
        } else {
            try {
                $checkedOutAccessories = fetch_checked_out_accessories_from_snipeit();
                // Find the accessory in the checked out list
                $found = false;
                foreach ($checkedOutAccessories as $accessory) {
                    if ((int)$accessory['id'] === $accessoryId && (int)$accessory['accessory_checkout_id'] === $accessoryCheckoutId) {
                        $checkinItems[$accessoryId . '_' . $accessoryCheckoutId] = [
                            'id'         => $accessoryId,
                            'item_type'  => 'accessory',
                            'accessory_checkout_id' => $accessoryCheckoutId,
                            'asset_tag'  => '',
                            'name'       => $accessory['name'] ?? '',
                            'model'      => $accessory['manufacturer_name'] ?? '',
                            'image'      => $accessory['image'] ?? '',
                            'image_path' => $accessory['image_path'] ?? ($accessory['image'] ?? ''),
                            'category_name' => qci_accessory_category_name($accessory),
                            'status'     => '',
                            'assigned_id'    => is_array($accessory['assigned_to'] ?? null) ? (int)($accessory['assigned_to']['id'] ?? 0) : 0,
                            'assigned_email' => qci_assigned_user_fields($accessory['assigned_to'] ?? [])['email'],
                            'assigned_name'  => qci_assigned_user_fields($accessory['assigned_to'] ?? [])['name'],
                        ];
                        $messages[] = "Added accessory {$accessory['name']} to check-in list.";
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    throw new Exception('Accessory not found in checked out list.');
                }
            } catch (Throwable $e) {
                $errors[] = 'Could not add accessory: ' . $e->getMessage();
            }
        }
    } elseif ($mode === 'checkin') {
        $note = trim($_POST['note'] ?? '');

        if (empty($checkinItems)) {
            $errors[] = 'There are no items in the check-in list.';
        } else {
            $activeTab = $_POST['active_tab'] ?? 'equipment';
            $itemsToCheckin = $checkinItems;
            
            // Filter items based on active tab
            if ($activeTab === 'equipment') {
                $itemsToCheckin = array_filter($checkinItems, function($item) {
                    return ($item['item_type'] ?? 'asset') === 'asset';
                });
            }
            // For accessories tab, process all items
            
            if (empty($itemsToCheckin)) {
                $errors[] = 'There are no ' . ($activeTab === 'equipment' ? 'assets' : 'items') . ' in the check-in list.';
            } else {
                $hadCheckinItems = !empty($checkinItems);
                $staffEmail = $currentUser['email'] ?? '';
                $staffName  = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
                $staffDisplayName = $staffName !== '' ? $staffName : ($currentUser['email'] ?? 'Staff');
                $itemLabels  = [];
                $userBuckets = [];
                $summaryBuckets = [];
                $userLookupCache = [];
                $userIdCache = [];
                $checkedInItemKeys = [];

                foreach ($itemsToCheckin as $itemKey => $item) {
                $itemType = $item['item_type'] ?? 'asset';
                $itemId  = (int)$item['id'];
                $itemName = $item['name'] ?? '';
                try {
                    $checkedInItemKeys[] = $itemKey;
                    $assignedEmail = $item['assigned_email'] ?? '';
                    $assignedName  = $item['assigned_name'] ?? '';
                    $assignedId    = (int)($item['assigned_id'] ?? 0);
                    if (($assignedEmail === '' && $assignedName === '') || $assignedId === 0) {
                        // For accessories, we might need to refresh data
                        if ($itemType === 'accessory') {
                            // Accessory assignment data should already be correct
                        } elseif ($itemType === 'asset') {
                            try {
                                $freshAsset = snipeit_request('GET', 'hardware/' . $itemId);
                                $freshAssigned = $freshAsset['assigned_to'] ?? null;
                                if (empty($freshAssigned) && isset($freshAsset['assigned_to_fullname'])) {
                                    $freshAssigned = $freshAsset['assigned_to_fullname'];
                                }
                                if (is_array($freshAssigned)) {
                                    $assignedId    = (int)($freshAssigned['id'] ?? $assignedId);
                                    $assignedEmail = $freshAssigned['email'] ?? ($freshAssigned['username'] ?? $assignedEmail);
                                    $assignedName  = $freshAssigned['name'] ?? ($freshAssigned['username'] ?? ($freshAssigned['email'] ?? $assignedName));
                                } elseif (is_string($freshAssigned) && $assignedName === '') {
                                    $assignedName = $freshAssigned;
                                }
                            } catch (Throwable $e) {
                                // Skip fresh lookup; proceed with stored assignment data.
                            }
                        }
                    }

                    if ($itemType === 'asset') {
                        checkin_asset($itemId, $note);
                        $assetTag = $item['asset_tag'] ?? '';
                        $messages[] = "Checked in asset {$assetTag}.";
                        $model = $item['model'] ?? '';
                        $formatted = $model !== '' ? ($assetTag . ' (' . $model . ')') : $assetTag;
                        $itemLabels[] = $formatted;
                    } elseif ($itemType === 'accessory') {
                        $accessoryCheckoutId = (int)($item['accessory_checkout_id'] ?? 0);
                        if ($accessoryCheckoutId > 0) {
                            checkin_accessory($accessoryCheckoutId, $note);
                            $messages[] = "Checked in accessory {$itemName}.";
                            $formatted = $itemName;
                            $itemLabels[] = $formatted;
                        } else {
                            throw new Exception('Invalid accessory checkout ID.');
                        }
                    }

                    if ($assignedEmail === '' && $assignedId > 0) {
                        if (isset($userIdCache[$assignedId])) {
                            $cached = $userIdCache[$assignedId];
                            $assignedEmail = $cached['email'] ?? '';
                            $assignedName = $assignedName !== '' ? $assignedName : ($cached['name'] ?? '');
                        } else {
                            try {
                                $matchedUser = snipeit_request('GET', 'users/' . $assignedId);
                                $matchedEmail = $matchedUser['email'] ?? ($matchedUser['username'] ?? '');
                                $matchedName  = $matchedUser['name'] ?? ($matchedUser['username'] ?? '');
                                $userIdCache[$assignedId] = [
                                    'email' => $matchedEmail,
                                    'name'  => $matchedName,
                                ];
                                if ($matchedEmail !== '') {
                                    $assignedEmail = $matchedEmail;
                                }
                                if ($assignedName === '' && $matchedName !== '') {
                                    $assignedName = $matchedName;
                                }
                            } catch (Throwable $e) {
                                // Skip lookup failure; user details may be unavailable.
                            }
                        }
                    }
                    if ($assignedEmail === '' && $assignedName !== '') {
                        $cacheKey = strtolower(trim($assignedName));
                        if (isset($userLookupCache[$cacheKey])) {
                            $assignedEmail = $userLookupCache[$cacheKey];
                        } else {
                            try {
                                $matchedUser = find_single_user_by_email_or_name($assignedName);
                                $matchedEmail = $matchedUser['email'] ?? ($matchedUser['username'] ?? '');
                                if ($matchedEmail !== '') {
                                    $assignedEmail = $matchedEmail;
                                    $userLookupCache[$cacheKey] = $matchedEmail;
                                }
                            } catch (Throwable $e) {
                                try {
                                    $data = snipeit_request('GET', 'users', [
                                        'search' => $assignedName,
                                        'limit'  => 50,
                                    ]);
                                    $rows = $data['rows'] ?? [];
                                    $exact = [];
                                    $nameLower = strtolower(trim($assignedName));
                                    foreach ($rows as $row) {
                                        $rowName = strtolower(trim((string)($row['name'] ?? '')));
                                        $rowEmail = strtolower(trim((string)($row['email'] ?? ($row['username'] ?? ''))));
                                        if ($rowName !== '' && $rowName === $nameLower) {
                                            $exact[] = $row;
                                        } elseif ($rowEmail !== '' && $rowEmail === $nameLower) {
                                            $exact[] = $row;
                                        }
                                    }
                                    if (!empty($exact)) {
                                        $picked = $exact[0];
                                        $matchedEmail = $picked['email'] ?? ($picked['username'] ?? '');
                                        if ($matchedEmail !== '') {
                                            $assignedEmail = $matchedEmail;
                                            $userLookupCache[$cacheKey] = $matchedEmail;
                                        }
                                        if ($assignedName === '') {
                                            $assignedName = $picked['name'] ?? ($picked['username'] ?? '');
                                        }
                                    }
                                } catch (Throwable $e2) {
                                    // Skip lookup failure; user email may be unavailable.
                                }
                            }
                        }
                    }
                    if ($assignedEmail === '' && $assignedName === '' && $assignedId === 0) {
                        try {
                            $history = snipeit_request('GET', 'hardware/' . $itemId . '/history');
                            $rows = $history['rows'] ?? [];
                            foreach ($rows as $row) {
                                $action = strtolower((string)($row['action_type'] ?? ($row['action'] ?? '')));
                                if ($action === '' || strpos($action, 'checkout') === false) {
                                    continue;
                                }
                                $target = $row['target'] ?? null;
                                $histId = 0;
                                $histName = '';
                                $histEmail = '';
                                if (is_array($target)) {
                                    $histId = (int)($target['id'] ?? 0);
                                    $histName = $target['name'] ?? ($target['username'] ?? '');
                                    $histEmail = $target['email'] ?? ($target['username'] ?? '');
                                } else {
                                    $histId = (int)($row['target_id'] ?? 0);
                                    $histName = $row['target_name'] ?? ($row['checkedout_to'] ?? '');
                                    $histEmail = $row['target_email'] ?? '';
                                }

                                if ($histEmail === '' && $histId > 0) {
                                    if (isset($userIdCache[$histId])) {
                                        $cached = $userIdCache[$histId];
                                        $histEmail = $cached['email'] ?? '';
                                        $histName = $histName !== '' ? $histName : ($cached['name'] ?? '');
                                    } else {
                                        try {
                                            $matchedUser = snipeit_request('GET', 'users/' . $histId);
                                            $matchedEmail = $matchedUser['email'] ?? ($matchedUser['username'] ?? '');
                                            $matchedName  = $matchedUser['name'] ?? ($matchedUser['username'] ?? '');
                                            $userIdCache[$histId] = [
                                                'email' => $matchedEmail,
                                                'name'  => $matchedName,
                                            ];
                                            $histEmail = $matchedEmail;
                                            if ($histName === '' && $matchedName !== '') {
                                                $histName = $matchedName;
                                            }
                                        } catch (Throwable $e) {
                                            // Skip lookup failure; user details may be unavailable.
                                        }
                                    }
                                }

                                if ($histEmail !== '' || $histName !== '') {
                                    $assignedEmail = $histEmail !== '' ? $histEmail : $assignedEmail;
                                    $assignedName = $histName !== '' ? $histName : $assignedName;
                                    break;
                                }
                            }
                        } catch (Throwable $e) {
                            // Skip history lookup failure.
                        }
                    }

                    $summaryLabel = '';
                    if ($assignedEmail !== '') {
                        $summaryLabel = $assignedName !== '' && $assignedName !== $assignedEmail
                            ? ($assignedName . " <{$assignedEmail}>")
                            : $assignedEmail;
                    } elseif ($assignedName !== '') {
                        $summaryLabel = $assignedName;
                    } else {
                        $summaryLabel = 'Unknown user';
                    }
                    if (!isset($summaryBuckets[$summaryLabel])) {
                        $summaryBuckets[$summaryLabel] = [];
                    }
                    $summaryBuckets[$summaryLabel][] = $formatted;

                    if ($assignedEmail !== '') {
                        if (!isset($userBuckets[$assignedEmail])) {
                            $displayName = $assignedName !== '' ? $assignedName : $assignedEmail;
                            $userBuckets[$assignedEmail] = [
                                'name' => $displayName,
                                'assets' => [],
                            ];
                        }
                        $userBuckets[$assignedEmail]['assets'][] = $formatted;
                    }
                } catch (Throwable $e) {
                    $itemLabel = $itemType === 'asset' ? ($item['asset_tag'] ?? '') : $itemName;
                    $errors[] = "Failed to check in {$itemLabel}: " . $e->getMessage();
                }
            }
            if (empty($errors)) {
                $itemLineItems = array_map(static function (string $item): string {
                    return '- ' . $item;
                }, array_values(array_filter($itemLabels, static function (string $item): bool {
                    return $item !== '';
                })));

                $config = load_config();
                $appCfg = $config['app'] ?? [];
                $notifyEnabled = array_key_exists('notification_quick_checkin_enabled', $appCfg)
                    ? !empty($appCfg['notification_quick_checkin_enabled'])
                    : true;
                $sendUserDefault = array_key_exists('notification_quick_checkin_send_user', $appCfg)
                    ? !empty($appCfg['notification_quick_checkin_send_user'])
                    : true;
                $sendStaffDefault = array_key_exists('notification_quick_checkin_send_staff', $appCfg)
                    ? !empty($appCfg['notification_quick_checkin_send_staff'])
                    : true;
                if ($notifyEnabled) {
                    $notifiedEmails = [];
                    $userPortalLinkLine = layout_my_reservations_link_line($config);
                    $staffPortalLinkLine = layout_staff_reservations_link_line($config);

                    // Notify original users.
                    if ($sendUserDefault) {
                        foreach ($userBuckets as $email => $info) {
                            $userAssetLines = array_map(static function (string $item): string {
                                return '- ' . $item;
                            }, array_values(array_filter($info['assets'], static function (string $item): bool {
                                return $item !== '';
                            })));
                            $bodyLines = array_merge(
                                ['The following items have been checked in:'],
                                $userAssetLines,
                                $staffDisplayName !== '' ? ["Checked in by: {$staffDisplayName}"] : [],
                                $note !== '' ? ["Note: {$note}"] : []
                            );
                            if ($userPortalLinkLine !== null) {
                                $bodyLines[] = $userPortalLinkLine;
                            }
                            layout_send_notification($email, $info['name'], 'Assets checked in', $bodyLines, $config);
                            $notifiedEmails[] = $email;
                        }
                    }

                    // Build per-user summary so staff and extra recipients can see who had the assets.
                    $perUserSummary = [];
                    foreach ($summaryBuckets as $label => $assets) {
                        $perUserSummary[] = '- ' . $label . ': ' . implode(', ', $assets);
                    }

                    $staffBodyLines = [];
                    $staffBodyLines[] = 'You checked in the following items:';
                    if (!empty($perUserSummary)) {
                        $staffBodyLines = array_merge($staffBodyLines, $perUserSummary);
                    } else {
                        $staffBodyLines = array_merge($staffBodyLines, $itemLineItems);
                    }
                    if ($note !== '') {
                        $staffBodyLines[] = "Note: {$note}";
                    }
                    if ($staffPortalLinkLine !== null) {
                        $staffBodyLines[] = $staffPortalLinkLine;
                    }

                    // Notify staff performing check-in.
                    if ($sendStaffDefault && $staffEmail !== '' && !empty($itemLabels)) {
                        layout_send_notification($staffEmail, $staffDisplayName, 'Items checked in', $staffBodyLines, $config);
                        $notifiedEmails[] = $staffEmail;
                    }

                    // Notify extra configured recipients.
                    $extraRecipients = layout_extra_notification_recipients(
                        (string)($appCfg['notification_quick_checkin_extra_emails'] ?? ''),
                        $notifiedEmails
                    );
                    $extraBodyLines = [];
                    $extraBodyLines[] = 'The following items were checked in:';
                    if (!empty($perUserSummary)) {
                        $extraBodyLines = array_merge($extraBodyLines, $perUserSummary);
                    } else {
                        $extraBodyLines = array_merge($extraBodyLines, $itemLineItems);
                    }
                    if ($staffDisplayName !== '') {
                        $extraBodyLines[] = "Checked in by: {$staffDisplayName}";
                    }
                    if ($note !== '') {
                        $extraBodyLines[] = "Note: {$note}";
                    }
                    if ($staffPortalLinkLine !== null) {
                        $extraBodyLines[] = $staffPortalLinkLine;
                    }
                    foreach ($extraRecipients as $recipient) {
                        layout_send_notification(
                            $recipient['email'],
                            $recipient['name'],
                            'Items checked in',
                            $extraBodyLines,
                            $config
                        );
                    }
                }

                $checkedInFrom = array_keys($summaryBuckets);
                activity_log_event('quick_checkin', 'Quick checkin completed', [
                    'metadata' => [
                        'items' => $itemLabels,
                        'checked_in_from' => $checkedInFrom,
                        'note'   => $note,
                    ],
                ]);
            }
            if ($hadCheckinItems) {
                foreach ($checkedInItemKeys as $itemKey) {
                    unset($checkinItems[$itemKey]);
                }
            }
        }
    }
}
}

$checkedOutAccessories = [];
$accessoryUsers = [];
$accessoryCategories = [];
if ($selectorTab === 'accessories') {
    try {
        $checkedOutAccessories = fetch_checked_out_accessories_from_snipeit();

        // Collect filter options before applying filters so each dropdown remains complete.
        $userSet = [];
        $categorySet = [];
        foreach ($checkedOutAccessories as $item) {
            $assigned = qci_assigned_user_fields($item['assigned_to'] ?? []);
            if ($assigned['name'] !== '') {
                $userSet[$assigned['name']] = $assigned['name'];
            }
            if ($assigned['email'] !== '') {
                $userSet[$assigned['email']] = $assigned['email'];
            }

            $category = qci_accessory_category_name($item);
            if ($category !== '') {
                $categorySet[$category] = $category;
            }
        }
        $accessoryUsers = array_values($userSet);
        sort($accessoryUsers);
        $accessoryCategories = array_values($categorySet);
        sort($accessoryCategories);
        
        // Filter by search
        if ($accessorySearchValue !== '') {
            $searchLower = strtolower($accessorySearchValue);
            $checkedOutAccessories = array_filter($checkedOutAccessories, function($item) use ($searchLower) {
                return stripos($item['name'] ?? '', $searchLower) !== false ||
                       stripos($item['manufacturer_name'] ?? '', $searchLower) !== false ||
                       stripos(qci_accessory_category_name($item), $searchLower) !== false;
            });
        }
        
        // Filter by user
        if ($accessoryUserValue !== '') {
            $checkedOutAccessories = array_filter($checkedOutAccessories, function($item) use ($accessoryUserValue) {
                $assigned = qci_assigned_user_fields($item['assigned_to'] ?? []);
                return $assigned['name'] === $accessoryUserValue || $assigned['email'] === $accessoryUserValue;
            });
        }
        
        // Filter by category
        if ($accessoryCategoryValue !== '') {
            $checkedOutAccessories = array_filter($checkedOutAccessories, function($item) use ($accessoryCategoryValue) {
                return qci_accessory_category_name($item) === $accessoryCategoryValue;
            });
        }
    } catch (Throwable $e) {
        $errors[] = 'Could not load checked out accessories: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quick Checkin – <?= h(layout_app_name()) ?></title>
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
            <h1>Quick Checkin</h1>
            <div class="page-subtitle">
                Scan or type asset tags to check items back in via Snipe-IT.
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
                <h5 class="card-title">Quick Checkin</h5>
                <p class="card-text">
                    Use the available tabs below to view equipment or accessories for check-in.
                </p>
                <?php
                    $equipmentTabUrl = 'quick_checkin.php';
                    $accessoryTabParams = ['tab' => 'accessories'];
                    if ($selectorTab === 'accessories') {
                        if ($accessorySearchValue !== '') $accessoryTabParams['accessory_search'] = $accessorySearchValue;
                        if ($accessoryUserValue !== '') $accessoryTabParams['accessory_user'] = $accessoryUserValue;
                        if ($accessoryCategoryValue !== '') $accessoryTabParams['accessory_category'] = $accessoryCategoryValue;
                    }
                    $accessoryTabUrl = 'quick_checkin.php?' . http_build_query($accessoryTabParams);
                    $checkinEntryCount = $selectorTab === 'equipment' 
                        ? count(array_filter($checkinItems, function($item) { return ($item['item_type'] ?? 'asset') === 'asset'; }))
                        : count($checkinItems);
                ?>

                <ul class="nav reservations-subtabs quick-checkin-tabs mb-3">
                    <li class="nav-item">
                        <a class="nav-link <?= $selectorTab === 'equipment' ? 'active' : '' ?>" href="<?= h($equipmentTabUrl) ?>">Equipment</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $selectorTab === 'accessories' ? 'active' : '' ?>" href="<?= h($accessoryTabUrl) ?>">Accessories</a>
                    </li>
                </ul>

                <?php if ($selectorTab === 'equipment'): ?>
                <div class="quick-checkout-panel quick-checkout-panel--picker filter-panel filter-panel--compact">
                    <div class="filter-panel__header d-flex align-items-center gap-3">
                        <span class="filter-panel__dot"></span>
                        <div>
                            <div class="filter-panel__title">QUICK CHECKIN</div>
                            <div class="quick-checkout-panel__intro">Scan or type asset tags to build the check-in list.</div>
                        </div>
                    </div>

                    <div class="quick-checkout-picker-surface">
                        <form method="post" class="row g-2 mb-0">
                            <input type="hidden" name="mode" value="add_asset">
                            <input type="hidden" name="active_tab" value="equipment">
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
                                    Add to check-in list
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="quick-checkout-panel quick-checkout-panel--shared filter-panel filter-panel--compact mt-4">
                    <div class="quick-checkout-panel__header quick-checkout-panel__header--basket d-flex align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <span class="filter-panel__dot"></span>
                            <div class="filter-panel__title">CHECK-IN LIST</div>
                        </div>
                        <div class="quick-checkout-panel__meta">
                            <span class="quick-checkout-panel__count"><?= (int)$checkinEntryCount ?> item<?= $checkinEntryCount === 1 ? '' : 's' ?></span>
                        </div>
                    </div>
                    <div class="quick-checkout-panel__subtitle">Items stay here until you check them in.</div>

                    <div class="quick-checkout-basket-surface">
                    <?php 
                        $assetsInCheckinList = array_filter($checkinItems, function($item) { 
                            return ($item['item_type'] ?? 'asset') === 'asset'; 
                        });
                    ?>
                    <?php if (empty($assetsInCheckinList)): ?>
                        <div class="alert alert-secondary mb-0">
                            No assets in the check-in list yet. Scan or enter an asset tag above.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Asset Tag</th>
                                        <th>Name</th>
                                        <th>Model</th>
                                        <th>Checked out to</th>
                                        <th style="width: 80px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assetsInCheckinList as $item): ?>
                                        <tr>
                                            <td><?= h($item['asset_tag']) ?></td>
                                            <td><?= h($item['name']) ?></td>
                                            <td><?= h($item['model']) ?></td>
                                            <?php
                                                $assignedName = $item['assigned_name'] ?? '';
                                                $assignedEmail = $item['assigned_email'] ?? '';
                                                if ($assignedEmail !== '') {
                                                    $assignedLabel = $assignedName !== '' && $assignedName !== $assignedEmail
                                                        ? $assignedName . " <{$assignedEmail}>"
                                                        : $assignedEmail;
                                                } elseif ($assignedName !== '') {
                                                    $assignedLabel = $assignedName;
                                                } else {
                                                    $assignedLabel = 'Not checked out';
                                                }
                                            ?>
                                            <td><?= h($assignedLabel) ?></td>
                                            <td>
                                                <a href="quick_checkin.php?remove=<?= (int)$item['id'] ?>&tab=equipment"
                                                   class="btn btn-sm btn-outline-danger">
                                                    Remove
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <form method="post" class="border-top pt-3">
                            <input type="hidden" name="mode" value="checkin">
                            <input type="hidden" name="active_tab" value="equipment">

                            <div class="row g-3 mb-3">
                                <div class="col-md-12">
                                    <label class="form-label">Note (optional)</label>
                                    <input type="text"
                                           name="note"
                                           class="form-control"
                                           placeholder="Optional note to store with check-in">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary quick-checkout-submit-button">
                                Check in all listed assets
                            </button>
                        </form>
                    <?php endif; ?>
                    </div>
                </div>
                <?php elseif ($selectorTab === 'accessories'): ?>
                <div class="quick-checkout-panel quick-checkout-panel--picker filter-panel filter-panel--compact">
                    <div class="filter-panel__header d-flex align-items-center gap-3">
                        <span class="filter-panel__dot"></span>
                        <div>
                            <div class="filter-panel__title">CHECKED OUT ACCESSORIES</div>
                            <div class="quick-checkout-panel__intro">Browse and filter currently checked out accessories.</div>
                        </div>
                    </div>

                    <div class="quick-checkout-picker-surface">
                        <form method="get" class="row g-2 mb-3 align-items-end quick-checkin-accessory-filters" data-accessory-filter-form>
                            <input type="hidden" name="tab" value="accessories">
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <div class="input-group filter-search">
                                    <span class="input-group-text filter-search__icon" aria-hidden="true">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/>
                                            <line x1="15.5" y1="15.5" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                        </svg>
                                    </span>
                                    <input type="text"
                                           name="accessory_search"
                                           class="form-control filter-search__input"
                                           placeholder="Search accessories..."
                                           value="<?= h($accessorySearchValue) ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">User</label>
                                <input type="text"
                                       name="accessory_user"
                                       class="form-control"
                                       list="accessory-user-options"
                                       autocomplete="off"
                                       placeholder="All users"
                                       value="<?= h($accessoryUserValue) ?>"
                                       data-accessory-user-filter>
                                <datalist id="accessory-user-options">
                                    <?php foreach ($accessoryUsers as $user): ?>
                                        <option value="<?= h($user) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Category</label>
                                <select name="accessory_category" class="form-select" data-accessory-category-filter>
                                    <option value="">All categories</option>
                                    <?php foreach ($accessoryCategories as $category): ?>
                                        <option value="<?= h($category) ?>" <?= $accessoryCategoryValue === $category ? 'selected' : '' ?>><?= h($category) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2 quick-checkin-filter-submit">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary btn-sm w-100">Filter</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!empty($checkinItems)): ?>
                <div class="quick-checkout-panel quick-checkout-panel--basket filter-panel filter-panel--compact mt-4">
                    <div class="quick-checkout-panel__header quick-checkout-panel__header--basket d-flex align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <span class="filter-panel__dot"></span>
                            <div class="filter-panel__title">CHECK-IN LIST</div>
                        </div>
                        <div class="quick-checkout-panel__meta">
                            <span class="quick-checkout-panel__count"><?= count($checkinItems) ?> item<?= count($checkinItems) === 1 ? '' : 's' ?></span>
                        </div>
                    </div>
                    <div class="quick-checkout-panel__subtitle">Items ready for check-in.</div>

                    <div class="quick-checkout-basket-surface">
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Identifier</th>
                                        <th>Name</th>
                                        <th>Model</th>
                                        <th>Checked out to</th>
                                        <th style="width: 80px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($checkinItems as $itemKey => $item): ?>
                                        <tr>
                                            <td>
                                                <?php if ($item['item_type'] === 'asset'): ?>
                                                    <span class="badge bg-primary">Asset</span>
                                                <?php elseif ($item['item_type'] === 'accessory'): ?>
                                                    <span class="badge bg-secondary">Accessory</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($item['item_type'] === 'asset'): ?>
                                                    <?= h($item['asset_tag'] ?? '') ?>
                                                <?php elseif ($item['item_type'] === 'accessory'): ?>
                                                    ID: <?= h($item['id'] ?? '') ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h($item['name'] ?? '') ?></td>
                                            <td><?= h($item['model'] ?? '') ?></td>
                                            <?php
                                                if ($item['item_type'] === 'asset') {
                                                    $assignedName = $item['assigned_name'] ?? '';
                                                    $assignedEmail = $item['assigned_email'] ?? '';
                                                    if ($assignedEmail !== '') {
                                                        $assignedLabel = $assignedName !== '' && $assignedName !== $assignedEmail
                                                            ? $assignedName . " <{$assignedEmail}>"
                                                            : $assignedEmail;
                                                    } elseif ($assignedName !== '') {
                                                        $assignedLabel = $assignedName;
                                                    } else {
                                                        $assignedLabel = 'Not checked out';
                                                    }
                                                } elseif ($item['item_type'] === 'accessory') {
                                                    $assignedName = $item['assigned_name'] ?? '';
                                                    $assignedEmail = $item['assigned_email'] ?? '';
                                                    $assignedLabel = $assignedName !== '' && $assignedName !== $assignedEmail
                                                        ? $assignedName . " <{$assignedEmail}>"
                                                        : ($assignedEmail ?: $assignedName);
                                                } else {
                                                    $assignedLabel = '';
                                                }
                                            ?>
                                            <td><?= h($assignedLabel) ?></td>
                                            <td>
                                                <a href="quick_checkin.php?remove=<?= h((string)$itemKey) ?>&tab=accessories"
                                                   class="btn btn-sm btn-outline-danger">
                                                    Remove
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <form method="post" class="border-top pt-3">
                            <input type="hidden" name="mode" value="checkin">
                            <input type="hidden" name="active_tab" value="accessories">

                            <div class="row g-3 mb-3">
                                <div class="col-md-12">
                                    <label class="form-label">Note (optional)</label>
                                    <input type="text"
                                           name="note"
                                           class="form-control"
                                           placeholder="Optional note to store with check-in">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary quick-checkout-submit-button">
                                Check in all listed items
                            </button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <div class="quick-checkout-panel quick-checkout-panel--shared filter-panel filter-panel--compact mt-4">
                    <div class="quick-checkout-panel__header quick-checkout-panel__header--basket d-flex align-items-center justify-content-between gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <span class="filter-panel__dot"></span>
                            <div class="filter-panel__title">CHECKED OUT ACCESSORIES</div>
                        </div>
                        <div class="quick-checkout-panel__meta">
                            <span class="quick-checkout-panel__count"><?= count($checkedOutAccessories) ?> <?= count($checkedOutAccessories) === 1 ? 'accessory' : 'accessories' ?></span>
                        </div>
                    </div>
                    <div class="quick-checkout-panel__subtitle">Currently checked out accessories from Snipe-IT.</div>

                    <div class="quick-checkout-basket-surface">
                    <?php if (empty($checkedOutAccessories)): ?>
                        <div class="alert alert-secondary mb-0">
                            No checked out accessories found.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Image</th>
                                        <th>Name</th>
                                        <th>Category</th>
                                        <th>Checked out to</th>
                                        <th>Checked out since</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($checkedOutAccessories as $accessory): ?>
                                        <tr>
                                            <td>
                                                <?php
                                                    $accessoryImageUrl = qci_image_proxy_url(qci_extract_record_image_path($accessory));
                                                ?>
                                                <?php if ($accessoryImageUrl !== ''): ?>
                                                    <img src="<?= h($accessoryImageUrl) ?>"
                                                         alt="<?= h(($accessory['name'] ?? 'Accessory') . ' image') ?>"
                                                         class="report-model-thumb">
                                                <?php else: ?>
                                                    <div class="report-model-thumb report-model-thumb--placeholder" aria-hidden="true">A</div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= h($accessory['name'] ?? '') ?></td>
                                            <td><?= h(qci_accessory_category_name($accessory)) ?></td>
                                            <?php
                                                $assigned = qci_assigned_user_fields($accessory['assigned_to'] ?? []);
                                                if ($assigned['name'] !== '' || $assigned['email'] !== '') {
                                                    $assignedName = $assigned['name'];
                                                    $assignedEmail = $assigned['email'];
                                                    $assignedLabel = $assignedName !== '' && $assignedName !== $assignedEmail
                                                        ? $assignedName . " <{$assignedEmail}>"
                                                        : ($assignedEmail ?: $assignedName);
                                                } else {
                                                    $assignedLabel = '';
                                                }
                                            ?>
                                            <td><?= h($assignedLabel) ?></td>
                                            <td><?= h(app_format_datetime($accessory['last_checkout'] ?? '')) ?></td>
                                            <td>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="mode" value="add_accessory">
                                                    <input type="hidden" name="accessory_id" value="<?= h($accessory['id'] ?? '') ?>">
                                                    <input type="hidden" name="accessory_checkout_id" value="<?= h($accessory['accessory_checkout_id'] ?? '') ?>">
                                                    <input type="hidden" name="active_tab" value="accessories">
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Add to Check-in list</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
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
            fetch('quick_checkin.php?ajax=asset_search&q=' + encodeURIComponent(q), {
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

    const accessoryFilterForm = document.querySelector('[data-accessory-filter-form]');
    if (accessoryFilterForm) {
        const userInput = accessoryFilterForm.querySelector('[data-accessory-user-filter]');
        const categorySelect = accessoryFilterForm.querySelector('[data-accessory-category-filter]');
        const userOptions = Array.from(document.querySelectorAll('#accessory-user-options option'))
            .map((option) => option.value.trim())
            .filter((value) => value !== '');
        const userOptionSet = new Set(userOptions);
        let pendingUserSubmit = null;

        function submitAccessoryFilters() {
            if (accessoryFilterForm.requestSubmit) {
                accessoryFilterForm.requestSubmit();
            } else {
                accessoryFilterForm.submit();
            }
        }

        if (categorySelect) {
            categorySelect.addEventListener('change', submitAccessoryFilters);
        }

        if (userInput) {
            userInput.addEventListener('input', () => {
                const value = userInput.value.trim();
                if (pendingUserSubmit) {
                    clearTimeout(pendingUserSubmit);
                    pendingUserSubmit = null;
                }
                if (value === '' || userOptionSet.has(value)) {
                    pendingUserSubmit = setTimeout(submitAccessoryFilters, 150);
                }
            });

            userInput.addEventListener('change', () => {
                const value = userInput.value.trim();
                if (value === '' || userOptionSet.has(value)) {
                    submitAccessoryFilters();
                }
            });
        }
    }
})();
</script>
<?php layout_footer(); ?>
</body>
</html>
