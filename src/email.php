<?php
// email.php
// Minimal SMTP sender for ReserveIT. Supports plain/SSL/TLS with LOGIN auth.

require_once __DIR__ . '/bootstrap.php';

/**
 * Send an email via SMTP using config values.
 *
 * @param string      $toEmail
 * @param string      $toName
 * @param string      $subject
 * @param string      $body      Plaintext body (UTF-8)
 * @param array|null  $cfg       Override config array (uses load_config() if null)
 * @return bool                   True on success, false on failure.
 */
function reserveit_send_mail(string $toEmail, string $toName, string $subject, string $body, ?array $cfg = null): bool
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
    $fromNm = $smtp['from_name'] ?? 'ReserveIT';

    if ($host === '' || $from === '') {
        error_log('ReserveIT SMTP not configured (host/from missing).');
        return false;
    }

    $remoteHost = ($enc === 'ssl' || $enc === 'tls') ? "ssl://{$host}" : $host;
    $fp = @stream_socket_client("{$remoteHost}:{$port}", $errno, $errstr, 10, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        error_log("ReserveIT SMTP connect failed: {$errstr}");
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
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
        $write($payload);
        $expectOk('250', $read);
        $write('QUIT');
        fclose($fp);
        return true;
    } catch (Throwable $e) {
        error_log('ReserveIT SMTP send failed: ' . $e->getMessage());
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
function reserveit_send_notification(string $toEmail, string $toName, string $subject, array $lines, ?array $cfg = null): bool
{
    $body = implode("\n", array_filter($lines, static function ($line) {
        return $line !== null && $line !== '';
    }));
    return reserveit_send_mail($toEmail, $toName, $subject, $body, $cfg);
}

function encode_header(string $str): string
{
    if (preg_match('/[^\x20-\x7E]/', $str)) {
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }
    return $str;
}
