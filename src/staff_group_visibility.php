<?php

require_once __DIR__ . '/bootstrap.php';
require_once SRC_PATH . '/catalogue_permissions.php';

function staff_group_visibility_restriction_enabled(array $config, array $currentUser): bool
{
    $catalogueCfg = is_array($config['catalogue'] ?? null) ? $config['catalogue'] : [];

    return empty($currentUser['is_admin'])
        && !empty($catalogueCfg['restrict_checkout_reservations_to_same_group']);
}

function staff_group_visibility_group_ids_for_user(array $user): array
{
    static $cache = [];

    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '') {
        return [];
    }
    if (array_key_exists($email, $cache)) {
        return $cache[$email];
    }

    $cachedGroupIds = staff_group_visibility_cached_group_ids_for_email($email);
    if ($cachedGroupIds !== null) {
        $cache[$email] = $cachedGroupIds;
        return $cache[$email];
    }

    $sessionKey = 'staff_group_visibility_group_ids_' . sha1($email);
    if (
        session_status() === PHP_SESSION_ACTIVE
        && isset($_SESSION[$sessionKey])
        && is_array($_SESSION[$sessionKey])
        && (int)($_SESSION[$sessionKey]['expires_at'] ?? 0) > time()
        && isset($_SESSION[$sessionKey]['group_ids'])
        && is_array($_SESSION[$sessionKey]['group_ids'])
    ) {
        $cache[$email] = array_values(array_unique(array_map('intval', $_SESSION[$sessionKey]['group_ids'])));
        return $cache[$email];
    }

    $groupIds = catalogue_permissions_user_group_ids($user);
    if (empty($groupIds)) {
        try {
            foreach (catalogue_permissions_fetch_snipeit_groups() as $group) {
                $groupId = (int)($group['id'] ?? 0);
                if ($groupId > 0 && catalogue_permissions_group_contains_user_email($groupId, $email)) {
                    $groupIds[$groupId] = $groupId;
                }
            }
        } catch (Throwable $e) {
            $groupIds = [];
        }
    }

    $cache[$email] = array_values(array_unique(array_map('intval', $groupIds)));
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[$sessionKey] = [
            'expires_at' => time() + 3600,
            'group_ids' => $cache[$email],
        ];
    }
    return $cache[$email];
}

function staff_group_visibility_cached_group_ids_for_email(string $email): ?array
{
    static $tableAvailable = null;
    static $cache = [];

    $email = strtolower(trim($email));
    if ($email === '') {
        return [];
    }
    if (array_key_exists($email, $cache)) {
        return $cache[$email];
    }

    if ($tableAvailable === false) {
        return null;
    }

    try {
        $pdo = catalogue_permissions_pdo();
        if ($tableAvailable === null) {
            $pdo->query('SELECT 1 FROM snipeit_user_group_cache LIMIT 1');
            $tableAvailable = true;
        }

        $stmt = $pdo->prepare("
            SELECT group_id
              FROM snipeit_user_group_cache
             WHERE user_email = :email
        ");
        $stmt->execute([':email' => $email]);

        $groupIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $groupId) {
            $groupId = (int)$groupId;
            if ($groupId > 0) {
                $groupIds[$groupId] = $groupId;
            }
        }

        $cache[$email] = array_values($groupIds);
        return $cache[$email];
    } catch (Throwable $e) {
        $tableAvailable = false;
        return null;
    }
}

function staff_group_visibility_cached_visible_emails_for_current_user(array $currentUser, bool $restrictionEnabled): ?array
{
    static $tableAvailable = null;
    static $cache = [];

    if (!$restrictionEnabled) {
        return null;
    }

    $currentEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
    if ($currentEmail === '') {
        return [];
    }

    $currentGroupIds = staff_group_visibility_group_ids_for_user($currentUser);
    if (empty($currentGroupIds)) {
        return [];
    }

    sort($currentGroupIds);
    $cacheKey = $currentEmail . '|' . implode(',', $currentGroupIds);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if ($tableAvailable === false) {
        return null;
    }

    try {
        $pdo = catalogue_permissions_pdo();
        if ($tableAvailable === null) {
            $pdo->query('SELECT 1 FROM snipeit_user_group_cache LIMIT 1');
            $tableAvailable = true;
        }

        $placeholders = implode(',', array_fill(0, count($currentGroupIds), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT user_email
              FROM snipeit_user_group_cache
             WHERE group_id IN ({$placeholders})
        ");
        $stmt->execute($currentGroupIds);

        $emails = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $email) {
            $email = strtolower(trim((string)$email));
            if ($email !== '') {
                $emails[$email] = $email;
            }
        }
        $emails[$currentEmail] = $currentEmail;

        $cache[$cacheKey] = array_values($emails);
        sort($cache[$cacheKey]);
        return $cache[$cacheKey];
    } catch (Throwable $e) {
        $tableAvailable = false;
        return null;
    }
}

function staff_group_visibility_user_can_see_email(array $currentUser, string $targetEmail, bool $restrictionEnabled): bool
{
    static $currentUserGroupCache = [];
    static $targetGroupCache = [];
    static $decisionCache = [];

    if (!$restrictionEnabled) {
        return true;
    }

    $currentEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
    $targetEmail = strtolower(trim($targetEmail));
    if ($currentEmail === '' || $targetEmail === '') {
        return false;
    }

    $cachedVisibleEmails = staff_group_visibility_cached_visible_emails_for_current_user($currentUser, $restrictionEnabled);
    if (is_array($cachedVisibleEmails)) {
        return in_array($targetEmail, $cachedVisibleEmails, true);
    }

    $decisionKey = $currentEmail . '|' . $targetEmail;
    if (array_key_exists($decisionKey, $decisionCache)) {
        return $decisionCache[$decisionKey];
    }

    $sessionDecisionKey = 'staff_group_visibility_decision_' . sha1($decisionKey);
    if (
        session_status() === PHP_SESSION_ACTIVE
        && isset($_SESSION[$sessionDecisionKey])
        && is_array($_SESSION[$sessionDecisionKey])
        && (int)($_SESSION[$sessionDecisionKey]['expires_at'] ?? 0) > time()
        && array_key_exists('allowed', $_SESSION[$sessionDecisionKey])
    ) {
        $decisionCache[$decisionKey] = !empty($_SESSION[$sessionDecisionKey]['allowed']);
        return $decisionCache[$decisionKey];
    }

    $visibleEmails = staff_group_visibility_visible_user_emails_for_current_user($currentUser, $restrictionEnabled);
    if (is_array($visibleEmails) && in_array($targetEmail, $visibleEmails, true)) {
        $decisionCache[$decisionKey] = true;
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[$sessionDecisionKey] = [
                'expires_at' => time() + 3600,
                'allowed' => true,
            ];
        }
        return $decisionCache[$decisionKey];
    }

    if (!array_key_exists($currentEmail, $currentUserGroupCache)) {
        $currentUserGroupCache[$currentEmail] = staff_group_visibility_group_ids_for_user($currentUser);
    }
    if (empty($currentUserGroupCache[$currentEmail])) {
        $decisionCache[$decisionKey] = false;
        return $decisionCache[$decisionKey];
    }

    if (!array_key_exists($targetEmail, $targetGroupCache)) {
        $targetGroupCache[$targetEmail] = staff_group_visibility_group_ids_for_user([
            'email' => $targetEmail,
        ]);
    }

    $decisionCache[$decisionKey] = !empty(array_intersect($currentUserGroupCache[$currentEmail], $targetGroupCache[$targetEmail]));
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[$sessionDecisionKey] = [
            'expires_at' => time() + 3600,
            'allowed' => $decisionCache[$decisionKey],
        ];
    }

    return $decisionCache[$decisionKey];
}

function staff_group_visibility_visible_user_emails_for_current_user(array $currentUser, bool $restrictionEnabled): ?array
{
    static $requestCache = [];

    if (!$restrictionEnabled) {
        return null;
    }

    $currentEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
    if ($currentEmail === '') {
        return [];
    }

    $groupIds = staff_group_visibility_group_ids_for_user($currentUser);
    if (empty($groupIds)) {
        return [];
    }

    sort($groupIds);
    $cacheKey = $currentEmail . '|' . implode(',', $groupIds);
    if (array_key_exists($cacheKey, $requestCache)) {
        return $requestCache[$cacheKey];
    }

    $sessionKey = 'staff_group_visibility_emails_' . sha1($cacheKey);
    if (
        session_status() === PHP_SESSION_ACTIVE
        && isset($_SESSION[$sessionKey])
        && is_array($_SESSION[$sessionKey])
        && (int)($_SESSION[$sessionKey]['expires_at'] ?? 0) > time()
        && isset($_SESSION[$sessionKey]['emails'])
        && is_array($_SESSION[$sessionKey]['emails'])
    ) {
        $requestCache[$cacheKey] = $_SESSION[$sessionKey]['emails'];
        return $requestCache[$cacheKey];
    }

    $emails = [];
    $hadFetchError = false;
    $limit = 500;

    foreach ($groupIds as $groupId) {
        $offset = 0;
        do {
            try {
                $data = snipeit_request('GET', 'users', [
                    'group_id' => (int)$groupId,
                    'limit' => $limit,
                    'offset' => $offset,
                ]);
            } catch (Throwable $e) {
                $hadFetchError = true;
                break;
            }

            $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $email = strtolower(trim((string)($row['email'] ?? '')));
                if ($email !== '') {
                    $emails[$email] = $email;
                }
            }

            if (count($rows) < $limit) {
                break;
            }
            $offset += $limit;
        } while (true);

        if ($hadFetchError) {
            break;
        }
    }

    if ($hadFetchError) {
        $requestCache[$cacheKey] = null;
        return null;
    }

    $emails[$currentEmail] = $currentEmail;
    $emailList = array_values($emails);
    sort($emailList);

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION[$sessionKey] = [
            'expires_at' => time() + 300,
            'emails' => $emailList,
        ];
    }

    $requestCache[$cacheKey] = $emailList;
    return $emailList;
}

function staff_group_visibility_email_is_in_visible_list(string $email, array $visibleEmails): bool
{
    return in_array(strtolower(trim($email)), $visibleEmails, true);
}

function staff_group_visibility_reservation_visible(array $reservation, array $currentUser, bool $restrictionEnabled): bool
{
    return staff_group_visibility_user_can_see_email(
        $currentUser,
        (string)($reservation['user_email'] ?? ''),
        $restrictionEnabled
    );
}

function staff_group_visibility_checked_out_row_visible(array $row, array $currentUser, bool $restrictionEnabled): bool
{
    return staff_group_visibility_user_can_see_email(
        $currentUser,
        staff_group_visibility_checked_out_row_email($row),
        $restrictionEnabled
    );
}

function staff_group_visibility_checked_out_row_email(array $row): string
{
    $assigned = $row['assigned_to'] ?? ($row['assigned_user'] ?? ($row['user'] ?? ($row['target'] ?? null)));
    if (is_array($assigned)) {
        $email = trim((string)($assigned['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }
    }

    foreach (['assigned_to_email', 'assigned_user_email', 'user_email', 'target_email', 'email'] as $key) {
        $email = trim((string)($row[$key] ?? ''));
        if ($email !== '') {
            return $email;
        }
    }

    return '';
}
