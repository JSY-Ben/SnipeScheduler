<?php
// scripts/snipeit_asset_cache_update.php
// Sync checked-out assets and the requestable catalogue from Snipe-IT
// into the local cache tables, including models/assets, accessories, and kits.
//
// CLI only; intended for cron.
//
// Example cron:
// /usr/bin/php /path/to/scripts/snipeit_asset_cache_update.php >> /var/log/snipe_checked_out_sync.log 2>&1

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';

$config = load_config();
$scriptTz = app_get_timezone($config);
$nowStamp = static function () use ($config, $scriptTz): string {
    return app_format_datetime(time(), $config, $scriptTz);
};
$logOut = static function (string $level, string $message) use ($nowStamp): void {
    fwrite(STDOUT, '[' . $nowStamp() . '] [' . $level . '] ' . $message . PHP_EOL);
};
$logErr = static function (string $message) use ($nowStamp): void {
    fwrite(STDERR, '[' . $nowStamp() . '] [error] ' . $message . PHP_EOL);
};
$logOut('info', 'sync_checked_out_assets run started (checked-out + catalogue cache)');
$catalogueCacheRequested = app_version_is_at_least('1.4.0');
$catalogueCacheEnabled = $catalogueCacheRequested && snipeit_catalogue_cache_tables_exist(true);
if ($catalogueCacheRequested && !$catalogueCacheEnabled) {
    $logOut('info', 'Catalogue cache sync skipped until the v1.4.0 schema upgrade is applied.');
}
$accessoryCacheEnabled = $catalogueCacheRequested && snipeit_catalogue_accessory_cache_tables_exist(true);
$kitCacheEnabled = $catalogueCacheRequested && snipeit_catalogue_kit_cache_tables_exist(true);
if ($catalogueCacheRequested && $catalogueCacheEnabled && (!$accessoryCacheEnabled || !$kitCacheEnabled)) {
    $logOut('info', 'Accessory/kit catalogue cache sync skipped until the v1.5.1 schema upgrade is applied.');
}

$allowedCategoryMap = [];
$allowedCfg = $config['catalogue']['allowed_categories'] ?? [];
if (is_array($allowedCfg)) {
    foreach ($allowedCfg as $cid) {
        if (ctype_digit((string)$cid) || is_int($cid)) {
            $cid = (int)$cid;
            if ($cid > 0) {
                $allowedCategoryMap[$cid] = true;
            }
        }
    }
}

if ($catalogueCacheEnabled && !empty($allowedCategoryMap)) {
    $logOut('info', 'Catalogue cache limited to configured allowed categories (' . count($allowedCategoryMap) . ').');
}

if ($catalogueCacheEnabled) {
    try {
        $allModels = fetch_all_models_from_snipeit('', null, false);
    } catch (Throwable $e) {
        $logErr('Failed to load models: ' . $e->getMessage());
        exit(1);
    }
} else {
    $allModels = [];
}

if ($accessoryCacheEnabled) {
    try {
        $allAccessories = fetch_all_accessories_from_snipeit('', false);
    } catch (Throwable $e) {
        $logErr('Failed to load accessories: ' . $e->getMessage());
        exit(1);
    }
} else {
    $allAccessories = [];
}

if ($kitCacheEnabled) {
    try {
        $allKits = fetch_all_kits_from_snipeit('', false);
    } catch (Throwable $e) {
        $logErr('Failed to load kits: ' . $e->getMessage());
        exit(1);
    }
} else {
    $allKits = [];
}

try {
    $allHardware = fetch_all_hardware_from_snipeit(0, false);
} catch (Throwable $e) {
    $logErr('Failed to load hardware assets: ' . $e->getMessage());
    exit(1);
}

$catalogueModels = [];
foreach ($allModels as $model) {
    if (empty($model['requestable'])) {
        continue;
    }

    $categoryId = isset($model['category']['id']) ? (int)$model['category']['id'] : 0;
    if (!empty($allowedCategoryMap) && ($categoryId <= 0 || !isset($allowedCategoryMap[$categoryId]))) {
        continue;
    }

    $modelId = (int)($model['id'] ?? 0);
    if ($modelId <= 0) {
        continue;
    }

    $notes = $model['notes'] ?? '';
    if (is_array($notes)) {
        $notes = $notes['text'] ?? '';
    }

    $catalogueModels[$modelId] = [
        'model_id' => $modelId,
        'model_name' => (string)($model['name'] ?? ''),
        'manufacturer_name' => (string)($model['manufacturer']['name'] ?? ''),
        'category_id' => $categoryId,
        'category_name' => (string)($model['category']['name'] ?? ''),
        'image_path' => (string)($model['image'] ?? ''),
        'notes_text' => (string)$notes,
        'total_asset_count' => isset($model['assets_count']) && is_numeric($model['assets_count'])
            ? (int)$model['assets_count']
            : (isset($model['assets_count_total']) && is_numeric($model['assets_count_total'])
                ? (int)$model['assets_count_total']
                : 0),
        'requestable_asset_count' => 0,
        'raw_payload' => snipeit_json_encode_payload($model),
    ];
}

$checkedOutAssets = [];
$catalogueAssets = [];
$seenHardwareAssetIds = [];

foreach ($allHardware as $asset) {
    $assetId = (int)($asset['id'] ?? 0);
    if ($assetId <= 0 || isset($seenHardwareAssetIds[$assetId])) {
        continue;
    }
    $seenHardwareAssetIds[$assetId] = true;

    $modelId = (int)($asset['model']['id'] ?? 0);
    $modelName = (string)($asset['model']['name'] ?? '');
    $assetTag = (string)($asset['asset_tag'] ?? '');
    $assetName = (string)($asset['name'] ?? '');
    $isAssetRequestable = !empty($asset['requestable']);
    $assigned = $asset['assigned_to'] ?? ($asset['assigned_to_fullname'] ?? '');
    $assignedFields = snipeit_extract_assigned_user_fields($assigned);
    $statusLabel = snipeit_normalize_status_label($asset['status_label'] ?? '');

    if ($catalogueCacheEnabled && $modelId > 0 && isset($catalogueModels[$modelId])) {
        if ($isAssetRequestable) {
            $catalogueModels[$modelId]['requestable_asset_count']++;
        }

        $catalogueAssets[] = [
            'asset_id' => $assetId,
            'model_id' => $modelId,
            'model_name' => $modelName,
            'asset_tag' => $assetTag,
            'asset_name' => $assetName,
            'requestable' => $isAssetRequestable ? 1 : 0,
            'status_label' => $statusLabel,
            'assigned_to_id' => $assignedFields['id'] > 0 ? $assignedFields['id'] : null,
            'assigned_to_name' => $assignedFields['name'] !== '' ? $assignedFields['name'] : null,
            'assigned_to_email' => $assignedFields['email'] !== '' ? $assignedFields['email'] : null,
            'assigned_to_username' => $assignedFields['username'] !== '' ? $assignedFields['username'] : null,
            'default_location_name' => snipeit_extract_default_location_name($asset),
            'raw_payload' => snipeit_json_encode_payload($asset),
        ];
    }

    $hasAssignment = $assignedFields['id'] > 0
        || $assignedFields['name'] !== ''
        || $assignedFields['email'] !== ''
        || $assignedFields['username'] !== '';
    if (!$isAssetRequestable || !$hasAssignment) {
        continue;
    }

    $lastCheckout = $asset['last_checkout'] ?? '';
    if (is_array($lastCheckout)) {
        $lastCheckout = $lastCheckout['datetime'] ?? ($lastCheckout['date'] ?? '');
    }

    $expectedCheckin = $asset['expected_checkin'] ?? '';
    if (is_array($expectedCheckin)) {
        $expectedCheckin = $expectedCheckin['datetime'] ?? ($expectedCheckin['date'] ?? '');
    }

    $checkedOutAssets[] = [
        'asset_id' => $assetId,
        'asset_tag' => $assetTag,
        'asset_name' => $assetName,
        'model_id' => $modelId,
        'model_name' => $modelName,
        'assigned_to_id' => $assignedFields['id'] > 0 ? $assignedFields['id'] : null,
        'assigned_to_name' => $assignedFields['name'] !== '' ? $assignedFields['name'] : null,
        'assigned_to_email' => $assignedFields['email'] !== '' ? $assignedFields['email'] : null,
        'assigned_to_username' => $assignedFields['username'] !== '' ? $assignedFields['username'] : null,
        'status_label' => $statusLabel !== '' ? $statusLabel : null,
        'last_checkout' => trim((string)$lastCheckout) !== '' ? (string)$lastCheckout : null,
        'expected_checkin' => trim((string)$expectedCheckin) !== '' ? (string)$expectedCheckin : null,
    ];
}

$extractText = static function ($value): string {
    if (is_array($value)) {
        $candidates = [
            $value['text'] ?? null,
            $value['name'] ?? null,
            $value['label'] ?? null,
            $value['value'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return '';
    }

    return trim((string)$value);
};

$catalogueAccessories = [];
$seenAccessoryIds = [];
foreach ($allAccessories as $accessory) {
    if (!is_array($accessory)) {
        continue;
    }

    $accessoryId = (int)($accessory['id'] ?? 0);
    if ($accessoryId <= 0 || isset($seenAccessoryIds[$accessoryId])) {
        continue;
    }
    $seenAccessoryIds[$accessoryId] = true;

    $manufacturerName = is_array($accessory['manufacturer'] ?? null)
        ? trim((string)($accessory['manufacturer']['name'] ?? ''))
        : trim((string)($accessory['manufacturer_name'] ?? ''));
    $imagePath = trim((string)($accessory['image'] ?? ($accessory['image_path'] ?? '')));
    $totalQuantity = isset($accessory['qty']) && is_numeric($accessory['qty'])
        ? (int)$accessory['qty']
        : (isset($accessory['quantity']) && is_numeric($accessory['quantity']) ? (int)$accessory['quantity'] : 0);

    $catalogueAccessories[] = [
        'accessory_id' => $accessoryId,
        'accessory_name' => trim((string)($accessory['name'] ?? '')),
        'manufacturer_name' => $manufacturerName,
        'category_id' => snipeit_extract_category_id($accessory),
        'category_name' => snipeit_extract_category_name($accessory),
        'image_path' => $imagePath,
        'notes_text' => $extractText($accessory['notes'] ?? ''),
        'total_quantity' => max(0, $totalQuantity),
        'available_quantity' => snipeit_accessory_available_quantity_from_payload($accessory),
        'raw_payload' => snipeit_json_encode_payload($accessory),
    ];
}

$catalogueKits = [];
$catalogueKitItems = [];
$seenKitIds = [];
$kitElementFields = ['models', 'accessories', 'licenses', 'consumables'];
foreach ($allKits as $kit) {
    if (!is_array($kit)) {
        continue;
    }

    $kitId = (int)($kit['id'] ?? 0);
    if ($kitId <= 0 || isset($seenKitIds[$kitId])) {
        continue;
    }
    $seenKitIds[$kitId] = true;

    $catalogueKits[] = [
        'kit_id' => $kitId,
        'kit_name' => trim((string)($kit['name'] ?? '')),
        'image_path' => trim((string)($kit['image'] ?? ($kit['image_path'] ?? ''))),
        'notes_text' => $extractText($kit['notes'] ?? ''),
        'raw_payload' => snipeit_json_encode_payload($kit),
    ];

    foreach ($kitElementFields as $field) {
        try {
            $elementRows = snipeit_get_kit_element_rows_from_payload($kit, $kitId, $field, false);
        } catch (Throwable $e) {
            $logOut('warn', 'Could not load kit #' . $kitId . ' ' . $field . ': ' . $e->getMessage());
            $elementRows = [];
        }

        $itemType = snipeit_kit_item_type_from_field($field);
        foreach ($elementRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $itemId = (int)($row['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $kitItemKey = $kitId . '|' . $itemType . '|' . $itemId;
            if (isset($catalogueKitItems[$kitItemKey])) {
                $catalogueKitItems[$kitItemKey]['quantity'] += snipeit_kit_element_quantity($row);
                continue;
            }

            $catalogueKitItems[$kitItemKey] = [
                'kit_id' => $kitId,
                'item_type' => $itemType,
                'item_id' => $itemId,
                'item_name' => trim((string)($row['name'] ?? ($itemType . ' #' . $itemId))),
                'quantity' => snipeit_kit_element_quantity($row),
                'raw_payload' => snipeit_json_encode_payload($row),
            ];
        }
    }
}

$checkedOutLiveTable = 'checked_out_asset_cache';
$checkedOutStageTable = 'checked_out_asset_cache_build';
$catalogueModelLiveTable = 'catalogue_model_cache';
$catalogueModelStageTable = 'catalogue_model_cache_build';
$catalogueAssetLiveTable = 'catalogue_asset_cache';
$catalogueAssetStageTable = 'catalogue_asset_cache_build';
$catalogueAccessoryLiveTable = 'catalogue_accessory_cache';
$catalogueAccessoryStageTable = 'catalogue_accessory_cache_build';
$catalogueKitLiveTable = 'catalogue_kit_cache';
$catalogueKitStageTable = 'catalogue_kit_cache_build';
$catalogueKitItemLiveTable = 'catalogue_kit_item_cache';
$catalogueKitItemStageTable = 'catalogue_kit_item_cache_build';
$syncTimestamp = date('Y-m-d H:i:s');

// Build replacement tables off to the side, then swap them in to keep live reads responsive during sync.
$stageTableExists = static function (string $stageTable) use ($pdo): bool {
    try {
        $pdo->query("SELECT 1 FROM `{$stageTable}` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
};

$resetStageTable = static function (string $liveTable, string $stageTable) use ($pdo, $stageTableExists): void {
    if (!$stageTableExists($stageTable)) {
        $pdo->exec("CREATE TABLE `{$stageTable}` LIKE `{$liveTable}`");
    }
    $pdo->exec("TRUNCATE TABLE `{$stageTable}`");
};

$swapTables = static function (array $pairs) use ($pdo): void {
    if (empty($pairs)) {
        return;
    }

    $renames = [];
    foreach ($pairs as $pair) {
        $liveTable = (string)($pair['live'] ?? '');
        $stageTable = (string)($pair['stage'] ?? '');
        if ($liveTable === '' || $stageTable === '') {
            continue;
        }

        $swapTable = $liveTable . '_swap';
        $renames[] = "`{$liveTable}` TO `{$swapTable}`";
        $renames[] = "`{$stageTable}` TO `{$liveTable}`";
        $renames[] = "`{$swapTable}` TO `{$stageTable}`";
    }

    if (!empty($renames)) {
        $pdo->exec('RENAME TABLE ' . implode(', ', $renames));
    }
};

$bulkInsertRows = static function (string $table, array $columns, array $rows, int $chunkSize = 50) use ($pdo): void {
    if (empty($rows) || empty($columns)) {
        return;
    }

    $columnList = implode(', ', array_map(static function (string $column): string {
        return "`{$column}`";
    }, $columns));
    $rowPlaceholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

    foreach (array_chunk($rows, max(1, $chunkSize)) as $chunk) {
        $valuesSql = implode(', ', array_fill(0, count($chunk), $rowPlaceholders));
        $params = [];

        foreach ($chunk as $row) {
            foreach ($columns as $column) {
                $params[] = $row[$column] ?? null;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO `{$table}` ({$columnList}) VALUES {$valuesSql}");
        $stmt->execute($params);
    }
};

try {
    $resetStageTable($checkedOutLiveTable, $checkedOutStageTable);
    if ($catalogueCacheEnabled) {
        $resetStageTable($catalogueModelLiveTable, $catalogueModelStageTable);
        $resetStageTable($catalogueAssetLiveTable, $catalogueAssetStageTable);
    }
    if ($accessoryCacheEnabled) {
        $resetStageTable($catalogueAccessoryLiveTable, $catalogueAccessoryStageTable);
    }
    if ($kitCacheEnabled) {
        $resetStageTable($catalogueKitLiveTable, $catalogueKitStageTable);
        $resetStageTable($catalogueKitItemLiveTable, $catalogueKitItemStageTable);
    }

    $checkedOutRows = [];
    foreach ($checkedOutAssets as $asset) {
        $checkedOutRows[] = [
            'asset_id' => $asset['asset_id'],
            'asset_tag' => $asset['asset_tag'],
            'asset_name' => $asset['asset_name'],
            'model_id' => $asset['model_id'],
            'model_name' => $asset['model_name'],
            'assigned_to_id' => $asset['assigned_to_id'],
            'assigned_to_name' => $asset['assigned_to_name'],
            'assigned_to_email' => $asset['assigned_to_email'],
            'assigned_to_username' => $asset['assigned_to_username'],
            'status_label' => $asset['status_label'],
            'last_checkout' => $asset['last_checkout'],
            'expected_checkin' => $asset['expected_checkin'],
            'updated_at' => $syncTimestamp,
        ];
    }
    $bulkInsertRows($checkedOutStageTable, [
        'asset_id',
        'asset_tag',
        'asset_name',
        'model_id',
        'model_name',
        'assigned_to_id',
        'assigned_to_name',
        'assigned_to_email',
        'assigned_to_username',
        'status_label',
        'last_checkout',
        'expected_checkin',
        'updated_at',
    ], $checkedOutRows);

    if ($catalogueCacheEnabled) {
        $catalogueModelRows = [];
        foreach ($catalogueModels as $model) {
            $catalogueModelRows[] = [
                'model_id' => $model['model_id'],
                'model_name' => $model['model_name'],
                'manufacturer_name' => $model['manufacturer_name'] !== '' ? $model['manufacturer_name'] : null,
                'category_id' => $model['category_id'] > 0 ? $model['category_id'] : null,
                'category_name' => $model['category_name'] !== '' ? $model['category_name'] : null,
                'image_path' => $model['image_path'] !== '' ? $model['image_path'] : null,
                'notes_text' => $model['notes_text'] !== '' ? $model['notes_text'] : null,
                'total_asset_count' => $model['total_asset_count'],
                'requestable_asset_count' => $model['requestable_asset_count'],
                'raw_payload' => $model['raw_payload'],
                'updated_at' => $syncTimestamp,
            ];
        }
        $bulkInsertRows($catalogueModelStageTable, [
            'model_id',
            'model_name',
            'manufacturer_name',
            'category_id',
            'category_name',
            'image_path',
            'notes_text',
            'total_asset_count',
            'requestable_asset_count',
            'raw_payload',
            'updated_at',
        ], $catalogueModelRows);

        $catalogueAssetRows = [];
        foreach ($catalogueAssets as $asset) {
            $catalogueAssetRows[] = [
                'asset_id' => $asset['asset_id'],
                'model_id' => $asset['model_id'],
                'model_name' => $asset['model_name'],
                'asset_tag' => $asset['asset_tag'],
                'asset_name' => $asset['asset_name'],
                'requestable' => $asset['requestable'],
                'status_label' => $asset['status_label'] !== '' ? $asset['status_label'] : null,
                'assigned_to_id' => $asset['assigned_to_id'],
                'assigned_to_name' => $asset['assigned_to_name'],
                'assigned_to_email' => $asset['assigned_to_email'],
                'assigned_to_username' => $asset['assigned_to_username'],
                'default_location_name' => $asset['default_location_name'] !== '' ? $asset['default_location_name'] : null,
                'raw_payload' => $asset['raw_payload'],
                'updated_at' => $syncTimestamp,
            ];
        }
        $bulkInsertRows($catalogueAssetStageTable, [
            'asset_id',
            'model_id',
            'model_name',
            'asset_tag',
            'asset_name',
            'requestable',
            'status_label',
            'assigned_to_id',
            'assigned_to_name',
            'assigned_to_email',
            'assigned_to_username',
            'default_location_name',
            'raw_payload',
            'updated_at',
        ], $catalogueAssetRows);
    }

    if ($accessoryCacheEnabled) {
        $catalogueAccessoryRows = [];
        foreach ($catalogueAccessories as $accessory) {
            $catalogueAccessoryRows[] = [
                'accessory_id' => $accessory['accessory_id'],
                'accessory_name' => $accessory['accessory_name'],
                'manufacturer_name' => $accessory['manufacturer_name'] !== '' ? $accessory['manufacturer_name'] : null,
                'category_id' => $accessory['category_id'] > 0 ? $accessory['category_id'] : null,
                'category_name' => $accessory['category_name'] !== '' ? $accessory['category_name'] : null,
                'image_path' => $accessory['image_path'] !== '' ? $accessory['image_path'] : null,
                'notes_text' => $accessory['notes_text'] !== '' ? $accessory['notes_text'] : null,
                'total_quantity' => $accessory['total_quantity'],
                'available_quantity' => $accessory['available_quantity'],
                'raw_payload' => $accessory['raw_payload'],
                'updated_at' => $syncTimestamp,
            ];
        }
        $bulkInsertRows($catalogueAccessoryStageTable, [
            'accessory_id',
            'accessory_name',
            'manufacturer_name',
            'category_id',
            'category_name',
            'image_path',
            'notes_text',
            'total_quantity',
            'available_quantity',
            'raw_payload',
            'updated_at',
        ], $catalogueAccessoryRows);
    }

    if ($kitCacheEnabled) {
        $catalogueKitRows = [];
        foreach ($catalogueKits as $kit) {
            $catalogueKitRows[] = [
                'kit_id' => $kit['kit_id'],
                'kit_name' => $kit['kit_name'],
                'image_path' => $kit['image_path'] !== '' ? $kit['image_path'] : null,
                'notes_text' => $kit['notes_text'] !== '' ? $kit['notes_text'] : null,
                'raw_payload' => $kit['raw_payload'],
                'updated_at' => $syncTimestamp,
            ];
        }
        $bulkInsertRows($catalogueKitStageTable, [
            'kit_id',
            'kit_name',
            'image_path',
            'notes_text',
            'raw_payload',
            'updated_at',
        ], $catalogueKitRows);

        $catalogueKitItemRows = [];
        foreach ($catalogueKitItems as $item) {
            $catalogueKitItemRows[] = [
                'kit_id' => $item['kit_id'],
                'item_type' => $item['item_type'],
                'item_id' => $item['item_id'],
                'item_name' => $item['item_name'],
                'quantity' => $item['quantity'],
                'raw_payload' => $item['raw_payload'],
                'updated_at' => $syncTimestamp,
            ];
        }
        $bulkInsertRows($catalogueKitItemStageTable, [
            'kit_id',
            'item_type',
            'item_id',
            'item_name',
            'quantity',
            'raw_payload',
            'updated_at',
        ], $catalogueKitItemRows);
    }

    $swapPairs = [
        ['live' => $checkedOutLiveTable, 'stage' => $checkedOutStageTable],
    ];
    if ($catalogueCacheEnabled) {
        $swapPairs[] = ['live' => $catalogueModelLiveTable, 'stage' => $catalogueModelStageTable];
        $swapPairs[] = ['live' => $catalogueAssetLiveTable, 'stage' => $catalogueAssetStageTable];
    }
    if ($accessoryCacheEnabled) {
        $swapPairs[] = ['live' => $catalogueAccessoryLiveTable, 'stage' => $catalogueAccessoryStageTable];
    }
    if ($kitCacheEnabled) {
        $swapPairs[] = ['live' => $catalogueKitLiveTable, 'stage' => $catalogueKitStageTable];
        $swapPairs[] = ['live' => $catalogueKitItemLiveTable, 'stage' => $catalogueKitItemStageTable];
    }
    $swapTables($swapPairs);

    $writeCacheMeta = static function (string $cacheKey, int $modelCount, int $assetCount, int $checkedOutCount) use ($pdo): void {
        $metaStmt = $pdo->prepare("
            INSERT INTO catalogue_cache_meta (
                cache_key,
                synced_at,
                model_count,
                asset_count,
                checked_out_count
            ) VALUES (
                :cache_key,
                NOW(),
                :model_count,
                :asset_count,
                :checked_out_count
            )
            ON DUPLICATE KEY UPDATE
                synced_at = VALUES(synced_at),
                model_count = VALUES(model_count),
                asset_count = VALUES(asset_count),
                checked_out_count = VALUES(checked_out_count)
        ");
        $metaStmt->execute([
            ':cache_key' => $cacheKey,
            ':model_count' => $modelCount,
            ':asset_count' => $assetCount,
            ':checked_out_count' => $checkedOutCount,
        ]);
    };

    if ($catalogueCacheEnabled) {
        $writeCacheMeta('catalogue', count($catalogueModels), count($catalogueAssets), count($checkedOutAssets));
    }
    if ($accessoryCacheEnabled) {
        $writeCacheMeta('catalogue_accessories', 0, count($catalogueAccessories), 0);
    }
    if ($kitCacheEnabled) {
        $writeCacheMeta('catalogue_kits', count($catalogueKits), count($catalogueKitItems), 0);
    }

    if ($catalogueCacheEnabled) {
        $logOut(
            'done',
            'Synced '
            . count($checkedOutAssets) . ' checked-out asset(s), '
            . count($catalogueModels) . ' catalogue model(s), and '
            . count($catalogueAssets) . ' catalogue asset(s)'
            . ($accessoryCacheEnabled ? (', ' . count($catalogueAccessories) . ' catalogue accessory item(s)') : '')
            . ($kitCacheEnabled ? (', ' . count($catalogueKits) . ' catalogue kit(s), and ' . count($catalogueKitItems) . ' kit item(s)') : '')
            . '.'
        );
    } else {
        $logOut('done', 'Synced ' . count($checkedOutAssets) . ' checked-out asset(s).');
    }
} catch (Throwable $e) {
    $logErr('Failed to sync caches: ' . $e->getMessage());
    exit(1);
}
