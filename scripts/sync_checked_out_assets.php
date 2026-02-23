<?php
// scripts/sync_checked_out_assets.php
// Sync checked-out assets from Snipe-IT into the local cache table.
//
// CLI only; intended for cron.
//
// Example cron:
// /usr/bin/php /path/to/scripts/sync_checked_out_assets.php >> /var/log/snipe_checked_out_sync.log 2>&1

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
$logOut('info', 'sync_checked_out_assets run started');

try {
    $assets = fetch_checked_out_assets_from_snipeit(false, 0);
} catch (Throwable $e) {
    $logErr('Failed to load checked-out assets: ' . $e->getMessage());
    exit(1);
}

try {
    if (!$pdo->beginTransaction()) {
        throw new RuntimeException('Could not start database transaction.');
    }
    $pdo->exec('TRUNCATE TABLE checked_out_asset_cache');

    $stmt = $pdo->prepare("
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

    $seenAssetIds = [];
    foreach ($assets as $asset) {
        $assetId = (int)($asset['id'] ?? 0);
        if ($assetId <= 0) {
            continue;
        }
        if (isset($seenAssetIds[$assetId])) {
            continue;
        }
        $seenAssetIds[$assetId] = true;

        $assetTag  = $asset['asset_tag'] ?? '';
        $assetName = $asset['name'] ?? '';
        $modelId   = (int)($asset['model']['id'] ?? 0);
        $modelName = $asset['model']['name'] ?? '';

        $assigned = $asset['assigned_to'] ?? ($asset['assigned_to_fullname'] ?? '');
        $assignedId = 0;
        $assignedName = '';
        $assignedEmail = '';
        $assignedUsername = '';
        if (is_array($assigned)) {
            $assignedId = (int)($assigned['id'] ?? 0);
            $assignedName = $assigned['name'] ?? ($assigned['username'] ?? '');
            $assignedEmail = $assigned['email'] ?? '';
            $assignedUsername = $assigned['username'] ?? '';
        } elseif (is_string($assigned)) {
            $assignedName = $assigned;
        }

        $statusLabel = $asset['status_label'] ?? '';
        if (is_array($statusLabel)) {
            $statusLabel = $statusLabel['name'] ?? ($statusLabel['status_meta'] ?? ($statusLabel['label'] ?? ''));
        }

        $lastCheckout = $asset['_last_checkout_norm'] ?? ($asset['last_checkout'] ?? '');
        if (is_array($lastCheckout)) {
            $lastCheckout = $lastCheckout['datetime'] ?? ($lastCheckout['date'] ?? '');
        }
        $expectedCheckin = $asset['_expected_checkin_norm'] ?? ($asset['expected_checkin'] ?? '');
        if (is_array($expectedCheckin)) {
            $expectedCheckin = $expectedCheckin['datetime'] ?? ($expectedCheckin['date'] ?? '');
        }

        $stmt->execute([
            ':asset_id' => $assetId,
            ':asset_tag' => $assetTag,
            ':asset_name' => $assetName,
            ':model_id' => $modelId,
            ':model_name' => $modelName,
            ':assigned_to_id' => $assignedId > 0 ? $assignedId : null,
            ':assigned_to_name' => $assignedName !== '' ? $assignedName : null,
            ':assigned_to_email' => $assignedEmail !== '' ? $assignedEmail : null,
            ':assigned_to_username' => $assignedUsername !== '' ? $assignedUsername : null,
            ':status_label' => $statusLabel !== '' ? $statusLabel : null,
            ':last_checkout' => $lastCheckout !== '' ? $lastCheckout : null,
            ':expected_checkin' => $expectedCheckin !== '' ? $expectedCheckin : null,
        ]);
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
    $logOut('done', 'Synced ' . count($assets) . ' checked-out asset(s).');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $logErr('Failed to sync checked-out assets: ' . $e->getMessage());
    exit(1);
}
