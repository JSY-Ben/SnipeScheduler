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
    $smtp   = $config['smtp'] ?? [];

    $host   = trim($smtp['host'] ?? '');
    $port   = (int)($smtp['port'] ?? 587);
    $user   = $smtp['username'] ?? '';
    $pass   = $smtp['password'] ?? '';
    $enc    = strtolower(trim($smtp['encryption'] ?? '')); // none|ssl|tls
    $auth   = strtolower(trim($smtp['auth_method'] ?? 'login')); // login|plain|none
    $from   = $smtp['from_email'] ?? '';
    $fromNm = $smtp['from_name'] ?? 'SnipeScheduler';

    if ($host === '' || $from === '') {
        error_log('SnipeScheduler SMTP not configured (host/from missing).');
        return false;
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
 * Convenience wrapper for sending a plaintext notification with multiple lines.
 *
 * @param string     $toEmail
 * @param string     $toName
 * @param string     $subject
 * @param array      $lines  Array of strings for the body (joined by newlines)
 * @param array|null $cfg
 * @return bool
 */
function layout_send_notification(string $toEmail, string $toName, string $subject, array $lines, ?array $cfg = null, bool $includeHtml = true): bool
{
    $bodyLines = array_filter($lines, static function ($line) {
        return $line !== null && $line !== '';
    });
    $body = implode("\n", $bodyLines);

    $htmlBody = null;
    if ($includeHtml) {
        $config = $cfg ?? load_config();
        $logoUrl = trim($config['app']['logo_url'] ?? '');
        $appName = $config['app']['name'] ?? 'SnipeScheduler';

        $htmlParts = [];
        $htmlParts[] = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;line-height:1.5;color:#222;} .logo{margin-bottom:12px;} .card{border:1px solid #e5e5e5;border-radius:8px;padding:12px;background:#fafafa;} .muted{color:#666;font-size:12px;}</style></head><body>';
        if ($logoUrl !== '') {
            $logoEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
            $htmlParts[] = '<div class="logo"><img src="' . $logoEsc . '" alt="Logo" style="max-height:60px;"></div>';
        }
        $htmlParts[] = '<div class="card">';
        $htmlParts[] = '<h2 style="margin:0 0 10px 0; font-size:18px;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h2>';
        foreach ($bodyLines as $line) {
            $htmlParts[] = '<p style="margin:6px 0;">' . nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        $htmlParts[] = '</div>';
        $htmlParts[] = '<div class="muted" style="margin-top:12px;">This message was sent by ' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . '.</div>';
        $htmlParts[] = '</body></html>';
        $htmlBody = implode('', $htmlParts);
    }

    // Prefix subject with app name
    $config = $cfg ?? load_config();
    $appName = $config['app']['name'] ?? 'SnipeScheduler';
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

function encode_header(string $str): string
{
    if (preg_match('/[^\x20-\x7E]/', $str)) {
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }
    return $str;
}
