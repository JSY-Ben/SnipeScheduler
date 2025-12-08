<?php
// scripts/email_overdue_users.php
// Send overdue asset reminders via email to each assigned user.
//
// Requirements:
// - ReserveIT config with SMTP settings configured (host, from, auth, etc.).
// - CLI only; intended for cron.
//
// Example cron:
// */30 * * * * /usr/bin/php /path/to/scripts/email_overdue_users.php >> /var/log/reserveit_overdue_users.log 2>&1

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/email.php';

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

// Bucket by user email
$buckets = [];
foreach ($assets as $a) {
    $assigned = $a['assigned_to'] ?? ($a['assigned_to_fullname'] ?? '');
    $email    = '';
    $name     = '';
    if (is_array($assigned)) {
        $email = $assigned['email'] ?? ($assigned['username'] ?? '');
        $name  = $assigned['name'] ?? ($assigned['username'] ?? ($assigned['email'] ?? ''));
    } elseif (is_string($assigned)) {
        $name = $assigned;
    }
    if ($email === '') {
        continue; // cannot notify without email
    }
    $tag    = $a['asset_tag'] ?? '';
    $model  = $a['model']['name'] ?? '';
    $expRaw = $a['_expected_checkin_norm'] ?? ($a['expected_checkin'] ?? '');
    $exp    = $expRaw ? date('Y-m-d', strtotime($expRaw)) : 'unknown';

    $line = $model !== '' ? "{$tag} ({$model}) – due {$exp}" : "{$tag} – due {$exp}";

    if (!isset($buckets[$email])) {
        $buckets[$email] = [
            'name'   => $name !== '' ? $name : $email,
            'assets' => [],
        ];
    }
    $buckets[$email]['assets'][] = $line;
}

if (empty($buckets)) {
    echo "[info] No overdue assets with notifiable emails.\n";
    exit(0);
}

$sent = 0;
$failed = 0;
foreach ($buckets as $email => $info) {
    $bodyLines = [
        'The following assets are overdue:',
        implode("\n", array_map(static fn($l) => "- {$l}", $info['assets'])),
    ];
    try {
        $ok = reserveit_send_notification(
            $email,
            $info['name'],
            'Overdue assets reminder',
            $bodyLines
        );
        if ($ok) {
            $sent++;
            echo "[sent] {$email}\n";
        } else {
            throw new RuntimeException('Email send failed (see logs).');
        }
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "[error] {$email}: {$e->getMessage()}\n");
    }
}

echo "[done] Sent: {$sent}, Failed: {$failed}\n";

