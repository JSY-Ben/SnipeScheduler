<?php
// email.php
// Minimal SMTP sender for SnipeScheduler. Supports plain/SSL/TLS with LOGIN auth.

require_once __DIR__ . '/bootstrap.php';

/**
 * Send an email via SMTP using config values.
 *
 * @param string      $toEmail
 * @param string      $toName
 * @param string      $subject
 * @param string      $body      Plaintext body (UTF-8)
 * @param string|null $htmlBody  Optional HTML body (UTF-8); when provided, sends multipart/alternative
 * @param array|null  $cfg       Override config array (uses load_config() if null)
 * @return bool                   True on success, false on failure.
 */
function layout_send_mail(string $toEmail, string $toName, string $subject, string $body, ?array $cfg = null, ?string $htmlBody = null): bool
{
    $config = $cfg ?? load_config();
    $appName = trim((string)($config['app']['name'] ?? 'SnipeScheduler'));
    if ($appName === '') {
        $appName = 'SnipeScheduler';
    }
    $smtp   = $config['smtp'] ?? [];

    $host   = trim($smtp['host'] ?? '');
    $port   = (int)($smtp['port'] ?? 587);
    $user   = $smtp['username'] ?? '';
    $pass   = $smtp['password'] ?? '';
    $enc    = strtolower(trim($smtp['encryption'] ?? '')); // none|ssl|tls
    $auth   = strtolower(trim($smtp['auth_method'] ?? 'login')); // login|plain|xoauth2|none
    $from   = $smtp['from_email'] ?? '';
    $fromNm = trim((string)($smtp['from_name'] ?? ''));
    if ($fromNm === '') {
        $fromNm = $appName;
    }

    if ($host === '' || $from === '') {
        error_log('SnipeScheduler SMTP not configured (host/from missing).');
        return false;
    }

    if ($auth === 'xoauth2') {
        if (!isset($smtp['tenant_id'], $smtp['client_id'], $smtp['client_secret'])) {
            error_log('SnipeScheduler SMTP not configured (tenant_id/client_id/client_secret missing).');
            return false;
        }
        if (!file_exists( __DIR__ . '/../vendor/autoload.php')) {
            error_log('Vendor not found.');
            return false;
        }
        require_once __DIR__ . '/../vendor/autoload.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer();
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPSecure = $enc;
        $mail->SMTPAuth = true;
        $mail->AuthType = strtoupper($auth);
        $provider = new Greew\OAuth2\Client\Provider\Azure([
            'tenantId' => $smtp['tenant_id'],
            'clientId' => $smtp['client_id'],
            'clientSecret' => $smtp['client_secret'],
        ]);
        $mail->setOAuth(
            new PHPMailer\PHPMailer\OAuth(
                [
                    'provider' => $provider,
                    'clientId' => $smtp['client_id'],
                    'clientSecret' => $smtp['client_secret'],
                    'refreshToken' => $smtp['refresh_token'],
                    'userName' => $user,
                ]
            )
        );
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->setFrom($from, $fromNm);
        if ($htmlBody) {
            $mail->isHTML();
            $mail->Body = $htmlBody;
        }
        else {
            $mail->isHTML(false);
            $mail->Body = $body;
        }
        try {
            if ($mail->send()) {
                return true;
            }
            error_log('SnipeScheduler SMTP send failed: ' . $mail->ErrorInfo);
            return false;
        }
        catch (Exception $e) {
            error_log('SnipeScheduler SMTP send failed: ' . $e->getMessage());
            return false;
        }
    }

    $remoteHost = ($enc === 'ssl') ? "ssl://{$host}" : $host; // STARTTLS uses plain host, SSL wraps immediately
    $fp = @stream_socket_client("{$remoteHost}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        error_log("SnipeScheduler SMTP connect failed: " . ($errstr ?? 'unknown'));
        return false;
    }

    stream_set_timeout($fp, 10);

    $read = static function () use ($fp) {
        return fgets($fp, 1024);
    };
    $write = static function (string $line) use ($fp) {
        fwrite($fp, $line . "\r\n");
    };
    $expectOk = static function (string $prefix, callable $readFn) {
        $resp = $readFn();
        if ($resp === false || strpos($resp, $prefix) !== 0) {
            throw new Exception("SMTP unexpected response: " . ($resp ?: ''));
        }
    };

    try {
        $expectOk('220', $read);
        $write('EHLO reserveit.local');
        $ehloResp = '';
        do {
            $line = $read();
            $ehloResp .= $line;
        } while ($line !== false && isset($line[3]) && $line[3] === '-');

        if ($enc === 'tls') {
            $write('STARTTLS');
            $expectOk('220', $read);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception('Could not start TLS encryption.');
            }
            // Re-EHLO after STARTTLS
            $write('EHLO reserveit.local');
            do {
                $line = $read();
                $ehloResp .= $line;
            } while ($line !== false && isset($line[3]) && $line[3] === '-');
        }

        $supports = strtolower($ehloResp);
        $authSupported = [
            'login' => strpos($supports, 'auth ') !== false && strpos($supports, 'login') !== false,
            'plain' => strpos($supports, 'auth ') !== false && strpos($supports, 'plain') !== false,
        ];

        if ($user !== '' && $auth !== 'none') {
            $method = $auth;
            if ($method === 'login' && !$authSupported['login'] && $authSupported['plain']) {
                $method = 'plain';
            } elseif ($method === 'plain' && !$authSupported['plain'] && $authSupported['login']) {
                $method = 'login';
            }

            if ($method === 'login') {
                $write('AUTH LOGIN');
                $expectOk('334', $read);
                $write(base64_encode($user));
                $expectOk('334', $read);
                $write(base64_encode($pass));
                $expectOk('235', $read);
            } elseif ($method === 'plain') {
                $write('AUTH PLAIN');
                $expectOk('334', $read);
                $token = base64_encode("\0" . $user . "\0" . $pass);
                $write($token);
                $expectOk('235', $read);
            } else {
                throw new Exception('No supported SMTP auth methods (login/plain) were accepted by the server.');
            }
        }

        $write('MAIL FROM: <' . $from . '>');
        $expectOk('250', $read);
        $write('RCPT TO: <' . $toEmail . '>');
        $expectOk('250', $read);
        $write('DATA');
        $expectOk('354', $read);

    $headers = [];
    $headers[] = 'From: ' . encode_header($fromNm) . " <{$from}>";
    $headers[] = 'To: ' . encode_header($toName) . " <{$toEmail}>";
    $headers[] = 'Subject: ' . encode_header($subject);
    $headers[] = 'MIME-Version: 1.0';

    if ($htmlBody !== null) {
        $boundary = 'b' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $parts  = "--{$boundary}\r\n";
        $parts .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $parts .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $parts .= $body . "\r\n";
        $parts .= "--{$boundary}\r\n";
        $parts .= "Content-Type: text/html; charset=UTF-8\r\n";
        $parts .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $parts .= $htmlBody . "\r\n";
        $parts .= "--{$boundary}--\r\n";

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $parts . "\r\n.";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
    }

    $write($payload);
    $expectOk('250', $read);
        $write('QUIT');
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('SnipeScheduler SMTP send failed: ' . $e->getMessage());
        fclose($fp);
        return false;
    }
}

/**
 * Render notification text as safe HTML, automatically linking absolute URLs.
 */
function layout_notification_line_to_html(string $line): string
{
    if (preg_match('~^\s*([^:]+):\s*(https?://[^\s<>"\']+)\s*$~u', $line, $m)) {
        $label = trim((string)($m[1] ?? ''));
        $url = trim((string)($m[2] ?? ''));
        if ($label !== '' && $url !== '') {
            $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
            $urlEsc = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            return '<a href="' . $urlEsc . '">' . $labelEsc . '</a>';
        }
    }

    $parts = preg_split('~(https?://[^\s<>"\']+)~u', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        return nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8'));
    }

    $html = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        if (preg_match('~^https?://[^\s<>"\']+$~u', $part)) {
            $url = htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
            $html .= '<a href="' . $url . '">' . $url . '</a>';
            continue;
        }

        $html .= nl2br(htmlspecialchars($part, ENT_QUOTES, 'UTF-8'));
    }

    return $html;
}

/**
 * Notification templates exposed in Settings. Values are deliberately HTML so
 * existing installations keep their current messages until an administrator
 * chooses to customise them.
 *
 * @return array<string, array{label:string,config_key:string,default:string}>
 */
function layout_notification_template_definitions(): array
{
    return [
        'overdue_user' => [
            'label' => 'Overdue user reminder email text',
            'config_key' => 'notification_overdue_user_template_html',
            'default' => '<p>Hello {{person_name}},</p><p>The following equipment is overdue:</p><p>{{equipment_list}}</p><p>Please return it as soon as possible.</p>',
        ],
        'overdue_staff' => [
            'label' => 'Overdue staff report email text',
            'config_key' => 'notification_overdue_staff_template_html',
            'default' => '<p>The following equipment is overdue:</p><p>{{equipment_list}}</p><p>{{staff_reservations_link}}</p>',
        ],
        'reservation_submitted' => [
            'label' => 'Reservation submitted email text',
            'config_key' => 'notification_reservation_submitted_template_html',
            'default' => '<p>Reservation <strong>#{{reservation_id}}</strong> has been submitted.</p><p><strong>Reserved for:</strong> {{person_name}}<br><strong>Equipment:</strong> {{equipment_list}}<br><strong>Start:</strong> {{start_date}}<br><strong>Return:</strong> {{return_date}}<br><strong>Reservation notes:</strong> {{reservation_note}}<br><strong>Submitted by:</strong> {{staff_name}}</p><p>{{reservation_link}}</p>',
        ],
        'quick_checkout' => [
            'label' => 'Quick checkout email text',
            'config_key' => 'notification_quick_checkout_template_html',
            'default' => '<p>The following items have been checked out to <strong>{{person_name}}</strong>:</p><p>{{equipment_list}}</p><p><strong>Return by:</strong> {{return_date}}<br><strong>Checked out by:</strong> {{staff_name}}<br><strong>Note:</strong> {{note}}</p>',
        ],
        'staff_checkout' => [
            'label' => 'Reservation checkout email text',
            'config_key' => 'notification_staff_checkout_template_html',
            'default' => '<p>Reservation <strong>#{{reservation_id}}</strong> for {{person_name}} has been checked out.</p><p><strong>Equipment:</strong> {{equipment_list}}<br><strong>Return by:</strong> {{return_date}}<br><strong>Checked out by:</strong> {{staff_name}}<br><strong>Note:</strong> {{note}}</p><p>{{reservation_link}}</p>',
        ],
        'quick_checkin' => [
            'label' => 'Quick check-in email text',
            'config_key' => 'notification_quick_checkin_template_html',
            'default' => '<p>The following items have been checked in for <strong>{{person_name}}</strong>:</p><p>{{equipment_list}}</p><p><strong>Checked in by:</strong> {{staff_name}}<br><strong>Note:</strong> {{note}}</p>',
        ],
        'mark_missed' => [
            'label' => 'Missed reservation email text',
            'config_key' => 'notification_mark_missed_template_html',
            'default' => '<p>Reservation <strong>#{{reservation_id}}</strong> for {{person_name}} has been marked as missed.</p><p><strong>Equipment:</strong> {{equipment_list}}<br><strong>Scheduled start:</strong> {{start_date}}<br><strong>Scheduled return:</strong> {{return_date}}</p><p>{{reservation_link}}</p>',
        ],
    ];
}

/** Keep administrator-authored email HTML to a small, email-safe subset. */
function layout_sanitize_notification_template_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $html = preg_replace('~<(script|style|iframe|object|embed|form|input|button)[^>]*>.*?</\\1\s*>~is', '', $html) ?? '';
    $html = strip_tags($html, '<div><p><br><strong><b><em><i><u><ul><ol><li><a><h1><h2><h3><blockquote>');
    // The editor does not need arbitrary styling or event attributes. Preserve
    // only safe http(s)/mailto links and basic formatting tags.
    $html = preg_replace_callback('~<a\b([^>]*)>~i', static function (array $match): string {
        $attrs = (string)($match[1] ?? '');
        if (!preg_match('~href\s*=\s*(["\'])(.*?)\\1~i', $attrs, $hrefMatch)) {
            return '<a>';
        }
        $href = html_entity_decode(trim((string)$hrefMatch[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('~^(https?://|mailto:)~i', $href)) {
            return '<a>';
        }
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
    }, $html) ?? '';
    $html = preg_replace('~<(?!a\b)([a-z0-9]+)\b[^>]*>~i', '<$1>', $html) ?? '';

    return trim($html);
}

/** @return array{html:string,text:string}|null */
function layout_render_notification_template(string $templateKey, array $variables, array $config): ?array
{
    $definitions = layout_notification_template_definitions();
    if (!isset($definitions[$templateKey])) {
        return null;
    }
    $configKey = $definitions[$templateKey]['config_key'];
    if (!array_key_exists($configKey, $config['app'] ?? [])) {
        return null; // Preserve legacy hard-coded content until explicitly saved.
    }
    $template = layout_sanitize_notification_template_html((string)($config['app'][$configKey] ?? ''));
    if ($template === '') {
        return null;
    }

    $appName = trim((string)($config['app']['name'] ?? 'SnipeScheduler')) ?: 'SnipeScheduler';
    $variables['app_name'] = $variables['app_name'] ?? $appName;
    $htmlReplacements = [];
    $textReplacements = [];
    foreach ($variables as $name => $value) {
        $token = '{{' . $name . '}}';
        $plain = trim((string)$value);
        $textReplacements[$token] = $plain;
        if (substr($name, -5) === '_link' && preg_match('~^https?://~i', $plain)) {
            $escaped = htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
            $htmlReplacements[$token] = '<a href="' . $escaped . '">' . $escaped . '</a>';
        } else {
            $htmlReplacements[$token] = nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8'));
        }
    }
    // Known but unavailable values become empty rather than leaking a token.
    foreach (['recipient_name','person_name','person_email','equipment_list','start_date','return_date','app_name','reservation_id','reservation_link','my_reservations_link','staff_reservations_link','staff_name','staff_email','note','reservation_note'] as $name) {
        $token = '{{' . $name . '}}';
        $htmlReplacements[$token] = $htmlReplacements[$token] ?? '';
        $textReplacements[$token] = $textReplacements[$token] ?? '';
    }
    $renderedHtml = strtr($template, $htmlReplacements);
    $textSource = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $template) ?? $template;
    $textSource = preg_replace('~</(div|p|li|h[1-3]|blockquote)>~i', "\n", $textSource) ?? $textSource;
    $plainTemplate = html_entity_decode(strip_tags($textSource), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $renderedText = trim(strtr($plainTemplate, $textReplacements));

    return ['html' => $renderedHtml, 'text' => $renderedText];
}

/**
 * Resolve the public base URL for app links in emails.
 *
 * Uses app.base_url when configured, otherwise falls back to the current web request.
 */
function layout_app_base_url(?array $cfg = null): string
{
    $config = $cfg ?? load_config();
    $configured = trim((string)($config['app']['base_url'] ?? ''));
    if ($configured !== '' && preg_match('~^https?://~i', $configured)) {
        return rtrim($configured, '/');
    }

    $hostRaw = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '')));
    if ($hostRaw === '') {
        return '';
    }
    $host = trim(explode(',', $hostRaw)[0]);
    if ($host === '') {
        return '';
    }

    $forwardedProtoRaw = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($forwardedProtoRaw !== '') {
        $scheme = strtolower(trim(explode(',', $forwardedProtoRaw)[0]));
    } else {
        $https = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
        $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }
    if ($scheme !== 'https') {
        $scheme = 'http';
    }

    if ($configured !== '' && $configured[0] === '/') {
        return $scheme . '://' . $host . rtrim($configured, '/');
    }

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = rtrim(dirname($scriptName), '/');
    $dir = ($dir === '' || $dir === '.') ? '' : $dir;

    return $scheme . '://' . $host . $dir;
}

/**
 * Build an absolute URL for a public app page.
 *
 * @param array<string, scalar|null> $query
 */
function layout_app_page_url(string $path, ?array $cfg = null, array $query = []): string
{
    $baseUrl = layout_app_base_url($cfg);
    if ($baseUrl === '') {
        return '';
    }

    $url = $baseUrl . '/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

/**
 * Link to the end-user reservations page.
 */
function layout_my_reservations_url(?array $cfg = null): string
{
    return layout_app_page_url('my_bookings.php', $cfg);
}

/**
 * Link to the staff/admin reservations workspace.
 */
function layout_staff_reservations_url(?array $cfg = null): string
{
    return layout_app_page_url('reservations.php', $cfg);
}

/**
 * Return a standard end-user portal link for notifications.
 */
function layout_my_reservations_link_line(?array $cfg = null): ?string
{
    $url = layout_my_reservations_url($cfg);
    if ($url === '') {
        return null;
    }

    return 'My Reservations: ' . $url;
}

/**
 * Return a standard staff/admin portal link for notifications.
 */
function layout_staff_reservations_link_line(?array $cfg = null): ?string
{
    $url = layout_staff_reservations_url($cfg);
    if ($url === '') {
        return null;
    }

    return 'Reservations: ' . $url;
}

/**
 * Build an absolute reservation detail URL for email notifications.
 */
function layout_reservation_detail_url(int $reservationId, ?array $cfg = null): string
{
    $reservationId = (int)$reservationId;
    if ($reservationId <= 0) {
        return '';
    }

    return layout_app_page_url('reservation_detail.php', $cfg, [
        'id' => $reservationId,
    ]);
}

/**
 * Return a standard reservation detail line for notifications.
 */
function layout_reservation_link_line(int $reservationId, ?array $cfg = null): ?string
{
    $url = layout_reservation_detail_url($reservationId, $cfg);
    if ($url === '') {
        return null;
    }

    return 'View reservation: ' . $url;
}

/**
 * Convenience wrapper for sending a plaintext notification with multiple lines.
 *
 * @param string     $toEmail
 * @param string     $toName
 * @param string     $subject
 * @param array      $lines  Array of strings for the body (joined by newlines)
 * @param array|null $cfg
 * @return bool
 */
function layout_send_notification(
    string $toEmail,
    string $toName,
    string $subject,
    array $lines,
    ?array $cfg = null,
    bool $includeHtml = true,
    ?string $templateKey = null,
    array $templateVariables = []
): bool
{
    $bodyLines = array_filter($lines, static function ($line) {
        return $line !== null && $line !== '';
    });
    $body = implode("\n", $bodyLines);

    $config = $cfg ?? load_config();
    $templateVariables['recipient_name'] = $templateVariables['recipient_name'] ?? $toName;
    $renderedTemplate = $templateKey !== null
        ? layout_render_notification_template($templateKey, $templateVariables, $config)
        : null;
    if ($renderedTemplate !== null) {
        $body = $renderedTemplate['text'];
        if ($templateKey === 'reservation_submitted') {
            $reservationNote = trim((string)($templateVariables['reservation_note'] ?? ''));
            $configuredTemplate = (string)($config['app']['notification_reservation_submitted_template_html'] ?? '');
            if ($reservationNote !== '' && strpos($configuredTemplate, '{{reservation_note}}') === false) {
                $body .= ($body !== '' ? "\n" : '') . 'Reservation notes: ' . $reservationNote;
                $renderedTemplate['html'] .= '<p><strong>Reservation notes:</strong><br>'
                    . nl2br(htmlspecialchars($reservationNote, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
        }
    }

    $htmlBody = null;
    if ($includeHtml) {
        $logoUrl = trim($config['app']['logo_url'] ?? '');
        $appName = trim((string)($config['app']['name'] ?? 'SnipeScheduler'));
        if ($appName === '') {
            $appName = 'SnipeScheduler';
        }

        $htmlParts = [];
        $htmlParts[] = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;line-height:1.5;color:#222;} .logo{margin-bottom:12px;} .card{border:1px solid #e5e5e5;border-radius:8px;padding:12px;background:#fafafa;} .muted{color:#666;font-size:12px;}</style></head><body>';
        if ($logoUrl !== '') {
            $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
            $htmlParts[] = '<div class="logo"><img src="' . $logoEsc . '" alt="Logo" style="max-height:60px;"></div>';
        }
        $htmlParts[] = '<div class="card">';
        $htmlParts[] = '<h2 style="margin:0 0 10px 0; font-size:18px;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2>';
        if ($renderedTemplate !== null) {
            $htmlParts[] = '<div class="notification-template">' . $renderedTemplate['html'] . '</div>';
        } else {
            foreach ($bodyLines as $line) {
                $htmlParts[] = '<p style="margin:6px 0;">' . layout_notification_line_to_html((string)$line) . '</p>';
            }
        }
        $htmlParts[] = '</div>';
        $htmlParts[] = '<div class="muted" style="margin-top:12px;">This message was sent by ' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . '.</div>';
        $htmlParts[] = '</body></html>';
        $htmlBody = implode('', $htmlParts);
    }

    // Prefix subject with app name
    $appName = trim((string)($config['app']['name'] ?? 'SnipeScheduler'));
    if ($appName === '') {
        $appName = 'SnipeScheduler';
    }
    $prefixedSubject = $appName . ' - ' . $subject;

    return layout_send_mail($toEmail, $toName, $prefixedSubject, $body, $cfg, $htmlBody);
}

/**
 * Parse a comma/newline/semicolon separated email list.
 *
 * Invalid entries are ignored.
 *
 * @return string[]
 */
function layout_parse_email_list(string $raw): array
{
    $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
    $emails = [];
    $seen = [];

    foreach ($parts as $part) {
        $email = trim((string)$part);
        if ($email === '') {
            continue;
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            continue;
        }

        $key = strtolower($email);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $emails[] = $email;
    }

    return $emails;
}

/**
 * Build recipient entries from a raw list, excluding any matching emails.
 *
 * @param string[] $excludeEmails
 * @return array<int, array{email: string, name: string}>
 */
function layout_extra_notification_recipients(string $raw, array $excludeEmails = []): array
{
    $exclude = [];
    foreach ($excludeEmails as $email) {
        $key = strtolower(trim((string)$email));
        if ($key !== '') {
            $exclude[$key] = true;
        }
    }

    $recipients = [];
    foreach (layout_parse_email_list($raw) as $email) {
        $key = strtolower(trim($email));
        if ($key === '' || isset($exclude[$key])) {
            continue;
        }
        $exclude[$key] = true;
        $recipients[] = [
            'email' => $email,
            'name'  => $email,
        ];
    }

    return $recipients;
}

/**
 * Build named recipients from paired email/name raw lists.
 *
 * Email list is required; names are optional and matched by position.
 * If only one name is provided, it is reused for all recipients.
 *
 * @param string[] $excludeEmails
 * @return array<int, array{email: string, name: string}>
 */
function layout_named_recipients_from_lists(string $emailsRaw, string $namesRaw = '', array $excludeEmails = []): array
{
    $exclude = [];
    foreach ($excludeEmails as $email) {
        $key = strtolower(trim((string)$email));
        if ($key !== '') {
            $exclude[$key] = true;
        }
    }

    $emails = layout_parse_email_list($emailsRaw);
    $nameParts = preg_split('/[\r\n,;]+/', $namesRaw) ?: [];
    $names = [];
    foreach ($nameParts as $part) {
        $name = trim((string)$part);
        if ($name !== '') {
            $names[] = $name;
        }
    }

    $recipients = [];
    foreach ($emails as $idx => $email) {
        $key = strtolower(trim($email));
        if ($key === '' || isset($exclude[$key])) {
            continue;
        }

        $name = $names[$idx] ?? '';
        if ($name === '' && count($names) === 1) {
            $name = $names[0];
        }
        if ($name === '') {
            $name = $email;
        }

        $exclude[$key] = true;
        $recipients[] = [
            'email' => $email,
            'name'  => $name,
        ];
    }

    return $recipients;
}

/**
 * Escape a value for LDAP filter usage.
 */
function layout_ldap_escape_filter_value(string $value): string
{
    if (function_exists('ldap_escape')) {
        return ldap_escape($value, '', defined('LDAP_ESCAPE_FILTER') ? LDAP_ESCAPE_FILTER : 0);
    }

    return str_replace(
        ['\\', '*', '(', ')', "\x00"],
        ['\5c', '\2a', '\28', '\29', '\00'],
        $value
    );
}

/**
 * Build recipients from LDAP members of configured group CN values.
 *
 * @param string[] $groupCns
 * @param string[] $excludeEmails
 * @return array<int, array{email: string, name: string}>
 */
function layout_ldap_group_notification_recipients(array $groupCns, array $ldapCfg, array $excludeEmails = []): array
{
    if (empty($groupCns)) {
        return [];
    }
    if (!function_exists('ldap_connect') || !function_exists('ldap_search') || !function_exists('ldap_get_entries')) {
        return [];
    }

    $host = trim((string)($ldapCfg['host'] ?? ''));
    $baseDn = trim((string)($ldapCfg['base_dn'] ?? ''));
    if ($host === '' || $baseDn === '') {
        return [];
    }

    if (!empty($ldapCfg['ignore_cert'])) {
        putenv('LDAPTLS_REQCERT=never');
        if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT') && defined('LDAP_OPT_X_TLS_NEVER')) {
            @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
        }
        if (defined('LDAP_OPT_X_TLS_NEWCTX')) {
            @ldap_set_option(null, LDAP_OPT_X_TLS_NEWCTX, 0);
        }
    }

    $ldap = @ldap_connect($host);
    if (!$ldap) {
        return [];
    }
    @ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
        @ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);
    }

    $bindDn = trim((string)($ldapCfg['bind_dn'] ?? ''));
    $bindPwd = (string)($ldapCfg['bind_password'] ?? '');
    $bound = $bindDn !== ''
        ? @ldap_bind($ldap, $bindDn, $bindPwd)
        : @ldap_bind($ldap);
    if (!$bound) {
        @ldap_unbind($ldap);
        return [];
    }

    $normalizedCns = [];
    foreach ($groupCns as $cn) {
        $cn = trim((string)$cn);
        if ($cn !== '') {
            $normalizedCns[strtolower($cn)] = $cn;
        }
    }
    if (empty($normalizedCns)) {
        @ldap_unbind($ldap);
        return [];
    }

    $groupDns = [];
    foreach (array_values($normalizedCns) as $cn) {
        $groupFilter = '(&(objectClass=group)(cn=' . layout_ldap_escape_filter_value($cn) . '))';
        $groupSearch = @ldap_search($ldap, $baseDn, $groupFilter, ['distinguishedName'], 0, 20);
        if (!$groupSearch) {
            continue;
        }
        $groupEntries = @ldap_get_entries($ldap, $groupSearch);
        $count = (int)($groupEntries['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $dn = trim((string)($groupEntries[$i]['distinguishedname'][0] ?? $groupEntries[$i]['dn'] ?? ''));
            if ($dn !== '') {
                $groupDns[strtolower($dn)] = $dn;
            }
        }
    }

    $memberFilters = [];
    if (!empty($groupDns)) {
        foreach ($groupDns as $dn) {
            $memberFilters[] = '(memberOf=' . layout_ldap_escape_filter_value($dn) . ')';
        }
    } else {
        // Fallback when group DN lookup fails: substring match on CN within memberOf.
        foreach (array_values($normalizedCns) as $cn) {
            $memberFilters[] = '(memberOf=*CN=' . layout_ldap_escape_filter_value($cn) . ',*)';
        }
    }

    if (empty($memberFilters)) {
        @ldap_unbind($ldap);
        return [];
    }

    $memberFilter = count($memberFilters) === 1
        ? $memberFilters[0]
        : '(|' . implode('', $memberFilters) . ')';

    $userFilter = '(&(|(objectClass=user)(objectClass=person))'
        . $memberFilter
        . '(|(mail=*)(userPrincipalName=*)))';
    $userAttrs = ['mail', 'userPrincipalName', 'displayName', 'givenName', 'sn'];
    $userSearch = @ldap_search($ldap, $baseDn, $userFilter, $userAttrs, 0, 2000);
    $userEntries = $userSearch ? @ldap_get_entries($ldap, $userSearch) : ['count' => 0];

    $exclude = [];
    foreach ($excludeEmails as $email) {
        $key = strtolower(trim((string)$email));
        if ($key !== '') {
            $exclude[$key] = true;
        }
    }

    $recipients = [];
    $userCount = (int)($userEntries['count'] ?? 0);
    for ($i = 0; $i < $userCount; $i++) {
        $mail = trim((string)($userEntries[$i]['mail'][0] ?? ''));
        $upn = trim((string)($userEntries[$i]['userprincipalname'][0] ?? ''));
        $email = '';
        if ($mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) !== false) {
            $email = $mail;
        } elseif ($upn !== '' && filter_var($upn, FILTER_VALIDATE_EMAIL) !== false) {
            $email = $upn;
        }
        if ($email === '') {
            continue;
        }

        $key = strtolower($email);
        if (isset($exclude[$key])) {
            continue;
        }

        $displayName = trim((string)($userEntries[$i]['displayname'][0] ?? ''));
        $givenName = trim((string)($userEntries[$i]['givenname'][0] ?? ''));
        $surname = trim((string)($userEntries[$i]['sn'][0] ?? ''));
        $name = $displayName !== '' ? $displayName : trim($givenName . ' ' . $surname);
        if ($name === '') {
            $name = $email;
        }

        $exclude[$key] = true;
        $recipients[] = [
            'email' => $email,
            'name' => $name,
        ];
    }

    @ldap_unbind($ldap);
    return $recipients;
}

/**
 * Build role-based recipients for reservation submitted notifications.
 *
 * Checkout users are sourced from auth checkout email lists and LDAP groups.
 * Administrators are sourced from auth admin email lists and LDAP groups.
 * If no role-based recipients are configured, this falls back to the overdue
 * staff reminder recipient list for backward compatibility.
 *
 * @param string[] $excludeEmails
 * @return array<int, array{email: string, name: string}>
 */
function layout_role_notification_recipients(
    bool $includeCheckoutUsers,
    bool $includeAdministrators,
    ?array $cfg = null,
    array $excludeEmails = []
): array
{
    if (!$includeCheckoutUsers && !$includeAdministrators) {
        return [];
    }

    $config = $cfg ?? load_config();
    $authCfg = $config['auth'] ?? [];
    $ldapCfg = $config['ldap'] ?? [];

    $roleEmails = [];
    $addEmailList = static function ($raw) use (&$roleEmails): void {
        if (!is_array($raw)) {
            return;
        }

        foreach ($raw as $item) {
            foreach (layout_parse_email_list((string)$item) as $email) {
                $key = strtolower(trim($email));
                if ($key !== '') {
                    $roleEmails[$key] = $email;
                }
            }
        }
    };
    $normalizeList = static function ($raw): array {
        if (!is_array($raw)) {
            $raw = $raw !== '' ? [$raw] : [];
        }
        return array_values(array_filter(array_map('trim', $raw), static function ($value) {
            return $value !== '';
        }));
    };
    $ldapGroupCns = [];

    if ($includeCheckoutUsers) {
        $addEmailList($authCfg['google_checkout_emails'] ?? []);
        $addEmailList($authCfg['microsoft_checkout_emails'] ?? []);
        foreach ($normalizeList($authCfg['checkout_group_cn'] ?? []) as $cn) {
            $ldapGroupCns[strtolower($cn)] = $cn;
        }
    }

    if ($includeAdministrators) {
        $addEmailList($authCfg['google_admin_emails'] ?? []);
        $addEmailList($authCfg['microsoft_admin_emails'] ?? []);
        foreach ($normalizeList($authCfg['admin_group_cn'] ?? []) as $cn) {
            $ldapGroupCns[strtolower($cn)] = $cn;
        }
    }

    $exclude = [];
    foreach ($excludeEmails as $email) {
        $key = strtolower(trim((string)$email));
        if ($key !== '') {
            $exclude[$key] = true;
        }
    }

    $recipients = [];
    $appendRecipients = static function (array $newRecipients) use (&$recipients, &$exclude): void {
        foreach ($newRecipients as $recipient) {
            $email = trim((string)($recipient['email'] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $key = strtolower($email);
            if (isset($exclude[$key])) {
                continue;
            }
            $exclude[$key] = true;
            $name = trim((string)($recipient['name'] ?? ''));
            $recipients[] = [
                'email' => $email,
                'name' => $name !== '' ? $name : $email,
            ];
        }
    };

    if (!empty($roleEmails)) {
        $appendRecipients(
            layout_extra_notification_recipients(implode("\n", array_values($roleEmails)), array_keys($exclude))
        );
    }

    $ldapEnabled = array_key_exists('ldap_enabled', $authCfg) ? !empty($authCfg['ldap_enabled']) : true;
    if ($ldapEnabled && !empty($ldapGroupCns)) {
        $appendRecipients(
            layout_ldap_group_notification_recipients(array_values($ldapGroupCns), $ldapCfg, array_keys($exclude))
        );
    }

    if (!empty($recipients)) {
        return $recipients;
    }

    $appCfg = $config['app'] ?? [];
    return layout_named_recipients_from_lists(
        (string)($appCfg['overdue_staff_email'] ?? ''),
        (string)($appCfg['overdue_staff_name'] ?? ''),
        $excludeEmails
    );
}

function encode_header(string $str): string
{
    if (preg_match('/[^\x20-\x7E]/', $str)) {
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }
    return $str;
}
