<?php
// scripts/snipeit_asset_cache_update.php
// Sync checked-out assets and the requestable catalogue from Snipe-IT
// into the local cache tables.
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

if ($catalogueCacheEnabled) {
    try {
        $allModels = fetch_all_models_from_snipeit();
    } catch (Throwable $e) {
        $logErr('Failed to load models: ' . $e->getMessage());
        exit(1);
    }
} else {
    $allModels = [];
}

try {
    $allHardware = fetch_all_hardware_from_snipeit(0);
} catch (Throwable $e) {
    $logErr('Failed to load hardware assets: ' . $e->getMessage());
    exit(1);
}

$catalogueModels = [];
foreach ($allModels as $model) {
    if (empty($model['requestable'])) {
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
        'category_id' => isset($model['category']['id']) ? (int)$model['category']['id'] : 0,
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

try {
    if (!$pdo->beginTransaction()) {
        throw new RuntimeException('Could not start database transaction.');
    }

    $pdo->exec('DELETE FROM checked_out_asset_cache');
    if ($catalogueCacheEnabled) {
        $pdo->exec('DELETE FROM catalogue_model_cache');
        $pdo->exec('DELETE FROM catalogue_asset_cache');
    }

    $checkedOutStmt = $pdo->prepare("
        INSERT INTO checked_out_asset_cache (
            asset_id,
            asset_tag,
            asset_name,
            model_id,
            model_name,
            assigned_to_id,
            assigned_to_name,
            assigned_to_email,
            assigned_to_username,
            status_label,
            last_checkout,
            expected_checkin,
            updated_at
        ) VALUES (
            :asset_id,
            :asset_tag,
            :asset_name,
            :model_id,
            :model_name,
            :assigned_to_id,
            :assigned_to_name,
            :assigned_to_email,
            :assigned_to_username,
            :status_label,
            :last_checkout,
            :expected_checkin,
            NOW()
        )
    ");

    foreach ($checkedOutAssets as $asset) {
        $checkedOutStmt->execute([
            ':asset_id' => $asset['asset_id'],
            ':asset_tag' => $asset['asset_tag'],
            ':asset_name' => $asset['asset_name'],
            ':model_id' => $asset['model_id'],
            ':model_name' => $asset['model_name'],
            ':assigned_to_id' => $asset['assigned_to_id'],
            ':assigned_to_name' => $asset['assigned_to_name'],
            ':assigned_to_email' => $asset['assigned_to_email'],
            ':assigned_to_username' => $asset['assigned_to_username'],
            ':status_label' => $asset['status_label'],
            ':last_checkout' => $asset['last_checkout'],
            ':expected_checkin' => $asset['expected_checkin'],
        ]);
    }

    if ($catalogueCacheEnabled) {
        $catalogueModelStmt = $pdo->prepare("
            INSERT INTO catalogue_model_cache (
                model_id,
                model_name,
                manufacturer_name,
                category_id,
                category_name,
                image_path,
                notes_text,
                total_asset_count,
                requestable_asset_count,
                raw_payload,
                updated_at
            ) VALUES (
                :model_id,
                :model_name,
                :manufacturer_name,
                :category_id,
                :category_name,
                :image_path,
                :notes_text,
                :total_asset_count,
                :requestable_asset_count,
                :raw_payload,
                NOW()
            )
        ");

        foreach ($catalogueModels as $model) {
            $catalogueModelStmt->execute([
                ':model_id' => $model['model_id'],
                ':model_name' => $model['model_name'],
                ':manufacturer_name' => $model['manufacturer_name'] !== '' ? $model['manufacturer_name'] : null,
                ':category_id' => $model['category_id'] > 0 ? $model['category_id'] : null,
                ':category_name' => $model['category_name'] !== '' ? $model['category_name'] : null,
                ':image_path' => $model['image_path'] !== '' ? $model['image_path'] : null,
                ':notes_text' => $model['notes_text'] !== '' ? $model['notes_text'] : null,
                ':total_asset_count' => $model['total_asset_count'],
                ':requestable_asset_count' => $model['requestable_asset_count'],
                ':raw_payload' => $model['raw_payload'],
            ]);
        }

        $catalogueAssetStmt = $pdo->prepare("
            INSERT INTO catalogue_asset_cache (
                asset_id,
                model_id,
                model_name,
                asset_tag,
                asset_name,
                requestable,
                status_label,
                assigned_to_id,
                assigned_to_name,
                assigned_to_email,
                assigned_to_username,
                default_location_name,
                raw_payload,
                updated_at
            ) VALUES (
                :asset_id,
                :model_id,
                :model_name,
                :asset_tag,
                :asset_name,
                :requestable,
                :status_label,
                :assigned_to_id,
                :assigned_to_name,
                :assigned_to_email,
                :assigned_to_username,
                :default_location_name,
                :raw_payload,
                NOW()
            )
        ");

        foreach ($catalogueAssets as $asset) {
            $catalogueAssetStmt->execute([
                ':asset_id' => $asset['asset_id'],
                ':model_id' => $asset['model_id'],
                ':model_name' => $asset['model_name'],
                ':asset_tag' => $asset['asset_tag'],
                ':asset_name' => $asset['asset_name'],
                ':requestable' => $asset['requestable'],
                ':status_label' => $asset['status_label'] !== '' ? $asset['status_label'] : null,
                ':assigned_to_id' => $asset['assigned_to_id'],
                ':assigned_to_name' => $asset['assigned_to_name'],
                ':assigned_to_email' => $asset['assigned_to_email'],
                ':assigned_to_username' => $asset['assigned_to_username'],
                ':default_location_name' => $asset['default_location_name'] !== '' ? $asset['default_location_name'] : null,
                ':raw_payload' => $asset['raw_payload'],
            ]);
        }

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
            ':cache_key' => 'catalogue',
            ':model_count' => count($catalogueModels),
            ':asset_count' => count($catalogueAssets),
            ':checked_out_count' => count($checkedOutAssets),
        ]);
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    if ($catalogueCacheEnabled) {
        $logOut(
            'done',
            'Synced '
            . count($checkedOutAssets) . ' checked-out asset(s), '
            . count($catalogueModels) . ' catalogue model(s), and '
            . count($catalogueAssets) . ' catalogue asset(s).'
        );
    } else {
        $logOut('done', 'Synced ' . count($checkedOutAssets) . ' checked-out asset(s).');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $logErr('Failed to sync caches: ' . $e->getMessage());
    exit(1);
}
