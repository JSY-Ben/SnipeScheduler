<?php

declare(strict_types=1);

/**
 * Acquire a process-wide, non-blocking lock for a scheduled task.
 *
 * The returned handle must remain referenced for the lifetime of the script.
 * A static registry and shutdown handler provide an additional safeguard.
 *
 * @return resource
 */
function cron_acquire_lock(string $jobName)
{
    static $locks = [];
    static $shutdownRegistered = false;

    $safeName = preg_replace('/[^A-Za-z0-9_.-]+/', '-', trim($jobName));
    $safeName = trim((string)$safeName, '-.');
    if ($safeName === '') {
        throw new InvalidArgumentException('Cron lock name cannot be empty.');
    }

    $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'snipescheduler-' . $safeName . '.lock';
    $handle = @fopen($lockPath, 'c');
    if ($handle === false) {
        fwrite(STDERR, "Could not open cron lock file: {$lockPath}\n");
        exit(1);
    }

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        fwrite(STDOUT, "Another {$jobName} run is already in progress; this run was skipped.\n");
        exit(0);
    }

    ftruncate($handle, 0);
    fwrite($handle, (string)getmypid() . ' ' . date(DATE_ATOM) . PHP_EOL);
    fflush($handle);
    $locks[$jobName] = $handle;

    if (!$shutdownRegistered) {
        register_shutdown_function(static function () use (&$locks): void {
            foreach ($locks as $lock) {
                if (is_resource($lock)) {
                    @flock($lock, LOCK_UN);
                    @fclose($lock);
                }
            }
            $locks = [];
        });
        $shutdownRegistered = true;
    }

    return $handle;
}
