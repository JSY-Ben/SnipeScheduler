<?php

declare(strict_types=1);

/** Coalesce all successful inventory mutations in a request into one sync. */
function asset_cache_refresh_after_response(): void
{
    static $scheduled = false;
    if ($scheduled) {
        return;
    }
    $scheduled = true;

    register_shutdown_function(static function (): void {
        ignore_user_abort(true);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        $lock = null;
        try {
            // Wait for an existing cron/request sync, then fetch a new snapshot.
            // Skipping a busy lock could miss mutations made during that sync.
            @set_time_limit(0);
            $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'snipescheduler-snipeit-asset-cache-update.lock';
            $lock = fopen($lockPath, 'c');
            if ($lock === false || !flock($lock, LOCK_EX)) {
                throw new RuntimeException('Could not acquire inventory cache sync lock.');
            }
            require_once __DIR__ . '/asset_cache_sync.php';
            asset_cache_sync(static function (string $level, string $message): void {
                error_log('[inventory-cache] [' . $level . '] ' . $message);
            });
        } catch (Throwable $e) {
            // The Snipe-IT mutation already succeeded; keep its response intact.
            error_log('[inventory-cache] Refresh failed: ' . $e->getMessage());
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    });
}
