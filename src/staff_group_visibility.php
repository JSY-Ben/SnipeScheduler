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
    return $cache[$email];
}

function staff_group_visibility_user_can_see_email(array $currentUser, string $targetEmail, bool $restrictionEnabled): bool
{
    static $currentUserGroupCache = [];
    static $targetGroupCache = [];

    if (!$restrictionEnabled) {
        return true;
    }

    $currentEmail = strtolower(trim((string)($currentUser['email'] ?? '')));
    $targetEmail = strtolower(trim($targetEmail));
    if ($currentEmail === '' || $targetEmail === '') {
        return false;
    }

    if (!array_key_exists($currentEmail, $currentUserGroupCache)) {
        $currentUserGroupCache[$currentEmail] = staff_group_visibility_group_ids_for_user($currentUser);
    }
    if (empty($currentUserGroupCache[$currentEmail])) {
        return false;
    }

    if (!array_key_exists($targetEmail, $targetGroupCache)) {
        $targetGroupCache[$targetEmail] = staff_group_visibility_group_ids_for_user([
            'email' => $targetEmail,
        ]);
    }

    return !empty(array_intersect($currentUserGroupCache[$currentEmail], $targetGroupCache[$targetEmail]));
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
