<?php
// image_proxy.php
//
// Simple, locked-down proxy for Snipe-IT model images.
// Accepts either:
//   ?src=/uploads/models/...
// or
//   ?src=https://inventory.highlands.ac.uk/uploads/models/...
//
// It will validate that the final URL points at the configured Snipe-IT host,
// then fetch the image and stream it back.

$config   = require __DIR__ . '/config.php';
$snipeCfg = $config['snipeit'] ?? [];

$baseUrl   = rtrim($snipeCfg['base_url'] ?? '', '/');
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

$src = urldecode($srcParam);

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

if (!$baseHost || !$srcHost || strcasecmp($baseHost, $srcHost) !== 0) {
    http_response_code(400);
    echo 'Invalid src parameter (host mismatch)';
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
