<?php
// scripts/email_overdue_staff.php
// Send a consolidated overdue asset report via email to a designated staff user.
//
// Requirements:
// - SnipeScheduler config with SMTP settings configured (host, from, auth, etc.).
// - Set STAFF_EMAIL (env) to the staff recipient; optional STAFF_NAME for display.
// - CLI only; intended for cron.
//
// Example cron:
// STAFF_EMAIL=staff@yourtenant.com STAFF_NAME="Ops Team" /usr/bin/php /path/to/scripts/email_overdue_staff.php >> /var/log/layout_overdue_staff.log 2>&1

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
$logOut('info', 'email_overdue_staff run started');

$staffEmailRaw = trim((string)($config['app']['overdue_staff_email'] ?? ''));
$staffNameRaw  = trim((string)($config['app']['overdue_staff_name'] ?? ''));

$splitList = static function (string $raw): array {
    $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $val = trim($part);
        if ($val !== '') {
            $out[] = $val;
        }
    }
    return $out;
};

$staffEmails = $splitList($staffEmailRaw);
$staffNames  = $splitList($staffNameRaw);

if (empty($staffEmails)) {
    $logErr('Overdue staff recipient email is not configured in settings.');
    exit(1);
}

function build_overdue_email_staff(array $rows, string $subject, array $config): array
{
    $appName = $config['app']['name'] ?? 'SnipeScheduler';
    $logoUrl = trim($config['app']['logo_url'] ?? '');

    $textLines = ["Overdue assets report:"];
    foreach ($rows as $r) {
        $textLines[] = "- {$r['tag']} ({$r['model']}) – due {$r['due']} ({$r['days']} day" . ($r['days'] === 1 ? '' : 's') . " overdue) | User: {$r['user']}";
    }
    $textBody = implode("\n", $textLines);

    $rowsHtml = '';
    foreach ($rows as $r) {
        $bg = $r['days'] >= 2 ? '#f8d7da' : '#fff3cd';
        $rowsHtml .= '<tr style="background:' . $bg . ';">'
            . '<td style="padding:6px 8px; border:1px solid #e5e5e5;">' . htmlspecialchars($r['tag'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px; border:1px solid #e5e5e5;">' . htmlspecialchars($r['model'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px; border:1px solid #e5e5e5;">' . htmlspecialchars($r['due'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px 8px; border:1px solid #e5e5e5; text-align:center;">' . (int)$r['days'] . '</td>'
            . '<td style="padding:6px 8px; border:1px solid #e5e5e5;">' . htmlspecialchars($r['user'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr>';
    }

    $htmlParts = [];
    $htmlParts[] = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;line-height:1.5;color:#222;} .card{border:1px solid #e5e5e5;border-radius:8px;padding:12px;background:#fafafa;} table{border-collapse:collapse;width:100%;} th{background:#f1f1f1;text-align:left;padding:6px 8px;border:1px solid #e5e5e5;}</style></head><body>';
    if ($logoUrl !== '') {
        $htmlParts[] = '<div style="margin-bottom:12px;"><img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="Logo" style="max-height:60px;"></div>';
    }
    $htmlParts[] = '<div class="card">';
    $htmlParts[] = '<h2 style="margin:0 0 10px 0; font-size:18px;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2>';
    $htmlParts[] = '<p style="margin:6px 0;">Overdue assets report:</p>';
    $htmlParts[] = '<table><thead><tr><th>Asset</th><th>Model</th><th>Due</th><th>Days overdue</th><th>User</th></tr></thead><tbody>';
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
    $expTs  = $expRaw ? strtotime($expRaw) : null;
    $exp    = $expTs ? app_format_date($expTs) : 'unknown';
    $daysOverdue = $expTs ? max(1, (int)floor((time() - $expTs) / 86400)) : 1;

    $lineUser = $userEmail !== '' ? "{$userEmail}" . ($userName !== '' ? " ({$userName})" : '') : 'Unknown';
    $lines[] = [
        'tag'  => $tag,
        'model'=> $model,
        'due'  => $exp,
        'days' => $daysOverdue,
        'user' => $lineUser,
    ];
}

usort($lines, static function (array $a, array $b): int {
    if ($a['days'] === $b['days']) {
        return strcasecmp($a['tag'], $b['tag']);
    }
    return $b['days'] <=> $a['days'];
});

$logOut('info', 'Overdue staff report contains ' . count($lines) . ' overdue item(s).');

$appName = $config['app']['name'] ?? 'SnipeScheduler';
$subject = $appName . ' - Overdue assets report';
[$textBody, $htmlBody] = build_overdue_email_staff($lines, $subject, $config);

try {
    $sentCount = 0;
    foreach ($staffEmails as $idx => $email) {
        $name = $staffNames[$idx] ?? '';
        if ($name === '' && count($staffNames) === 1) {
            $name = $staffNames[0];
        }
        $ok = layout_send_mail(
            $email,
            $name !== '' ? $name : $email,
            $subject,
            $textBody,
            $config,
            $htmlBody
        );
        if ($ok) {
            $sentCount++;
            $logOut('sent', "Overdue report sent to {$email}");
        } else {
            throw new RuntimeException('Email send failed (see logs).');
        }
    }
} catch (Throwable $e) {
    $logErr('Failed to send overdue report: ' . $e->getMessage());
    exit(1);
}

$logOut('done', "Completed; sent {$sentCount} email(s).");
