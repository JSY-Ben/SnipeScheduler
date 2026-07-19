<?php
// image_proxy.php
//
// Simple, locked-down proxy for Snipe-IT images and signed user avatars.
// Accepts either:
//   ?src=/uploads/models/...
// or
//   ?src=https://snipeit.example.com/uploads/models/...
//
// Normal images must point at the configured Snipe-IT host. Avatar URLs may
// point at an external host only when signed by the application.

require_once __DIR__ . '/../src/bootstrap.php';

$config   = load_config();
$snipeCfg = $config['snipeit'] ?? [];

$baseUrl   = rtrim($snipeCfg['base_url'] ?? '', '/');
$apiToken  = (string)($snipeCfg['api_token'] ?? '');
$verifySsl = !empty($snipeCfg['verify_ssl']);

// ---------------------------------------------------------------------
// Validate input
// ---------------------------------------------------------------------
$srcParam = $_GET['src'] ?? '';

if ($srcParam === '') {
    http_response_code(400);
    echo 'Missing src parameter';
    exit;
}

// PHP has already URL-decoded query parameters once. Decoding again would
// corrupt legitimate percent-encoded characters in upstream image URLs.
$src = (string)$srcParam;
$isSignedAvatar = isset($_GET['avatar']) && (string)$_GET['avatar'] === '1';

// Build full URL
if (preg_match('#^https?://#i', $src)) {
    // Already a full URL
    $url = $src;
} else {
    // Treat as relative path under Snipe-IT base URL
    if ($baseUrl === '') {
        http_response_code(500);
        echo 'Snipe-IT base URL not configured.';
        exit;
    }
    $url = $baseUrl . '/' . ltrim($src, '/');
}

// ---------------------------------------------------------------------
// Basic host validation (avoid proxying arbitrary sites)
// ---------------------------------------------------------------------
$baseHost = parse_url($baseUrl, PHP_URL_HOST);
$srcHost  = parse_url($url, PHP_URL_HOST);
$srcScheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
$hostMatchesSnipeIt = $baseHost && $srcHost && strcasecmp($baseHost, $srcHost) === 0;
$avatarSignature = trim((string)($_GET['sig'] ?? ''));
$expectedAvatarSignature = $apiToken !== '' ? hash_hmac('sha256', $url, $apiToken) : '';
$hasValidAvatarSignature = $isSignedAvatar
    && $expectedAvatarSignature !== ''
    && hash_equals($expectedAvatarSignature, $avatarSignature);

if (!in_array($srcScheme, ['http', 'https'], true) || (!$hostMatchesSnipeIt && !$hasValidAvatarSignature)) {
    http_response_code(400);
    echo 'Invalid src parameter';
    exit;
}

// ---------------------------------------------------------------------
// Fetch image from Snipe-IT
// ---------------------------------------------------------------------
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
}
if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
}

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(502);
    echo 'Error fetching image: ' . curl_error($ch);
    curl_close($ch);
    exit;
}

$headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

$body = substr($response, $headerSize);
curl_close($ch);

if ($httpCode >= 400) {
    http_response_code($httpCode);
    echo 'Error fetching image (HTTP ' . $httpCode . ')';
    exit;
}

if (!empty($contentType) && stripos($contentType, 'image/') !== 0) {
    http_response_code(502);
    echo 'Upstream response was not an image';
    exit;
}

// ---------------------------------------------------------------------
// Output image
// ---------------------------------------------------------------------
if (!empty($contentType)) {
    header('Content-Type: ' . $contentType);
} else {
    header('Content-Type: image/jpeg');
}

header('Cache-Control: public, max-age=86400');

echo $body;
