<?php
// scripts/email_overdue_users.php
// Send overdue asset reminders via email to each assigned user.
//
// Requirements:
// - SnipeScheduler config with SMTP settings configured (host, from, auth, etc.).
// - CLI only; intended for cron.
//
// Example cron:
// */30 * * * * /usr/bin/php /path/to/scripts/email_overdue_users.php >> /var/log/layout_overdue_users.log 2>&1

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/email.php';

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
$logOut('info', 'email_overdue_users run started');

function build_overdue_email(array $rows, string $subject, array $config): array
{
    $appName = $config['app']['name'] ?? 'SnipeScheduler';
    $logoUrl = trim($config['app']['logo_url'] ?? '');

    $textLines = ["The following assets are overdue:"];
    foreach ($rows as $r) {
        $textLines[] = "- {$r['tag']} ({$r['model']}) – due {$r['due']} ({$r['days']} day" . ($r['days'] === 1 ? '' : 's') . " overdue)";
    }
    $textBody = implode("\n", $textLines);

    // Build HTML table with conditional row colors
    $rowsHtml = '';
    foreach ($rows as $r) {
        $bg = $r['days'] >= 2 ? '#f8d7da' : '#fff3cd';
        $rowsHtml .= '<tr style="background:' . $bg . ';">'
            . '<td style="padding:6px 8px; border:1px solid #e5e5e5;">' . htmlspecialchars($r['tag'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px; border:1px solid #e5e5e5;">' . htmlspecialchars($r['model'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px; border:1px solid #e5e5e5;">' . htmlspecialchars($r['due'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px; border:1px solid #e5e5e5; text-align:center;">' . (int)$r['days'] . '</td>'
            . '</tr>';
    }

    $htmlParts = [];
    $htmlParts[] = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;line-height:1.5;color:#222;} .card{border:1px solid #e5e5e5;border-radius:8px;padding:12px;background:#fafafa;} table{border-collapse:collapse;width:100%;} th{background:#f1f1f1;text-align:left;padding:6px 8px;border:1px solid #e5e5e5;}</style></head><body>';
    if ($logoUrl !== '') {
        $htmlParts[] = '<div style="margin-bottom:12px;"><img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="Logo" style="max-height:60px;"></div>';
    }
    $htmlParts[] = '<div class="card">';
    $htmlParts[] = '<h2 style="margin:0 0 10px 0; font-size:18px;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2>';
    $htmlParts[] = '<p style="margin:6px 0;">The following assets are overdue:</p>';
    $htmlParts[] = '<table><thead><tr><th>Asset</th><th>Model</th><th>Due</th><th>Days overdue</th></tr></thead><tbody>';
    $htmlParts[] = $rowsHtml;
    $htmlParts[] = '</tbody></table>';
    $htmlParts[] = '</div>';
    $htmlParts[] = '<div style="color:#666;font-size:12px;margin-top:12px;">Sent by ' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . '.</div>';
    $htmlParts[] = '</body></html>';

    return [$textBody, implode('', $htmlParts)];
}

try {
    $assets = list_checked_out_assets(true); // overdue only
} catch (Throwable $e) {
    $logErr('Failed to load overdue assets: ' . $e->getMessage());
    exit(1);
}

if (empty($assets)) {
    $logOut('info', 'No overdue assets found.');
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
    $expTs  = $expRaw ? strtotime($expRaw) : null;
    $exp    = $expTs ? app_format_date($expTs) : 'unknown';
    $daysOverdue = $expTs ? max(1, (int)floor((time() - $expTs) / 86400)) : 1;

    if (!isset($buckets[$email])) {
        $buckets[$email] = [
            'name'   => $name !== '' ? $name : $email,
            'assets' => [],
        ];
    }
    $buckets[$email]['assets'][] = [
        'tag'  => $tag,
        'model'=> $model,
        'due'  => $exp,
        'days' => $daysOverdue,
    ];
}

if (empty($buckets)) {
    $logOut('info', 'No overdue assets with notifiable emails.');
    exit(0);
}

$sent = 0;
$failed = 0;
foreach ($buckets as $email => $info) {
    $config = load_config();
    $appName = $config['app']['name'] ?? 'SnipeScheduler';
    $subject = $appName . ' - Overdue assets reminder';
    [$textBody, $htmlBody] = build_overdue_email($info['assets'], $subject, $config);
    try {
        $ok = layout_send_mail($email, $info['name'], $subject, $textBody, $config, $htmlBody);
        if ($ok) {
            $sent++;
            $logOut('sent', $email);
        } else {
            throw new RuntimeException('Email send failed (see logs).');
        }
    } catch (Throwable $e) {
        $failed++;
        $logErr($email . ': ' . $e->getMessage());
    }
}

$logOut('done', "Sent: {$sent}, Failed: {$failed}");
