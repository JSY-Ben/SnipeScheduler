<?php
// announcement_helpers.php
// Shared helpers for configurable catalogue announcements.

if (!function_exists('app_announcement_parse_datetime_input')) {
    function app_announcement_parse_datetime_input(string $raw, ?DateTimeZone $tz = null): ?DateTime
    {
        $text = trim($raw);
        if ($text === '') {
            return null;
        }

        $formats = [
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
        ];
        foreach ($formats as $format) {
            $dt = $tz
                ? DateTime::createFromFormat('!' . $format, $text, $tz)
                : DateTime::createFromFormat('!' . $format, $text);
            if (!$dt) {
                continue;
            }
            $lastErrors = DateTime::getLastErrors();
            if (is_array($lastErrors) && ((int)($lastErrors['warning_count'] ?? 0) > 0 || (int)($lastErrors['error_count'] ?? 0) > 0)) {
                continue;
            }
            return $dt;
        }

        return app_parse_datetime_value($text, $tz);
    }
}

if (!function_exists('app_announcement_format_datetime_for_input')) {
    function app_announcement_format_datetime_for_input(int $timestamp, ?DateTimeZone $tz, string $format): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        $dt = new DateTime('@' . $timestamp);
        if ($tz) {
            $dt->setTimezone($tz);
        }
        return $dt->format($format);
    }
}

if (!function_exists('app_announcement_normalize_entry')) {
    function app_announcement_normalize_entry(array $entry, ?DateTimeZone $tz = null): ?array
    {
        $message = trim((string)($entry['message'] ?? ''));
        if ($message === '') {
            return null;
        }

        $startTs = max(0, (int)($entry['start_ts'] ?? 0));
        $endTs = max(0, (int)($entry['end_ts'] ?? 0));

        if ($startTs <= 0) {
            $startRaw = trim((string)($entry['start_datetime'] ?? ($entry['start'] ?? '')));
            $startDt = $startRaw !== '' ? app_announcement_parse_datetime_input($startRaw, $tz) : null;
            if ($startDt instanceof DateTime) {
                $startTs = $startDt->getTimestamp();
            }
        }

        if ($endTs <= 0) {
            $endRaw = trim((string)($entry['end_datetime'] ?? ($entry['end'] ?? '')));
            $endDt = $endRaw !== '' ? app_announcement_parse_datetime_input($endRaw, $tz) : null;
            if ($endDt instanceof DateTime) {
                $endTs = $endDt->getTimestamp();
            }
        }

        if ($startTs <= 0 || $endTs <= 0 || $endTs <= $startTs) {
            return null;
        }

        $startDt = new DateTime('@' . $startTs);
        $endDt = new DateTime('@' . $endTs);
        if ($tz) {
            $startDt->setTimezone($tz);
            $endDt->setTimezone($tz);
        }

        return [
            'message' => $message,
            'start_ts' => $startTs,
            'end_ts' => $endTs,
            'start_datetime' => $startDt->format('Y-m-d H:i:s'),
            'end_datetime' => $endDt->format('Y-m-d H:i:s'),
            'token' => sha1($message . '|' . $startTs . '|' . $endTs),
        ];
    }
}

if (!function_exists('app_announcements_from_app_config')) {
    function app_announcements_from_app_config(?array $appCfg, ?DateTimeZone $tz = null): array
    {
        $appCfg = is_array($appCfg) ? $appCfg : [];
        $items = [];
        $seen = [];

        $append = static function (array $entry) use (&$items, &$seen, $tz): void {
            $normalized = app_announcement_normalize_entry($entry, $tz);
            if (!$normalized) {
                return;
            }
            $token = $normalized['token'];
            if (isset($seen[$token])) {
                return;
            }
            $seen[$token] = true;
            $items[] = $normalized;
        };

        $list = $appCfg['announcements'] ?? [];
        if (is_array($list)) {
            foreach ($list as $entry) {
                if (is_array($entry)) {
                    $append($entry);
                }
            }
        }

        $append([
            'message' => (string)($appCfg['announcement_message'] ?? ''),
            'start_ts' => (int)($appCfg['announcement_start_ts'] ?? 0),
            'end_ts' => (int)($appCfg['announcement_end_ts'] ?? 0),
            'start_datetime' => (string)($appCfg['announcement_start_datetime'] ?? ''),
            'end_datetime' => (string)($appCfg['announcement_end_datetime'] ?? ''),
        ]);

        usort($items, static function (array $a, array $b): int {
            if ($a['start_ts'] !== $b['start_ts']) {
                return $a['start_ts'] <=> $b['start_ts'];
            }
            if ($a['end_ts'] !== $b['end_ts']) {
                return $a['end_ts'] <=> $b['end_ts'];
            }
            return strcmp($a['message'], $b['message']);
        });

        return $items;
    }
}

if (!function_exists('app_announcements_active')) {
    function app_announcements_active(array $announcements, ?int $atTimestamp = null): array
    {
        $atTimestamp = $atTimestamp ?? time();
        $active = [];
        foreach ($announcements as $item) {
            $startTs = (int)($item['start_ts'] ?? 0);
            $endTs = (int)($item['end_ts'] ?? 0);
            if ($startTs <= 0 || $endTs <= $startTs) {
                continue;
            }
            if ($atTimestamp < $startTs || $atTimestamp > $endTs) {
                continue;
            }
            $active[] = $item;
        }
        return $active;
    }
}

if (!function_exists('app_announcements_session_token')) {
    function app_announcements_session_token(array $announcements): string
    {
        if (empty($announcements)) {
            return '';
        }

        $parts = [];
        foreach ($announcements as $item) {
            $parts[] = (string)($item['token'] ?? sha1(
                (string)($item['message'] ?? '')
                . '|'
                . (int)($item['start_ts'] ?? 0)
                . '|'
                . (int)($item['end_ts'] ?? 0)
            ));
        }

        return sha1(implode('|', $parts));
    }
}
