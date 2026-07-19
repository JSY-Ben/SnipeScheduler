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

require_once __DIR__ . '/../src/cron_lock.php';
$cronLock = cron_acquire_lock('snipeit-user-group-cache-update');

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

function user_avatar_cache_normalize_url(string $value, string $baseUrl): string
{
    $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($value === '') {
        return '';
    }

    if (str_starts_with($value, '//')) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $value = $scheme . ':' . $value;
    } elseif (!preg_match('#^https?://#i', $value)) {
        if ($baseUrl === '') {
            return '';
        }
        $value = rtrim($baseUrl, '/') . '/' . ltrim($value, '/');
    }

    $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $value : '';
}

function user_avatar_cache_remove_mapping(string $cacheDir, string $cacheKey): void
{
    $mappingPath = $cacheDir . '/' . $cacheKey . '.json';
    if (is_file($mappingPath) && !@unlink($mappingPath)) {
        throw new RuntimeException('Could not remove avatar cache mapping: ' . $mappingPath);
    }
}

function user_avatar_cache_publish_mapping(string $cacheDir, string $cacheKey, string $blob): void
{
    if (!preg_match('/^[a-f0-9]{64}\.(?:jpg|png|gif|webp)$/', $blob)) {
        throw new RuntimeException('Invalid avatar blob filename');
    }

    $temporaryPath = tempnam($cacheDir, '.mapping-');
    $payload = json_encode(['blob' => $blob], JSON_UNESCAPED_SLASHES);
    if ($temporaryPath === false || $payload === false || file_put_contents($temporaryPath, $payload, LOCK_EX) === false) {
        throw new RuntimeException('Could not write temporary avatar mapping');
    }
    @chmod($temporaryPath, 0644);
    if (!@rename($temporaryPath, $cacheDir . '/' . $cacheKey . '.json')) {
        @unlink($temporaryPath);
        throw new RuntimeException('Could not publish avatar cache mapping');
    }
}

function user_avatar_cache_cleanup(string $cacheDir, array $localCacheKeys): int
{
    $localKeyMap = array_fill_keys(array_values($localCacheKeys), true);
    $referencedBlobs = [];
    foreach (glob($cacheDir . '/*.json') ?: [] as $mappingPath) {
        $cacheKey = basename($mappingPath, '.json');
        $mapping = json_decode((string)@file_get_contents($mappingPath), true);
        $blob = is_array($mapping) ? trim((string)($mapping['blob'] ?? '')) : '';
        if (!isset($localKeyMap[$cacheKey])
            || !preg_match('/^[a-f0-9]{64}\.(?:jpg|png|gif|webp)$/', $blob)
            || !is_file($cacheDir . '/' . $blob)) {
            @unlink($mappingPath);
            continue;
        }
        $referencedBlobs[$blob] = true;
    }

    foreach (glob($cacheDir . '/*') ?: [] as $cachedPath) {
        $basename = basename($cachedPath);
        if (preg_match('/^[a-f0-9]{64}\.(?:jpg|png|gif|webp)$/', $basename)
            && !isset($referencedBlobs[$basename])) {
            @unlink($cachedPath);
        } elseif (str_starts_with($basename, '.avatar-') || str_starts_with($basename, '.mapping-')) {
            @unlink($cachedPath);
        }
    }

    return count($referencedBlobs);
}

function user_avatar_cache_download(string $url, string $cacheDir, bool $verifySsl): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        CURLOPT_USERAGENT => 'SnipeScheduler avatar cache updater',
    ]);
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
    if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Avatar download failed: ' . $error);
    }
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = strtolower(trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE)));
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Avatar download returned HTTP ' . $httpCode);
    }
    if (!is_string($body) || $body === '' || strlen($body) > 5 * 1024 * 1024) {
        throw new RuntimeException('Avatar download was empty or exceeded 5 MB');
    }

    $mimeType = strtolower(trim((string)strtok($contentType, ';')));
    if (function_exists('getimagesizefromstring')) {
        $imageInfo = @getimagesizefromstring($body);
        if (is_array($imageInfo) && !empty($imageInfo['mime'])) {
            $mimeType = strtolower((string)$imageInfo['mime']);
        }
    }
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $extension = $extensions[$mimeType] ?? '';
    if ($extension === '') {
        throw new RuntimeException('Avatar response was not a supported image type');
    }

    $blob = hash('sha256', $body) . '.' . $extension;
    $targetPath = $cacheDir . '/' . $blob;
    if (is_file($targetPath)) {
        return $blob;
    }

    $temporaryPath = tempnam($cacheDir, '.avatar-');
    if ($temporaryPath === false || file_put_contents($temporaryPath, $body, LOCK_EX) === false) {
        throw new RuntimeException('Could not write temporary avatar cache file');
    }
    @chmod($temporaryPath, 0644);
    if (!@rename($temporaryPath, $targetPath)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Could not publish avatar cache file');
    }
    return $blob;
}

function user_avatar_cache_sync(PDO $pdo, array $config, callable $logOut, callable $logErr): array
{
    $cacheDir = APP_ROOT . '/cache/user_avatars';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
        throw new RuntimeException('Could not create avatar cache directory: ' . $cacheDir);
    }

    $localEmails = [];
    $rows = $pdo->query("
        SELECT email
          FROM users
         WHERE email IS NOT NULL AND TRIM(email) <> ''
        UNION
        SELECT user_email AS email
          FROM reservations
         WHERE user_email IS NOT NULL AND TRIM(user_email) <> ''
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $email) {
        $email = strtolower(trim((string)$email));
        if ($email !== '') {
            $localEmails[$email] = hash('sha256', $email);
        }
    }

    if ($localEmails === []) {
        $uniqueBlobs = user_avatar_cache_cleanup($cacheDir, []);
        return ['local_users' => 0, 'matched' => 0, 'downloaded' => 0, 'failed' => 0, 'unique_blobs' => $uniqueBlobs];
    }

    $snipeCfg = $config['snipeit'] ?? [];
    $baseUrl = rtrim((string)($snipeCfg['base_url'] ?? ''), '/');
    $verifySsl = !empty($snipeCfg['verify_ssl']);
    $limit = 500;
    $offset = 0;
    $matched = 0;
    $downloaded = 0;
    $failed = 0;
    $downloadedUrls = [];

    do {
        $data = snipeit_request('GET', 'users', ['limit' => $limit, 'offset' => $offset], false);
        $users = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $email = strtolower(trim((string)($user['email'] ?? '')));
            if ($email === '' || !isset($localEmails[$email])) {
                continue;
            }
            $matched++;
            $cacheKey = $localEmails[$email];
            $avatarUrl = user_avatar_cache_normalize_url((string)($user['avatar'] ?? ''), $baseUrl);
            if ($avatarUrl === '') {
                user_avatar_cache_remove_mapping($cacheDir, $cacheKey);
                continue;
            }

            try {
                if (isset($downloadedUrls[$avatarUrl])) {
                    $blob = $downloadedUrls[$avatarUrl];
                } else {
                    $blob = user_avatar_cache_download($avatarUrl, $cacheDir, $verifySsl);
                    $downloadedUrls[$avatarUrl] = $blob;
                    $downloaded++;
                }
                user_avatar_cache_publish_mapping($cacheDir, $cacheKey, $blob);
            } catch (Throwable $e) {
                $failed++;
                $logErr('Could not cache avatar for ' . $email . ': ' . $e->getMessage());
            }
        }

        if (count($users) < $limit) {
            break;
        }
        $offset += $limit;
    } while (true);

    $uniqueBlobs = user_avatar_cache_cleanup($cacheDir, $localEmails);
    return [
        'local_users' => count($localEmails),
        'matched' => $matched,
        'downloaded' => $downloaded,
        'failed' => $failed,
        'unique_blobs' => $uniqueBlobs,
    ];
}

$logOut('info', 'Snipe-IT user group cache sync started');

try {
    $avatarStats = user_avatar_cache_sync($pdo, $config, $logOut, $logErr);
    $logOut(
        'info',
        'Snipe-IT avatar cache sync complete: local_users=' . $avatarStats['local_users']
        . ', matched=' . $avatarStats['matched']
        . ', downloaded=' . $avatarStats['downloaded']
        . ', failed=' . $avatarStats['failed']
        . ', unique_image_files=' . $avatarStats['unique_blobs']
    );
} catch (Throwable $e) {
    $logErr('Avatar cache sync failed: ' . $e->getMessage());
}

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
$usersSeen = 0;
$membershipsWritten = 0;
$groupsSeen = 0;

$writeMembership = static function (array $user, int $groupId, string $groupName) use ($insert, &$usersSeen, &$membershipsWritten): void {
    $userId = (int)($user['id'] ?? 0);
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '' || $groupId <= 0) {
        return;
    }
    $name = trim((string)($user['name'] ?? ($user['username'] ?? '')));
    $insert->execute([
        ':user_email' => $email,
        ':snipeit_user_id' => $userId,
        ':user_name' => $name,
        ':group_id' => $groupId,
        ':group_name' => $groupName,
    ]);
    $usersSeen++;
    $membershipsWritten++;
};

function user_group_cache_extract_group_name_from_value($value, int $targetGroupId): string
{
    if ($targetGroupId <= 0 || !is_array($value)) {
        return '';
    }

    if (isset($value['rows']) && is_array($value['rows'])) {
        return user_group_cache_extract_group_name_from_value($value['rows'], $targetGroupId);
    }

    if (isset($value['id']) && (int)$value['id'] === $targetGroupId) {
        foreach (['name', 'label'] as $nameKey) {
            $name = trim((string)($value[$nameKey] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    }

    foreach ($value as $nested) {
        $name = user_group_cache_extract_group_name_from_value($nested, $targetGroupId);
        if ($name !== '') {
            return $name;
        }
    }

    return '';
}

$updateGroupName = $pdo->prepare("
    UPDATE snipeit_user_group_cache_build
       SET group_name = :group_name
     WHERE group_id = :group_id
");

try {
    $groups = catalogue_permissions_configured_snipeit_groups($config);
    if (empty($groups)) {
        throw new RuntimeException('No Snipe-IT group IDs are configured in catalogue.snipeit_group_ids.');
    }

    foreach ($groups as $group) {
        $groupId = (int)($group['id'] ?? 0);
        $groupName = trim((string)($group['name'] ?? ''));
        if ($groupId <= 0) {
            continue;
        }
        $groupsSeen++;
        $resolvedGroupName = $groupName;

        $offset = 0;
        do {
            $data = snipeit_request('GET', 'users', [
                'group_id' => $groupId,
                'limit' => $limit,
                'offset' => $offset,
            ], false);

            $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
            foreach ($rows as $user) {
                if (is_array($user)) {
                    $userGroupName = user_group_cache_extract_group_name_from_value($user['groups'] ?? ($user['user_groups'] ?? []), $groupId);
                    if ($userGroupName !== '') {
                        $resolvedGroupName = $userGroupName;
                    }
                    $writeMembership($user, $groupId, $resolvedGroupName);
                }
            }

            if (count($rows) < $limit) {
                break;
            }
            $offset += $limit;
        } while (true);

        if ($resolvedGroupName !== $groupName && $resolvedGroupName !== '') {
            $updateGroupName->execute([
                ':group_name' => $resolvedGroupName,
                ':group_id' => $groupId,
            ]);
        }
    }

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
    'Snipe-IT user group cache sync complete: groups=' . $groupsSeen
    . ', user_memberships_seen=' . $usersSeen
    . ', memberships=' . $membershipsWritten
);
