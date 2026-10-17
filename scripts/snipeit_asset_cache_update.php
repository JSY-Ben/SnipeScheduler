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

require_once __DIR__ . '/../src/cron_lock.php';
$cronLock = cron_acquire_lock('snipeit-asset-cache-update');

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';

require_once SRC_PATH . '/asset_cache_sync.php';

$ok = asset_cache_sync(static function (string $level, string $message): void {
    $stream = $level === 'error' ? STDERR : STDOUT;
    fwrite($stream, '[' . date(DATE_ATOM) . '] [' . $level . '] ' . $message . PHP_EOL);
});
exit($ok ? 0 : 1);
