<?php
// scripts/email_overdue_staff.php
// Send a consolidated overdue asset report via email to a designated staff user.
//
// Requirements:
// - ReserveIT config with SMTP settings configured (host, from, auth, etc.).
// - Set STAFF_EMAIL (env) to the staff recipient; optional STAFF_NAME for display.
// - CLI only; intended for cron.
//
// Example cron:
// STAFF_EMAIL=staff@yourtenant.com STAFF_NAME="Ops Team" /usr/bin/php /path/to/scripts/email_overdue_staff.php >> /var/log/reserveit_overdue_staff.log 2>&1

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/email.php';

$staffEmail = getenv('STAFF_EMAIL') ?: '';
$staffName  = getenv('STAFF_NAME') ?: $staffEmail;
if ($staffEmail === '') {
    fwrite(STDERR, "[error] STAFF_EMAIL is required.\n");
    exit(1);
}

try {
    $assets = list_checked_out_assets(true); // overdue only
} catch (Throwable $e) {
    fwrite(STDERR, "[error] Failed to load overdue assets: {$e->getMessage()}\n");
    exit(1);
}

if (empty($assets)) {
    echo "[info] No overdue assets found.\n";
    exit(0);
}

$lines = [];
foreach ($assets as $a) {
    $tag    = $a['asset_tag'] ?? '';
    $model  = $a['model']['name'] ?? '';
    $assigned = $a['assigned_to'] ?? ($a['assigned_to_fullname'] ?? '');
    $userEmail = '';
    $userName  = '';
    if (is_array($assigned)) {
        $userEmail = $assigned['email'] ?? ($assigned['username'] ?? '');
        $userName  = $assigned['name'] ?? ($assigned['username'] ?? ($assigned['email'] ?? ''));
    } elseif (is_string($assigned)) {
        $userName = $assigned;
    }
    $expRaw = $a['_expected_checkin_norm'] ?? ($a['expected_checkin'] ?? '');
    $exp    = $expRaw ? date('Y-m-d', strtotime($expRaw)) : 'unknown';

    $line = $model !== '' ? "{$tag} ({$model}) – due {$exp}" : "{$tag} – due {$exp}";
    if ($userEmail !== '') {
        $line .= " | User: {$userEmail}" . ($userName !== '' ? " ({$userName})" : '');
    }
    $lines[] = $line;
}

$bodyLines = [
    'Overdue assets report:',
    implode("\n", array_map(static fn($l) => "- {$l}", $lines)),
];

try {
    $ok = reserveit_send_notification(
        $staffEmail,
        $staffName !== '' ? $staffName : $staffEmail,
        'Overdue assets report',
        $bodyLines
    );
    if ($ok) {
        echo "[sent] Overdue report sent to {$staffEmail}\n";
    } else {
        throw new RuntimeException('Email send failed (see logs).');
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[error] Failed to send overdue report: {$e->getMessage()}\n");
    exit(1);
}

echo "[done]\n";

