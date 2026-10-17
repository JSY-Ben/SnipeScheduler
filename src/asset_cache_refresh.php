<?php

declare(strict_types=1);

/** Coalesce successful mutations into one detached CLI sync per request. */
function asset_cache_refresh_after_response(): void
{
    static $scheduled = false;
    if ($scheduled) {
        return;
    }
    $scheduled = true;

    register_shutdown_function(static function (): void {
        try {
            if (!function_exists('exec') || PHP_OS_FAMILY === 'Windows') {
                throw new RuntimeException('Background cache refresh requires exec and a Unix PHP host.');
            }
            $config = load_config();
            $configuredBinary = trim((string)($config['app']['cache_refresh_php_binary'] ?? ''));
            $candidates = $configuredBinary !== '' ? [$configuredBinary] : [
                PHP_SAPI === 'cli' ? PHP_BINARY : '',
                PHP_BINDIR . '/php',
                '/usr/bin/php',
                '/usr/local/bin/php',
            ];
            $php = null;
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
                    $php = $candidate;
                    break;
                }
            }
            if ($php === null) {
                throw new RuntimeException('PHP CLI not found; set app.cache_refresh_php_binary in config.php.');
            }
            $script = dirname(__DIR__) . '/scripts/snipeit_asset_cache_update.php';
            $logPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
                . '/snipescheduler-cache-refresh-' . substr(sha1(dirname(__DIR__)), 0, 12) . '.log';
            // All standard streams are redirected, so PHP never waits for the sync.
            // Waiting for the cron lock happens exclusively in the child process.
            $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
                . ' --wait-for-lock >> ' . escapeshellarg($logPath) . ' 2>&1 < /dev/null &';
            exec($command, $output, $exitCode);
            if ($exitCode !== 0) {
                throw new RuntimeException('Could not launch background cache refresh.');
            }
        } catch (Throwable $e) {
            // Never fall back to a full inventory sync inside the web request.
            error_log('[inventory-cache] ' . $e->getMessage() . ' Scheduled cron remains available.');
        }
    });
}
