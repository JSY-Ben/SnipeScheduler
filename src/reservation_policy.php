<?php
// reservation_policy.php
// Shared reservation policy parsing + validation helpers.

if (!function_exists('reservation_policy_parts_to_minutes')) {
    function reservation_policy_parts_to_minutes($days, $hours, $minutes): int
    {
        $d = max(0, (int)$days);
        $h = max(0, (int)$hours);
        $m = max(0, (int)$minutes);
        return ($d * 1440) + ($h * 60) + $m;
    }
}

if (!function_exists('reservation_policy_minutes_to_parts')) {
    function reservation_policy_minutes_to_parts($totalMinutes): array
    {
        $minutes = max(0, (int)$totalMinutes);
        $days = (int)floor($minutes / 1440);
        $remaining = $minutes % 1440;
        $hours = (int)floor($remaining / 60);
        $mins = $remaining % 60;

        return [
            'days' => $days,
            'hours' => $hours,
            'minutes' => $mins,
        ];
    }
}

if (!function_exists('reservation_policy_format_minutes')) {
    function reservation_policy_format_minutes($totalMinutes): string
    {
        $parts = reservation_policy_minutes_to_parts($totalMinutes);
        $labels = [];

        if ($parts['days'] > 0) {
            $labels[] = $parts['days'] . ' day' . ($parts['days'] === 1 ? '' : 's');
        }
        if ($parts['hours'] > 0) {
            $labels[] = $parts['hours'] . ' hour' . ($parts['hours'] === 1 ? '' : 's');
        }
        if ($parts['minutes'] > 0) {
            $labels[] = $parts['minutes'] . ' minute' . ($parts['minutes'] === 1 ? '' : 's');
        }

        if (empty($labels)) {
            return '0 minutes';
        }

        return implode(', ', $labels);
    }
}

if (!function_exists('reservation_policy_parse_blackout_datetime_value')) {
    function reservation_policy_parse_blackout_datetime_value(string $value, ?array $cfg = null): ?int
    {
        $text = trim($value);
        if ($text === '') {
            return null;
        }

        $cfg = $cfg ?? load_config();
        $tz = app_get_timezone($cfg);
        $dateFormat = app_get_date_format($cfg);
        $timeFormat = app_get_time_format($cfg);

        $preferredFormats = [];
        $dateTimeFormat = trim($dateFormat . ' ' . $timeFormat);
        if ($dateTimeFormat !== '') {
            $preferredFormats[] = $dateTimeFormat;
            if (strpos($timeFormat, 's') === false) {
                $preferredFormats[] = trim($dateFormat . ' ' . $timeFormat . ':s');
            }
            if (strpos($timeFormat, 'A') !== false) {
                $preferredFormats[] = trim($dateFormat . ' ' . str_replace('A', 'a', $timeFormat));
            }
            if (strpos($timeFormat, 'a') !== false) {
                $preferredFormats[] = trim($dateFormat . ' ' . str_replace('a', 'A', $timeFormat));
            }
        }

        $fallbackFormats = [
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'm/d/Y H:i:s',
            'm/d/Y H:i',
        ];
        $formats = array_values(array_unique(array_filter(array_merge($preferredFormats, $fallbackFormats), 'strlen')));

        foreach ($formats as $format) {
            $dateTime = $tz
                ? DateTime::createFromFormat('!' . $format, $text, $tz)
                : DateTime::createFromFormat('!' . $format, $text);
            if (!$dateTime) {
                continue;
            }

            $errors = DateTime::getLastErrors();
            if (
                is_array($errors)
                && (
                    (int)($errors['warning_count'] ?? 0) > 0
                    || (int)($errors['error_count'] ?? 0) > 0
                )
            ) {
                continue;
            }

            return $dateTime->getTimestamp();
        }

        $fallbackTs = strtotime($text);
        return $fallbackTs === false ? null : $fallbackTs;
    }
}

if (!function_exists('reservation_policy_parse_blackout_slots_text')) {
    function reservation_policy_parse_blackout_slots_text(string $text, ?array $cfg = null): array
    {
        $slots = [];
        $seen = [];
        $lines = preg_split('/\R/', $text) ?: [];

        foreach ($lines as $rawLine) {
            $line = trim((string)$rawLine);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }

            $parts = preg_split('/\s*(?:->|to|\|)\s*/i', $line, 2);
            if (!is_array($parts) || count($parts) !== 2) {
                $parts = preg_split('/\s*,\s*/', $line, 2);
            }
            if (!is_array($parts) || count($parts) !== 2) {
                continue;
            }

            $startTs = reservation_policy_parse_blackout_datetime_value((string)$parts[0], $cfg);
            $endTs = reservation_policy_parse_blackout_datetime_value((string)$parts[1], $cfg);
            if ($startTs === null || $endTs === null || $endTs <= $startTs) {
                continue;
            }

            $startIso = date('Y-m-d H:i:s', $startTs);
            $endIso = date('Y-m-d H:i:s', $endTs);
            $dedupeKey = $startIso . '|' . $endIso;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $slots[] = [
                'start' => $startIso,
                'end' => $endIso,
            ];
        }

        usort($slots, static function (array $a, array $b): int {
            $cmp = strcmp((string)($a['start'] ?? ''), (string)($b['start'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)($a['end'] ?? ''), (string)($b['end'] ?? ''));
        });

        return $slots;
    }
}

if (!function_exists('reservation_policy_normalize_blackout_slots')) {
    function reservation_policy_normalize_blackout_slots($raw, ?array $cfg = null): array
    {
        if (is_string($raw)) {
            return reservation_policy_parse_blackout_slots_text($raw, $cfg);
        }

        if (!is_array($raw)) {
            return [];
        }

        $slots = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $parsed = reservation_policy_parse_blackout_slots_text($entry, $cfg);
                foreach ($parsed as $slot) {
                    $key = (string)($slot['start'] ?? '') . '|' . (string)($slot['end'] ?? '');
                    if ($key === '|' || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $slots[] = $slot;
                }
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $startRaw = trim((string)($entry['start'] ?? ($entry['start_datetime'] ?? '')));
            $endRaw = trim((string)($entry['end'] ?? ($entry['end_datetime'] ?? '')));
            $startTs = reservation_policy_parse_blackout_datetime_value($startRaw, $cfg);
            $endTs = reservation_policy_parse_blackout_datetime_value($endRaw, $cfg);
            if ($startTs === null || $endTs === null || $endTs <= $startTs) {
                continue;
            }

            $startIso = date('Y-m-d H:i:s', $startTs);
            $endIso = date('Y-m-d H:i:s', $endTs);
            $key = $startIso . '|' . $endIso;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $slots[] = [
                'start' => $startIso,
                'end' => $endIso,
            ];
        }

        usort($slots, static function (array $a, array $b): int {
            $cmp = strcmp((string)($a['start'] ?? ''), (string)($b['start'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)($a['end'] ?? ''), (string)($b['end'] ?? ''));
        });

        return $slots;
    }
}

if (!function_exists('reservation_policy_blackout_slots_to_text')) {
    function reservation_policy_blackout_slots_to_text(array $slots, ?array $cfg = null): string
    {
        if (empty($slots)) {
            return '';
        }

        $lines = [];
        foreach ($slots as $slot) {
            $startRaw = (string)($slot['start'] ?? '');
            $endRaw = (string)($slot['end'] ?? '');
            $startTs = reservation_policy_parse_blackout_datetime_value($startRaw, $cfg);
            $endTs = reservation_policy_parse_blackout_datetime_value($endRaw, $cfg);
            if ($startTs === null || $endTs === null || $endTs <= $startTs) {
                continue;
            }
            $lines[] = app_format_datetime(date('Y-m-d H:i:s', $startTs), $cfg)
                . ' -> '
                . app_format_datetime(date('Y-m-d H:i:s', $endTs), $cfg);
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('reservation_policy_get')) {
    function reservation_policy_get(array $config): array
    {
        $app = is_array($config['app'] ?? null) ? $config['app'] : [];

        $noticeMinutes = isset($app['reservation_notice_minutes'])
            ? max(0, (int)$app['reservation_notice_minutes'])
            : reservation_policy_parts_to_minutes(
                $app['reservation_notice_days'] ?? 0,
                $app['reservation_notice_hours'] ?? 0,
                $app['reservation_notice_minutes_part'] ?? 0
            );

        $minDurationMinutes = isset($app['reservation_min_duration_minutes'])
            ? max(0, (int)$app['reservation_min_duration_minutes'])
            : reservation_policy_parts_to_minutes(
                $app['reservation_min_duration_days'] ?? 0,
                $app['reservation_min_duration_hours'] ?? 0,
                $app['reservation_min_duration_minutes_part'] ?? 0
            );

        $maxDurationMinutes = isset($app['reservation_max_duration_minutes'])
            ? max(0, (int)$app['reservation_max_duration_minutes'])
            : reservation_policy_parts_to_minutes(
                $app['reservation_max_duration_days'] ?? 0,
                $app['reservation_max_duration_hours'] ?? 0,
                $app['reservation_max_duration_minutes_part'] ?? 0
            );

        if ($maxDurationMinutes > 0 && $maxDurationMinutes < $minDurationMinutes) {
            $maxDurationMinutes = $minDurationMinutes;
        }

        return [
            'notice_minutes' => $noticeMinutes,
            'min_duration_minutes' => $minDurationMinutes,
            'max_duration_minutes' => $maxDurationMinutes,
            'max_concurrent_reservations' => max(0, (int)($app['reservation_max_concurrent_reservations'] ?? 0)),
            'blackout_slots' => reservation_policy_normalize_blackout_slots($app['reservation_blackout_slots'] ?? [], $config),
            'bypass' => [
                'notice' => [
                    'checkout_staff' => !empty($app['reservation_notice_bypass_checkout_staff']),
                    'admins' => !empty($app['reservation_notice_bypass_admins']),
                ],
                'duration' => [
                    'checkout_staff' => !empty($app['reservation_duration_bypass_checkout_staff']),
                    'admins' => !empty($app['reservation_duration_bypass_admins']),
                ],
                'concurrent' => [
                    'checkout_staff' => !empty($app['reservation_concurrent_bypass_checkout_staff']),
                    'admins' => !empty($app['reservation_concurrent_bypass_admins']),
                ],
                'blackout' => [
                    'checkout_staff' => !empty($app['reservation_blackout_bypass_checkout_staff']),
                    'admins' => !empty($app['reservation_blackout_bypass_admins']),
                ],
            ],
        ];
    }
}

if (!function_exists('reservation_policy_rule_can_bypass')) {
    function reservation_policy_rule_can_bypass(
        array $policy,
        string $ruleKey,
        bool $isAdmin,
        bool $isStaff,
        bool $_isOnBehalf
    ): bool {
        // Bypass applies to privileged users for both self-booking and
        // booking on behalf of others.

        $ruleCfg = $policy['bypass'][$ruleKey] ?? [];
        if ($isAdmin) {
            return !empty($ruleCfg['admins']);
        }
        if ($isStaff) {
            return !empty($ruleCfg['checkout_staff']);
        }

        return false;
    }
}

if (!function_exists('reservation_policy_validate_booking')) {
    function reservation_policy_validate_booking(PDO $pdo, array $policy, array $context): array
    {
        $errors = [];

        $startTs = (int)($context['start_ts'] ?? 0);
        $endTs = (int)($context['end_ts'] ?? 0);
        if ($startTs <= 0 || $endTs <= 0 || $endTs <= $startTs) {
            return $errors;
        }

        $isAdmin = !empty($context['is_admin']);
        $isStaff = !empty($context['is_staff']) || $isAdmin;
        $isOnBehalf = !empty($context['is_on_behalf']);
        $excludeReservationId = max(0, (int)($context['exclude_reservation_id'] ?? 0));

        $noticeMinutes = max(0, (int)($policy['notice_minutes'] ?? 0));
        if (
            $noticeMinutes > 0
            && !reservation_policy_rule_can_bypass($policy, 'notice', $isAdmin, $isStaff, $isOnBehalf)
        ) {
            $minAllowedStartTs = time() + ($noticeMinutes * 60);
            if ($startTs < $minAllowedStartTs) {
                $errors[] = 'Reservations must be made at least '
                    . reservation_policy_format_minutes($noticeMinutes)
                    . ' in advance.';
            }
        }

        if (!reservation_policy_rule_can_bypass($policy, 'duration', $isAdmin, $isStaff, $isOnBehalf)) {
            $durationMinutes = (int)floor(($endTs - $startTs) / 60);
            $minDurationMinutes = max(0, (int)($policy['min_duration_minutes'] ?? 0));
            $maxDurationMinutes = max(0, (int)($policy['max_duration_minutes'] ?? 0));

            if ($minDurationMinutes > 0 && $durationMinutes < $minDurationMinutes) {
                $errors[] = 'Reservation duration must be at least '
                    . reservation_policy_format_minutes($minDurationMinutes)
                    . '.';
            }
            if ($maxDurationMinutes > 0 && $durationMinutes > $maxDurationMinutes) {
                $errors[] = 'Reservation duration must be no more than '
                    . reservation_policy_format_minutes($maxDurationMinutes)
                    . '.';
            }
        }

        $maxConcurrent = max(0, (int)($policy['max_concurrent_reservations'] ?? 0));
        if (
            $maxConcurrent > 0
            && !reservation_policy_rule_can_bypass($policy, 'concurrent', $isAdmin, $isStaff, $isOnBehalf)
        ) {
            $rawUserId = trim((string)($context['target_user_id'] ?? ''));
            $targetUserId = (ctype_digit($rawUserId) && (int)$rawUserId > 0)
                ? (string)((int)$rawUserId)
                : '';
            $targetUserEmail = strtolower(trim((string)($context['target_user_email'] ?? '')));

            if ($targetUserId !== '' || $targetUserEmail !== '') {
                try {
                    $params = [
                        ':window_start' => date('Y-m-d H:i:s', $startTs),
                        ':window_end' => date('Y-m-d H:i:s', $endTs),
                    ];

                    $whereUser = [];
                    if ($targetUserId !== '') {
                        $whereUser[] = 'r.user_id = :target_user_id';
                        $params[':target_user_id'] = $targetUserId;
                    }
                    if ($targetUserEmail !== '') {
                        $whereUser[] = 'LOWER(TRIM(r.user_email)) = :target_user_email';
                        $params[':target_user_email'] = $targetUserEmail;
                    }

                    if (!empty($whereUser)) {
                        $sql = "
                            SELECT COUNT(*) AS c
                            FROM reservations r
                            WHERE r.status IN ('pending','confirmed','completed')
                              AND (r.start_datetime < :window_end AND r.end_datetime > :window_start)
                        ";
                        if ($excludeReservationId > 0) {
                            $sql .= ' AND r.id <> :exclude_reservation_id';
                            $params[':exclude_reservation_id'] = $excludeReservationId;
                        }
                        $sql .= ' AND (' . implode(' OR ', $whereUser) . ')';

                        $stmt = $pdo->prepare($sql);
                        $stmt->execute($params);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        $concurrentCount = $row ? (int)($row['c'] ?? 0) : 0;

                        if ($concurrentCount >= $maxConcurrent) {
                            $errors[] = 'This user already has the maximum allowed concurrent reservations ('
                                . $maxConcurrent . ') for that time window.';
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Could not validate the concurrent reservation limit.';
                }
            }
        }

        if (!reservation_policy_rule_can_bypass($policy, 'blackout', $isAdmin, $isStaff, $isOnBehalf)) {
            $blackoutSlots = is_array($policy['blackout_slots'] ?? null) ? $policy['blackout_slots'] : [];
            foreach ($blackoutSlots as $slot) {
                $slotStartTs = strtotime((string)($slot['start'] ?? ''));
                $slotEndTs = strtotime((string)($slot['end'] ?? ''));
                if ($slotStartTs === false || $slotEndTs === false || $slotEndTs <= $slotStartTs) {
                    continue;
                }

                if ($slotStartTs < $endTs && $slotEndTs > $startTs) {
                    $errors[] = 'The selected window overlaps a blackout slot ('
                        . app_format_datetime(date('Y-m-d H:i:s', $slotStartTs))
                        . ' to '
                        . app_format_datetime(date('Y-m-d H:i:s', $slotEndTs))
                        . ').';
                    break;
                }
            }
        }

        return $errors;
    }
}
