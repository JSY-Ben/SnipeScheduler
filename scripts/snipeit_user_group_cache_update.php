<?php
// scripts/snipeit_user_group_cache_update.php
// Sync Snipe-IT user group memberships into the local booking database.
//
// CLI only; intended for cron.
//
// Example cron:
// */15 * * * * /usr/bin/php /path/to/scripts/snipeit_user_group_cache_update.php >> /var/log/snipe_user_group_sync.log 2>&1

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/catalogue_permissions.php';
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

function user_group_cache_ensure_tables(PDO $pdo): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS snipeit_user_group_cache (
            user_email VARCHAR(255) NOT NULL,
            snipeit_user_id INT UNSIGNED NOT NULL DEFAULT 0,
            user_name VARCHAR(255) NOT NULL DEFAULT '',
            group_id INT UNSIGNED NOT NULL,
            group_name VARCHAR(255) NOT NULL DEFAULT '',
            synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (user_email, group_id),
            KEY idx_snipeit_user_group_cache_group (group_id),
            KEY idx_snipeit_user_group_cache_synced (synced_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $pdo->exec($sql);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS snipeit_user_group_cache_build
        LIKE snipeit_user_group_cache
    ");
}

function user_group_cache_extract_groups(array $user): array
{
    $groups = [];

    foreach (['groups', 'user_groups'] as $field) {
        if (!array_key_exists($field, $user)) {
            continue;
        }

        $value = $user[$field];
        $rows = is_array($value) && isset($value['rows']) && is_array($value['rows'])
            ? $value['rows']
            : (is_array($value) ? $value : []);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $groupId = (int)($row['id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }
            $groups[$groupId] = [
                'id' => $groupId,
                'name' => trim((string)($row['name'] ?? ($row['label'] ?? ''))),
            ];
        }

        foreach (catalogue_permissions_extract_group_ids_from_value($value) as $groupId) {
            $groupId = (int)$groupId;
            if ($groupId > 0 && !isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'id' => $groupId,
                    'name' => '',
                ];
            }
        }
    }

    return array_values($groups);
}

function user_group_cache_fetch_user_detail_groups(int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    try {
        $detail = snipeit_request('GET', 'users/' . $userId, [], false);
        return user_group_cache_extract_groups($detail);
    } catch (Throwable $e) {
        return [];
    }
}

$logOut('info', 'Snipe-IT user group cache sync started');

try {
    user_group_cache_ensure_tables($pdo);
    $pdo->exec('TRUNCATE TABLE snipeit_user_group_cache_build');
} catch (Throwable $e) {
    $logErr('Could not prepare user group cache table: ' . $e->getMessage());
    exit(1);
}

$insert = $pdo->prepare("
    INSERT INTO snipeit_user_group_cache_build (
        user_email,
        snipeit_user_id,
        user_name,
        group_id,
        group_name,
        synced_at
    ) VALUES (
        :user_email,
        :snipeit_user_id,
        :user_name,
        :group_id,
        :group_name,
        NOW()
    )
    ON DUPLICATE KEY UPDATE
        snipeit_user_id = VALUES(snipeit_user_id),
        user_name = VALUES(user_name),
        group_name = VALUES(group_name),
        synced_at = VALUES(synced_at)
");

$limit = 500;
$offset = 0;
$usersSeen = 0;
$membershipsWritten = 0;
$detailsFetched = 0;

try {
    do {
        $data = snipeit_request('GET', 'users', [
            'limit' => $limit,
            'offset' => $offset,
        ], false);

        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        foreach ($rows as $user) {
            if (!is_array($user)) {
                continue;
            }

            $usersSeen++;
            $userId = (int)($user['id'] ?? 0);
            $email = strtolower(trim((string)($user['email'] ?? '')));
            if ($email === '') {
                continue;
            }
            $name = trim((string)($user['name'] ?? ($user['username'] ?? '')));

            $groups = user_group_cache_extract_groups($user);
            if (empty($groups) && $userId > 0) {
                $groups = user_group_cache_fetch_user_detail_groups($userId);
                $detailsFetched++;
            }

            foreach ($groups as $group) {
                $groupId = (int)($group['id'] ?? 0);
                if ($groupId <= 0) {
                    continue;
                }
                $insert->execute([
                    ':user_email' => $email,
                    ':snipeit_user_id' => $userId,
                    ':user_name' => $name,
                    ':group_id' => $groupId,
                    ':group_name' => trim((string)($group['name'] ?? '')),
                ]);
                $membershipsWritten++;
            }
        }

        if (count($rows) < $limit) {
            break;
        }
        $offset += $limit;
    } while (true);

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM snipeit_user_group_cache');
    $pdo->exec('
        INSERT INTO snipeit_user_group_cache (
            user_email,
            snipeit_user_id,
            user_name,
            group_id,
            group_name,
            synced_at
        )
        SELECT
            user_email,
            snipeit_user_id,
            user_name,
            group_id,
            group_name,
            synced_at
          FROM snipeit_user_group_cache_build
    ');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $logErr('User group cache sync failed: ' . $e->getMessage());
    exit(1);
}

$logOut(
    'info',
    'Snipe-IT user group cache sync complete: users=' . $usersSeen
    . ', memberships=' . $membershipsWritten
    . ', detail_fetches=' . $detailsFetched
);
