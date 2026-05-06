<?php
// snipeit_client.php
//
// Thin client for talking to the Snipe-IT API.
// Uses config.php for base URL, API token and SSL verification settings.
//
// Exposes:
//   - get_bookable_models($page, $search, $categoryId, $sort, $perPage, $allowedCategoryIds, $modelIdAllowlist, $includeNonRequestable)
//   - get_model_categories($includeNonRequestable)
//   - get_model($id)
//   - get_model_hardware_count($modelId)

require_once __DIR__ . '/bootstrap.php';

$config       = load_config();
$snipeConfig  = $config['snipeit'] ?? [];

$snipeBaseUrl   = rtrim($snipeConfig['base_url'] ?? '', '/');
$snipeApiToken  = $snipeConfig['api_token'] ?? '';
$snipeVerifySsl = !empty($snipeConfig['verify_ssl']);

$limit = 200;

function snipeit_api_response_cache_table_exists(bool $refresh = false): bool
{
    static $exists = null;

    if ($exists !== null && !$refresh) {
        return $exists;
    }

    $exists = false;

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $pdo->query('SELECT 1 FROM snipeit_api_response_cache LIMIT 1');
        $exists = true;
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function snipeit_api_response_cache_get(string $cacheKey): ?array
{
    if (!snipeit_api_response_cache_table_exists()) {
        return null;
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $stmt = $pdo->prepare('SELECT response_payload FROM snipeit_api_response_cache WHERE cache_key = :cache_key LIMIT 1');
        $stmt->execute([':cache_key' => $cacheKey]);
        $raw = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }

    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function snipeit_api_response_cache_set(string $cacheKey, string $endpoint, array $params, array $data): void
{
    if (!snipeit_api_response_cache_table_exists()) {
        return;
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $stmt = $pdo->prepare("
            INSERT INTO snipeit_api_response_cache (
                cache_key,
                endpoint,
                request_params,
                response_payload,
                updated_at
            ) VALUES (
                :cache_key,
                :endpoint,
                :request_params,
                :response_payload,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                endpoint = VALUES(endpoint),
                request_params = VALUES(request_params),
                response_payload = VALUES(response_payload),
                updated_at = VALUES(updated_at)
        ");
        $stmt->execute([
            ':cache_key' => $cacheKey,
            ':endpoint' => ltrim($endpoint, '/'),
            ':request_params' => snipeit_json_encode_payload($params),
            ':response_payload' => snipeit_json_encode_payload($data),
        ]);
    } catch (Throwable $e) {
        return;
    }
}

function snipeit_api_response_cache_clear(): void
{
    if (!snipeit_api_response_cache_table_exists()) {
        return;
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $pdo->exec('DELETE FROM snipeit_api_response_cache');
    } catch (Throwable $e) {
        return;
    }
}

/**
 * Core HTTP wrapper for Snipe-IT API.
 *
 * @param string $method   HTTP method (GET, POST, etc.)
 * @param string $endpoint Relative endpoint, e.g. "models" or "models/5"
 * @param array  $params   Query/body params
 * @param bool   $allowResponseCache Read GET responses from the DB response cache before calling Snipe-IT.
 * @return array           Decoded JSON response
 * @throws Exception       On HTTP or decode errors
 */
function snipeit_request(string $method, string $endpoint, array $params = [], bool $allowResponseCache = true): array
{
    global $snipeBaseUrl, $snipeApiToken, $snipeVerifySsl;

    if ($snipeBaseUrl === '' || $snipeApiToken === '') {
        throw new Exception('Snipe-IT API is not configured (missing base_url or api_token).');
    }

    $url = $snipeBaseUrl . '/api/v1/' . ltrim($endpoint, '/');

    $method = strtoupper($method);
    $requestParamsJson = snipeit_json_encode_payload($params);
    $cacheKey = null;

    // DB-backed GET response cache for endpoints that do not have dedicated cache tables.
    if ($method === 'GET') {
        $cacheKey = sha1($url . '|' . $requestParamsJson);
        $cached = $allowResponseCache ? snipeit_api_response_cache_get($cacheKey) : null;
        if ($cached !== null) {
            return $cached;
        }
    }

    $ch = curl_init();
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $snipeApiToken,
    ];

    if ($method === 'GET') {
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
        $headers[] = 'Content-Type: application/json';
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => $snipeVerifySsl,
        CURLOPT_SSL_VERIFYHOST => $snipeVerifySsl ? 2 : 0,
    ]);

    $raw = curl_exec($ch);

    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Error talking to Snipe-IT API: ' . $err);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);

    if ($httpCode >= 400) {
        $msg = $decoded['message'] ?? $raw;
        throw new Exception('Snipe-IT API returned HTTP ' . $httpCode . ': ' . $msg);
    }

    if (!is_array($decoded)) {
        throw new Exception('Invalid JSON from Snipe-IT API');
    }

    if ($cacheKey !== null) {
        snipeit_api_response_cache_set($cacheKey, $endpoint, $params, $decoded);
    } elseif ($method !== 'GET') {
        snipeit_api_response_cache_clear();
    }

    return $decoded;
}

function app_version_string(): string
{
    static $version = null;

    if ($version !== null) {
        return $version;
    }

    $versionFile = APP_ROOT . '/version.txt';
    $raw = is_file($versionFile) ? @file_get_contents($versionFile) : false;
    $version = $raw !== false ? trim((string)$raw) : '';

    return $version;
}

function app_version_is_at_least(string $minimumVersion): bool
{
    $current = ltrim(trim(app_version_string()), "vV");
    $minimum = ltrim(trim($minimumVersion), "vV");

    if ($current === '' || $minimum === '') {
        return false;
    }

    return version_compare($current, $minimum, '>=');
}

function snipeit_catalogue_cache_tables_exist(bool $refresh = false): bool
{
    static $exists = null;

    if ($exists !== null && !$refresh) {
        return $exists;
    }

    $exists = false;
    if (!app_version_is_at_least('1.4.0')) {
        return $exists;
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $pdo->query('SELECT 1 FROM catalogue_model_cache LIMIT 1');
        $pdo->query('SELECT 1 FROM catalogue_asset_cache LIMIT 1');
        $pdo->query('SELECT 1 FROM catalogue_cache_meta LIMIT 1');
        $exists = true;
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function snipeit_catalogue_cache_tables_exist_for(array $tableNames, bool $refresh = false): bool
{
    static $existsByKey = [];

    $tableNames = array_values(array_filter(array_map(static function ($tableName): string {
        $clean = preg_replace('/[^A-Za-z0-9_]+/', '', (string)$tableName);
        return is_string($clean) ? $clean : '';
    }, $tableNames), 'strlen'));
    sort($tableNames);
    $cacheKey = implode('|', $tableNames);

    if ($cacheKey === '') {
        return false;
    }

    if (array_key_exists($cacheKey, $existsByKey) && !$refresh) {
        return $existsByKey[$cacheKey];
    }

    $existsByKey[$cacheKey] = false;
    if (!app_version_is_at_least('1.4.0')) {
        return false;
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        foreach (array_merge(['catalogue_cache_meta'], $tableNames) as $tableName) {
            $pdo->query("SELECT 1 FROM `{$tableName}` LIMIT 1");
        }
        $existsByKey[$cacheKey] = true;
    } catch (Throwable $e) {
        $existsByKey[$cacheKey] = false;
    }

    return $existsByKey[$cacheKey];
}

function snipeit_catalogue_accessory_cache_tables_exist(bool $refresh = false): bool
{
    return snipeit_catalogue_cache_tables_exist_for(['catalogue_accessory_cache'], $refresh);
}

function snipeit_catalogue_kit_cache_tables_exist(bool $refresh = false): bool
{
    return snipeit_catalogue_cache_tables_exist_for(['catalogue_kit_cache', 'catalogue_kit_item_cache'], $refresh);
}

function snipeit_catalogue_named_cache_is_synced(string $cacheKey, array $tableNames): bool
{
    if (!snipeit_catalogue_cache_tables_exist_for($tableNames)) {
        return false;
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $stmt = $pdo->prepare('SELECT synced_at FROM catalogue_cache_meta WHERE cache_key = :cache_key LIMIT 1');
        $stmt->execute([':cache_key' => $cacheKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return !empty($row['synced_at']);
    } catch (Throwable $e) {
        return false;
    }
}

function snipeit_catalogue_accessory_cache_is_synced(): bool
{
    return snipeit_catalogue_named_cache_is_synced('catalogue_accessories', ['catalogue_accessory_cache']);
}

function snipeit_catalogue_kit_cache_is_synced(): bool
{
    return snipeit_catalogue_named_cache_is_synced('catalogue_kits', ['catalogue_kit_cache', 'catalogue_kit_item_cache']);
}

/**
 * Check whether the local catalogue cache is available and has been populated.
 *
 * @return array{tables:bool,synced:bool}
 */
function snipeit_catalogue_cache_status(bool $refresh = false): array
{
    static $status = null;

    if ($status !== null && !$refresh) {
        return $status;
    }

    $status = [
        'tables' => false,
        'synced' => false,
    ];

    if (!snipeit_catalogue_cache_tables_exist($refresh)) {
        return $status;
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $stmt = $pdo->prepare('SELECT synced_at FROM catalogue_cache_meta WHERE cache_key = :cache_key LIMIT 1');
        $stmt->execute([':cache_key' => 'catalogue']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $status['tables'] = true;
        $status['synced'] = !empty($row['synced_at']);
    } catch (Throwable $e) {
        $status = [
            'tables' => false,
            'synced' => false,
        ];
    }

    return $status;
}

function snipeit_catalogue_cache_is_synced(): bool
{
    $status = snipeit_catalogue_cache_status();
    return !empty($status['tables']) && !empty($status['synced']);
}

function snipeit_catalogue_cache_has_non_requestable_models(): bool
{
    static $hasNonRequestable = null;

    if ($hasNonRequestable !== null) {
        return $hasNonRequestable;
    }

    if (!snipeit_catalogue_cache_is_synced()) {
        $hasNonRequestable = false;
        return $hasNonRequestable;
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $stmt = $pdo->query("SELECT 1 FROM catalogue_model_cache WHERE raw_payload LIKE '%\"requestable\":false%' LIMIT 1");
        $hasNonRequestable = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        $hasNonRequestable = false;
    }

    return $hasNonRequestable;
}

function snipeit_catalogue_show_non_requestable_equipment(?array $cfg = null): bool
{
    if ($cfg === null) {
        try {
            $cfg = load_config();
        } catch (Throwable $e) {
            $cfg = [];
        }
    }

    return !empty($cfg['catalogue']['show_non_requestable_equipment']);
}

function snipeit_json_encode_payload(array $payload): string
{
    $encoded = json_encode($payload);
    return is_string($encoded) ? $encoded : '{}';
}

function snipeit_extract_display_name($value): string
{
    if (is_array($value)) {
        $name = $value['name'] ?? ($value['label'] ?? ($value['text'] ?? ''));
        return trim((string)$name);
    }

    return trim((string)$value);
}

function snipeit_normalize_status_label($statusLabel): string
{
    if (is_array($statusLabel)) {
        $statusLabel = $statusLabel['name'] ?? ($statusLabel['status_meta'] ?? ($statusLabel['label'] ?? ''));
    }

    return trim((string)$statusLabel);
}

function snipeit_status_label_key(string $statusLabel): string
{
    $statusLabel = trim($statusLabel);
    if ($statusLabel === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($statusLabel, 'UTF-8');
    }

    return strtolower($statusLabel);
}

/**
 * @return array<string,string> Case-folded status label => original status label
 */
function snipeit_catalogue_allowed_status_labels(?array $cfg = null): array
{
    if ($cfg === null) {
        global $config;
        $cfg = is_array($config ?? null) ? $config : [];
    }

    $raw = $cfg['catalogue']['allowed_status_labels'] ?? [];
    if (!is_array($raw) || empty($raw)) {
        return [];
    }

    $allowed = [];
    foreach ($raw as $labelRaw) {
        $label = snipeit_normalize_status_label($labelRaw);
        if ($label === '') {
            continue;
        }

        $allowed[snipeit_status_label_key($label)] = $label;
    }

    return $allowed;
}

/**
 * @param array<string,string>|null $allowedStatusLabels
 */
function snipeit_status_label_is_allowed($statusLabel, ?array $allowedStatusLabels = null): bool
{
    $allowedMap = $allowedStatusLabels ?? snipeit_catalogue_allowed_status_labels();
    if (empty($allowedMap)) {
        return true;
    }

    $normalized = snipeit_normalize_status_label($statusLabel);
    if ($normalized === '') {
        return false;
    }

    return isset($allowedMap[snipeit_status_label_key($normalized)]);
}

/**
 * @param array<string,string>|null $allowedStatusLabels
 */
function snipeit_asset_allowed_for_catalogue_availability(array $asset, ?array $allowedStatusLabels = null): bool
{
    if (empty($asset['requestable'])) {
        return false;
    }

    return snipeit_status_label_is_allowed($asset['status_label'] ?? '', $allowedStatusLabels);
}

/**
 * @return array{id:int,name:string,email:string,username:string}
 */
function snipeit_extract_assigned_user_fields($assigned): array
{
    $result = [
        'id' => 0,
        'name' => '',
        'email' => '',
        'username' => '',
    ];

    if (is_array($assigned)) {
        $result['id'] = (int)($assigned['id'] ?? 0);
        $result['name'] = trim((string)($assigned['name'] ?? ($assigned['username'] ?? '')));
        $result['email'] = trim((string)($assigned['email'] ?? ''));
        $result['username'] = trim((string)($assigned['username'] ?? ''));
        return $result;
    }

    $name = trim((string)$assigned);
    if ($name !== '') {
        $result['name'] = $name;
    }

    return $result;
}

function snipeit_extract_default_location_name(array $asset): string
{
    $candidates = [
        $asset['rtd_location'] ?? null,
        $asset['default_location'] ?? null,
        $asset['location'] ?? null,
        $asset['default_loc'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        $name = snipeit_extract_display_name($candidate);
        if ($name !== '') {
            return $name;
        }
    }

    return 'No default location';
}

function snipeit_build_cached_model_payload(array $row): array
{
    $payload = [];
    $raw = trim((string)($row['raw_payload'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $modelId = (int)($row['model_id'] ?? 0);
    $categoryId = isset($row['category_id']) ? (int)$row['category_id'] : 0;
    $categoryName = trim((string)($row['category_name'] ?? ''));
    $manufacturerName = trim((string)($row['manufacturer_name'] ?? ''));

    $payload['id'] = $modelId;
    $payload['name'] = $payload['name'] ?? ($row['model_name'] ?? '');
    $payload['image'] = $payload['image'] ?? ($row['image_path'] ?? '');
    $payload['notes'] = $payload['notes'] ?? ($row['notes_text'] ?? '');
    $payload['assets_count'] = isset($payload['assets_count']) && is_numeric($payload['assets_count'])
        ? (int)$payload['assets_count']
        : (int)($row['total_asset_count'] ?? 0);
    $payload['assets_count_total'] = isset($payload['assets_count_total']) && is_numeric($payload['assets_count_total'])
        ? (int)$payload['assets_count_total']
        : (int)($row['total_asset_count'] ?? 0);
    $payload['requestable'] = array_key_exists('requestable', $payload) ? !empty($payload['requestable']) : true;
    $payload['requestable_asset_count'] = (int)($row['requestable_asset_count'] ?? 0);

    if (!isset($payload['manufacturer']) || !is_array($payload['manufacturer'])) {
        $payload['manufacturer'] = [];
    }
    if ($manufacturerName !== '') {
        $payload['manufacturer']['name'] = $manufacturerName;
    }

    if (!isset($payload['category']) || !is_array($payload['category'])) {
        $payload['category'] = [];
    }
    if ($categoryId > 0) {
        $payload['category']['id'] = $categoryId;
    }
    if ($categoryName !== '') {
        $payload['category']['name'] = $categoryName;
    }

    return $payload;
}

function snipeit_cached_model_row_is_requestable(array $row): bool
{
    $raw = trim((string)($row['raw_payload'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && array_key_exists('requestable', $decoded)) {
            return !empty($decoded['requestable']);
        }
    }

    return true;
}

function snipeit_build_cached_asset_payload(array $row): array
{
    $payload = [];
    $raw = trim((string)($row['raw_payload'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $assetId = (int)($row['asset_id'] ?? 0);
    $modelId = (int)($row['model_id'] ?? 0);
    $assigned = [
        'id' => (int)($row['assigned_to_id'] ?? 0),
        'name' => trim((string)($row['assigned_to_name'] ?? '')),
        'email' => trim((string)($row['assigned_to_email'] ?? '')),
        'username' => trim((string)($row['assigned_to_username'] ?? '')),
    ];
    $assigned = array_filter($assigned, static function ($value): bool {
        if (is_int($value)) {
            return $value > 0;
        }
        return trim((string)$value) !== '';
    });

    $payload['id'] = $assetId;
    $payload['asset_tag'] = $payload['asset_tag'] ?? ($row['asset_tag'] ?? '');
    $payload['name'] = $payload['name'] ?? ($row['asset_name'] ?? '');
    $payload['requestable'] = !empty($row['requestable']);

    if (!isset($payload['model']) || !is_array($payload['model'])) {
        $payload['model'] = [];
    }
    if ($modelId > 0) {
        $payload['model']['id'] = $modelId;
    }
    if (trim((string)($row['model_name'] ?? '')) !== '') {
        $payload['model']['name'] = $row['model_name'];
    }

    if (!isset($payload['status_label']) || $payload['status_label'] === '') {
        $payload['status_label'] = (string)($row['status_label'] ?? '');
    }

    if (!empty($assigned)) {
        $payload['assigned_to'] = $assigned;
    } elseif (!isset($payload['assigned_to']) && trim((string)($row['assigned_to_name'] ?? '')) !== '') {
        $payload['assigned_to_fullname'] = $row['assigned_to_name'];
    }

    $locationName = trim((string)($row['default_location_name'] ?? ''));
    if (
        $locationName !== ''
        && empty($payload['rtd_location'])
        && empty($payload['default_location'])
        && empty($payload['location'])
        && empty($payload['default_loc'])
    ) {
        $payload['default_location'] = ['name' => $locationName];
    }

    return $payload;
}

function snipeit_build_cached_accessory_payload(array $row): array
{
    $payload = [];
    $raw = trim((string)($row['raw_payload'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $accessoryId = (int)($row['accessory_id'] ?? 0);
    $categoryId = isset($row['category_id']) ? (int)$row['category_id'] : 0;
    $categoryName = trim((string)($row['category_name'] ?? ''));
    $manufacturerName = trim((string)($row['manufacturer_name'] ?? ''));

    $payload['id'] = $accessoryId;
    $payload['name'] = $payload['name'] ?? ($row['accessory_name'] ?? '');
    $payload['image'] = $payload['image'] ?? ($row['image_path'] ?? '');
    $payload['notes'] = $payload['notes'] ?? ($row['notes_text'] ?? '');
    $payload['qty'] = isset($payload['qty']) && is_numeric($payload['qty'])
        ? (int)$payload['qty']
        : (int)($row['total_quantity'] ?? 0);
    $payload['available_qty'] = isset($payload['available_qty']) && is_numeric($payload['available_qty'])
        ? (int)$payload['available_qty']
        : (int)($row['available_quantity'] ?? 0);

    if (!isset($payload['manufacturer']) || !is_array($payload['manufacturer'])) {
        $payload['manufacturer'] = [];
    }
    if ($manufacturerName !== '') {
        $payload['manufacturer']['name'] = $manufacturerName;
    }

    if (!isset($payload['category']) || !is_array($payload['category'])) {
        $payload['category'] = [];
    }
    if ($categoryId > 0) {
        $payload['category']['id'] = $categoryId;
    }
    if ($categoryName !== '') {
        $payload['category']['name'] = $categoryName;
    }

    return $payload;
}

function snipeit_build_cached_kit_payload(array $row): array
{
    $payload = [];
    $raw = trim((string)($row['raw_payload'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $payload['id'] = (int)($row['kit_id'] ?? 0);
    $payload['name'] = $payload['name'] ?? ($row['kit_name'] ?? '');
    $payload['image'] = $payload['image'] ?? ($row['image_path'] ?? '');
    $payload['notes'] = $payload['notes'] ?? ($row['notes_text'] ?? '');

    return $payload;
}

function snipeit_build_cached_kit_item_payload(array $row): array
{
    $payload = [];
    $raw = trim((string)($row['raw_payload'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $payload['id'] = (int)($row['item_id'] ?? 0);
    $payload['name'] = $payload['name'] ?? ($row['item_name'] ?? '');
    $quantity = (int)($row['quantity'] ?? 1);
    if (!isset($payload['quantity']) || !is_numeric($payload['quantity'])) {
        $payload['quantity'] = max(1, $quantity);
    }
    if (!isset($payload['qty']) || !is_numeric($payload['qty'])) {
        $payload['qty'] = max(1, $quantity);
    }

    return $payload;
}

function snipeit_get_cached_model_row(int $modelId): ?array
{
    static $rowCache = [];

    if ($modelId <= 0 || !snipeit_catalogue_cache_is_synced()) {
        return null;
    }

    if (array_key_exists($modelId, $rowCache)) {
        return $rowCache[$modelId];
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $stmt = $pdo->prepare("
            SELECT
                model_id,
                model_name,
                manufacturer_name,
                category_id,
                category_name,
                image_path,
                notes_text,
                total_asset_count,
                requestable_asset_count,
                raw_payload
            FROM catalogue_model_cache
            WHERE model_id = :model_id
            LIMIT 1
        ");
        $stmt->execute([':model_id' => $modelId]);
        $rowCache[$modelId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $rowCache[$modelId] = null;
    }

    return $rowCache[$modelId];
}

function snipeit_get_cached_bookable_models(
    int $page = 1,
    string $search = '',
    ?int $categoryId = null,
    ?string $sort = null,
    int $perPage = 50,
    array $allowedCategoryIds = [],
    array $modelIdAllowlist = [],
    bool $includeNonRequestable = false
): ?array {
    if (!snipeit_catalogue_cache_is_synced()) {
        return null;
    }
    if ($includeNonRequestable && !snipeit_catalogue_cache_has_non_requestable_models()) {
        return null;
    }

    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $allowedIds = [];
    foreach ($allowedCategoryIds as $cid) {
        if (ctype_digit((string)$cid) || is_int($cid)) {
            $allowedIds[] = (int)$cid;
        }
    }
    $allowedIds = array_values(array_unique($allowedIds));

    $allowedModelIds = [];
    foreach ($modelIdAllowlist as $mid) {
        if (ctype_digit((string)$mid) || is_int($mid)) {
            $mid = (int)$mid;
            if ($mid > 0) {
                $allowedModelIds[] = $mid;
            }
        }
    }
    $allowedModelIds = array_values(array_unique($allowedModelIds));

    $effectiveCategory = $categoryId;
    if (!empty($allowedIds) && $effectiveCategory !== null && !in_array($effectiveCategory, $allowedIds, true)) {
        $effectiveCategory = null;
    }

    $where = [];
    $params = [];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(model_name LIKE ? OR manufacturer_name LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }

    if ($effectiveCategory !== null) {
        $where[] = 'category_id = ?';
        $params[] = $effectiveCategory;
    }

    if (!empty($allowedIds)) {
        $placeholders = implode(',', array_fill(0, count($allowedIds), '?'));
        $where[] = "category_id IN ({$placeholders})";
        foreach ($allowedIds as $cid) {
            $params[] = $cid;
        }
    }

    if (!empty($allowedModelIds)) {
        $placeholders = implode(',', array_fill(0, count($allowedModelIds), '?'));
        $where[] = "model_id IN ({$placeholders})";
        foreach ($allowedModelIds as $mid) {
            $params[] = $mid;
        }
    }

    $whereSql = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));
    $sortKey = (string)($sort ?? '');
    switch ($sortKey) {
        case 'manu_asc':
            $orderBy = ' ORDER BY manufacturer_name ASC, model_name ASC';
            break;
        case 'manu_desc':
            $orderBy = ' ORDER BY manufacturer_name DESC, model_name ASC';
            break;
        case 'name_desc':
            $orderBy = ' ORDER BY model_name DESC';
            break;
        case 'units_asc':
            $orderBy = ' ORDER BY total_asset_count ASC, model_name ASC';
            break;
        case 'units_desc':
            $orderBy = ' ORDER BY total_asset_count DESC, model_name ASC';
            break;
        case 'name_asc':
        default:
            $orderBy = ' ORDER BY model_name ASC';
            break;
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $offset = ($page - 1) * $perPage;
        $sql = "
            SELECT
                model_id,
                model_name,
                manufacturer_name,
                category_id,
                category_name,
                image_path,
                notes_text,
                total_asset_count,
                requestable_asset_count,
                raw_payload
            FROM catalogue_model_cache
            {$whereSql}
            {$orderBy}
        ";
        if ($includeNonRequestable) {
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM catalogue_model_cache' . $whereSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql .= ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$includeNonRequestable) {
            $rows = array_values(array_filter($rows, 'snipeit_cached_model_row_is_requestable'));
            $total = count($rows);
            $rows = array_slice($rows, $offset, $perPage);
        }

        $models = [];
        foreach ($rows as $row) {
            $models[] = snipeit_build_cached_model_payload($row);
        }

        return [
            'total' => $total,
            'rows' => $models,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function snipeit_get_cached_model_categories(bool $includeNonRequestable = false): ?array
{
    if (!snipeit_catalogue_cache_is_synced()) {
        return null;
    }
    if ($includeNonRequestable && !snipeit_catalogue_cache_has_non_requestable_models()) {
        return null;
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $stmt = $pdo->query("
            SELECT
                category_id AS id,
                category_name AS name,
                raw_payload
            FROM catalogue_model_cache
            WHERE category_id IS NOT NULL
              AND category_id > 0
              AND category_name IS NOT NULL
              AND category_name <> ''
            ORDER BY category_name ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $categoriesById = [];
        foreach ($rows as $row) {
            if (!$includeNonRequestable && !snipeit_cached_model_row_is_requestable($row)) {
                continue;
            }

            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if (!isset($categoriesById[$id])) {
                $categoriesById[$id] = [
                    'id' => $id,
                    'name' => (string)($row['name'] ?? ''),
                    'category_type' => 'asset',
                    'requestable_count' => 0,
                ];
            }

            $categoriesById[$id]['requestable_count']++;
        }

        $categories = array_values($categoriesById);
        usort($categories, static function ($a, $b): int {
            return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        return $categories;
    } catch (Throwable $e) {
        return null;
    }
}

function snipeit_get_cached_accessory_categories(): ?array
{
    $rows = snipeit_get_cached_accessories();
    return $rows !== null ? snipeit_collect_category_options($rows) : null;
}

function snipeit_get_cached_accessories(string $search = ''): ?array
{
    static $cache = [];

    if (!snipeit_catalogue_accessory_cache_is_synced()) {
        return null;
    }

    $cacheKey = strtolower($search);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $where = [];
        $params = [];
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(accessory_name LIKE ? OR manufacturer_name LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));
        $stmt = $pdo->prepare("
            SELECT
                accessory_id,
                accessory_name,
                manufacturer_name,
                category_id,
                category_name,
                image_path,
                notes_text,
                total_quantity,
                available_quantity,
                raw_payload
            FROM catalogue_accessory_cache
            {$whereSql}
            ORDER BY accessory_name ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $accessories = [];
        foreach ($rows as $row) {
            $accessories[] = snipeit_build_cached_accessory_payload($row);
        }

        $cache[$cacheKey] = $accessories;
        return $accessories;
    } catch (Throwable $e) {
        return null;
    }
}

function snipeit_get_cached_kits(string $search = ''): ?array
{
    static $cache = [];

    if (!snipeit_catalogue_kit_cache_is_synced()) {
        return null;
    }

    $cacheKey = strtolower($search);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $where = [];
        $params = [];
        if ($search !== '') {
            $where[] = 'kit_name LIKE ?';
            $params[] = '%' . $search . '%';
        }

        $whereSql = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));
        $stmt = $pdo->prepare("
            SELECT
                kit_id,
                kit_name,
                image_path,
                notes_text,
                raw_payload
            FROM catalogue_kit_cache
            {$whereSql}
            ORDER BY kit_name ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $kits = [];
        foreach ($rows as $row) {
            $kits[] = snipeit_build_cached_kit_payload($row);
        }

        $cache[$cacheKey] = $kits;
        return $kits;
    } catch (Throwable $e) {
        return null;
    }
}

function snipeit_kit_item_type_from_field(string $field): string
{
    $field = strtolower(trim($field));
    $map = [
        'models' => 'model',
        'model' => 'model',
        'accessories' => 'accessory',
        'accessory' => 'accessory',
        'licenses' => 'license',
        'licences' => 'license',
        'license' => 'license',
        'licence' => 'license',
        'consumables' => 'consumable',
        'consumable' => 'consumable',
    ];

    return $map[$field] ?? $field;
}

function snipeit_get_cached_kit_element_rows(int $kitId, string $field): ?array
{
    if ($kitId <= 0 || !snipeit_catalogue_kit_cache_is_synced()) {
        return null;
    }

    $itemType = snipeit_kit_item_type_from_field($field);
    if ($itemType === '') {
        return [];
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $stmt = $pdo->prepare("
            SELECT
                kit_id,
                item_type,
                item_id,
                item_name,
                quantity,
                raw_payload
            FROM catalogue_kit_item_cache
            WHERE kit_id = :kit_id
              AND item_type = :item_type
            ORDER BY item_name ASC, item_id ASC
        ");
        $stmt->execute([
            ':kit_id' => $kitId,
            ':item_type' => $itemType,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = snipeit_build_cached_kit_item_payload($row);
        }

        return $items;
    } catch (Throwable $e) {
        return null;
    }
}

function snipeit_get_cached_asset_status_labels(): ?array
{
    if (!snipeit_catalogue_cache_is_synced()) {
        return null;
    }

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $stmt = $pdo->query("
            SELECT
                status_label AS name,
                COUNT(*) AS asset_count
            FROM catalogue_asset_cache
            WHERE status_label IS NOT NULL
              AND status_label <> ''
            GROUP BY status_label
            ORDER BY status_label ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $statuses = [];
        foreach ($rows as $row) {
            $name = snipeit_normalize_status_label($row['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $statuses[] = [
                'id' => 0,
                'name' => $name,
                'asset_count' => (int)($row['asset_count'] ?? 0),
            ];
        }

        return $statuses;
    } catch (Throwable $e) {
        return null;
    }
}

function snipeit_get_cached_model(int $modelId): ?array
{
    $row = snipeit_get_cached_model_row($modelId);
    return $row !== null ? snipeit_build_cached_model_payload($row) : null;
}

function snipeit_get_cached_assets_by_model(int $modelId, int $maxResults = 300): ?array
{
    if ($modelId <= 0 || !snipeit_catalogue_cache_is_synced()) {
        return null;
    }

    if (snipeit_get_cached_model_row($modelId) === null) {
        return null;
    }

    $maxResults = max(1, $maxResults);

    try {
        require_once SRC_PATH . '/db.php';
        global $pdo;

        $sql = "
            SELECT
                asset_id,
                model_id,
                model_name,
                asset_tag,
                asset_name,
                requestable,
                status_label,
                assigned_to_id,
                assigned_to_name,
                assigned_to_email,
                assigned_to_username,
                default_location_name,
                raw_payload
            FROM catalogue_asset_cache
            WHERE model_id = :model_id
            ORDER BY requestable DESC, asset_tag ASC, asset_id ASC
            LIMIT " . (int)$maxResults;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':model_id' => $modelId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $assets = [];
        foreach ($rows as $row) {
            $assets[] = snipeit_build_cached_asset_payload($row);
        }

        return $assets;
    } catch (Throwable $e) {
        return null;
    }
}

function snipeit_get_cached_requestable_asset_count(int $modelId): ?int
{
    if ($modelId <= 0 || !snipeit_catalogue_cache_is_synced()) {
        return null;
    }

    $row = snipeit_get_cached_model_row($modelId);
    if ($row === null) {
        return null;
    }

    return (int)($row['requestable_asset_count'] ?? 0);
}

/**
 * Fetch all matching model rows directly from Snipe-IT.
 *
 * @return array
 * @throws Exception
 */
function fetch_all_models_from_snipeit(string $search = '', ?int $categoryId = null, bool $allowResponseCache = true): array
{
    $limit  = 200;
    $allRows = [];
    $offset = 0;

    do {
        $params = [
            'limit'  => $limit,
            'offset' => $offset,
        ];

        if ($search !== '') {
            $params['search'] = $search;
        }

        if ($categoryId !== null && $categoryId > 0) {
            $params['category_id'] = $categoryId;
        }

        $chunk = snipeit_request('GET', 'models', $params, $allowResponseCache);
        $rows = isset($chunk['rows']) && is_array($chunk['rows']) ? $chunk['rows'] : [];
        if (empty($rows)) {
            break;
        }

        $allRows = array_merge($allRows, $rows);
        $fetchedThisCall = count($rows);
        $offset += $limit;

        if ($fetchedThisCall < $limit) {
            break;
        }
    } while (true);

    return $allRows;
}

/**
 * Fetch model categories directly from Snipe-IT.
 *
 * @return array
 * @throws Exception
 */
function fetch_model_categories_from_snipeit(bool $allowResponseCache = true, bool $includeNonRequestable = false): array
{
    $params = [
        'limit' => 500,
    ];

    $data = snipeit_request('GET', 'categories', $params, $allowResponseCache);

    if (!isset($data['rows']) || !is_array($data['rows'])) {
        return [];
    }

    $rows = $data['rows'];
    if (!$includeNonRequestable) {
        $rows = array_values(array_filter($rows, function ($row) {
            if (isset($row['requestable_count']) && is_numeric($row['requestable_count'])) {
                return (int)$row['requestable_count'] > 0;
            }
            return true;
        }));
    }

    usort($rows, function ($a, $b) {
        $na = $a['name'] ?? '';
        $nb = $b['name'] ?? '';
        return strcasecmp($na, $nb);
    });

    return $rows;
}

function snipeit_extract_category_id(array $row): int
{
    $category = $row['category'] ?? null;
    if (is_array($category) && isset($category['id']) && is_numeric($category['id'])) {
        return max(0, (int)$category['id']);
    }

    if (isset($row['category_id']) && is_numeric($row['category_id'])) {
        return max(0, (int)$row['category_id']);
    }

    return 0;
}

function snipeit_extract_category_name(array $row): string
{
    $category = $row['category'] ?? null;
    if (is_array($category)) {
        $candidates = [
            $category['name'] ?? null,
            $category['category_name'] ?? null,
            $category['label'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }
    } elseif (is_string($category) && trim($category) !== '') {
        return trim($category);
    }

    $candidates = [
        $row['category_name'] ?? null,
        $row['category_label'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }
    }

    return '';
}

function snipeit_normalize_category_type($categoryType): string
{
    $type = snipeit_extract_display_name($categoryType);
    if ($type === '') {
        return '';
    }

    $type = strtolower(str_replace(['_', '-'], ' ', $type));
    $type = trim((string)preg_replace('/\s+/', ' ', $type));

    $aliases = [
        'asset model' => 'asset',
        'assets' => 'asset',
        'hardware' => 'asset',
        'accessories' => 'accessory',
        'licenses' => 'license',
        'components' => 'component',
        'consumables' => 'consumable',
    ];

    return $aliases[$type] ?? $type;
}

function snipeit_extract_category_type(array $row): string
{
    $category = $row['category'] ?? null;
    $candidates = [];
    if (is_array($category)) {
        $candidates[] = $category['category_type'] ?? null;
        $candidates[] = $category['categoryType'] ?? null;
    }

    $candidates[] = $row['category_type'] ?? null;
    $candidates[] = $row['categoryType'] ?? null;

    foreach ($candidates as $candidate) {
        $type = snipeit_normalize_category_type($candidate);
        if ($type !== '') {
            return $type;
        }
    }

    return '';
}

function snipeit_category_filter_value_from_parts(int $categoryId, string $categoryName): string
{
    if ($categoryId > 0) {
        return 'id:' . $categoryId;
    }

    $categoryName = trim($categoryName);
    if ($categoryName !== '') {
        return 'name:' . strtolower($categoryName);
    }

    return 'uncategorized';
}

function snipeit_category_filter_value(array $row): string
{
    return snipeit_category_filter_value_from_parts(
        snipeit_extract_category_id($row),
        snipeit_extract_category_name($row)
    );
}

function snipeit_category_filter_values(array $row): array
{
    $values = [];

    if (array_key_exists('value', $row)) {
        foreach (snipeit_normalize_category_filter_values([$row['value']]) as $value) {
            $values[$value] = $value;
        }
    }

    $categoryId = snipeit_extract_category_id($row);
    if ($categoryId > 0) {
        $values['id:' . $categoryId] = 'id:' . $categoryId;
    }

    $categoryName = snipeit_extract_category_name($row);
    if ($categoryName === '' && array_key_exists('label', $row)) {
        $categoryName = trim((string)$row['label']);
    }
    if ($categoryName !== '') {
        $nameValue = 'name:' . strtolower($categoryName);
        $values[$nameValue] = $nameValue;
    }

    if (empty($values)) {
        $values['uncategorized'] = 'uncategorized';
    }

    return array_values($values);
}

function snipeit_category_filter_matches(array $row, string $categoryFilter): bool
{
    $normalizedFilters = snipeit_normalize_category_filter_values([$categoryFilter]);
    if (empty($normalizedFilters)) {
        return true;
    }

    $rowValues = snipeit_category_filter_values($row);
    foreach ($normalizedFilters as $filterValue) {
        if (in_array($filterValue, $rowValues, true)) {
            return true;
        }
    }

    return false;
}

function snipeit_normalize_category_filter_values($values): array
{
    if (!is_array($values)) {
        return [];
    }

    $normalized = [];
    foreach ($values as $rawValue) {
        if (is_int($rawValue) || (is_string($rawValue) && ctype_digit($rawValue))) {
            $value = 'id:' . max(0, (int)$rawValue);
        } else {
            $value = strtolower(trim((string)$rawValue));
            if ($value === '') {
                continue;
            }

            if ($value !== 'uncategorized' && !str_starts_with($value, 'id:') && !str_starts_with($value, 'name:')) {
                $value = 'name:' . $value;
            }
        }

        if ($value === 'id:0') {
            continue;
        }

        $normalized[$value] = $value;
    }

    return array_values($normalized);
}

function snipeit_collect_category_options(array $rows): array
{
    $options = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $categoryId = snipeit_extract_category_id($row);
        $categoryName = snipeit_extract_category_name($row);
        $value = snipeit_category_filter_value_from_parts($categoryId, $categoryName);
        if (isset($options[$value])) {
            continue;
        }

        $options[$value] = [
            'id' => $categoryId,
            'name' => $categoryName !== '' ? $categoryName : 'Uncategorised',
            'value' => $value,
            'label' => $categoryName !== '' ? $categoryName : 'Uncategorised',
        ];
    }

    uasort($options, static function (array $a, array $b): int {
        $aIsUncategorised = ($a['value'] ?? '') === 'uncategorized';
        $bIsUncategorised = ($b['value'] ?? '') === 'uncategorized';
        if ($aIsUncategorised !== $bIsUncategorised) {
            return $aIsUncategorised ? 1 : -1;
        }

        return strcasecmp((string)($a['label'] ?? ''), (string)($b['label'] ?? ''));
    });

    return array_values($options);
}

/**
 * Fetch asset status labels from Snipe-IT.
 *
 * @return array
 * @throws Exception
 */
function fetch_status_labels_from_snipeit(bool $allowResponseCache = true): array
{
    $limit = 200;
    $offset = 0;
    $statusByKey = [];

    do {
        $params = [
            'limit' => $limit,
            'offset' => $offset,
        ];
        $data = snipeit_request('GET', 'statuslabels', $params, $allowResponseCache);
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        if (empty($rows)) {
            break;
        }

        foreach ($rows as $row) {
            $name = snipeit_normalize_status_label($row['name'] ?? ($row['label'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = snipeit_status_label_key($name);
            if ($key === '' || isset($statusByKey[$key])) {
                continue;
            }

            $statusByKey[$key] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => $name,
            ];
        }

        if (count($rows) < $limit) {
            break;
        }
        $offset += $limit;
    } while (true);

    $result = array_values($statusByKey);
    usort($result, static function (array $a, array $b): int {
        return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });

    return $result;
}

/**
 * Fetch all hardware rows directly from Snipe-IT.
 *
 * @param int $maxResults
 * @return array
 * @throws Exception
 */
function fetch_all_hardware_from_snipeit(int $maxResults = 0, bool $allowResponseCache = true): array
{
    if ($maxResults <= 0) {
        $maxResults = PHP_INT_MAX;
    }

    $all = [];
    $limit = min(200, $maxResults);
    $offset = 0;

    do {
        $params = [
            'limit'  => $limit,
            'offset' => $offset,
        ];

        $data = snipeit_request('GET', 'hardware', $params, $allowResponseCache);
        $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];
        if (empty($rows)) {
            break;
        }

        $all = array_merge($all, $rows);
        $count = count($rows);
        $offset += $limit;

        if ($count < $limit || count($all) >= $maxResults) {
            break;
        }
    } while (true);

    if (count($all) > $maxResults) {
        $all = array_slice($all, 0, $maxResults);
    }

    return $all;
}

/**
 * Fetch **all** matching models from Snipe-IT,
 * then sort them as requested, then paginate locally.
 *
 * Sort options:
 *   - manu_asc / manu_desc      (manufacturer)
 *   - name_asc / name_desc      (model name)
 *   - units_asc / units_desc    (assets_count)
 *
 * @param int         $page
 * @param string      $search
 * @param int|null    $categoryId
 * @param string|null $sort
 * @param int         $perPage
 * @param array       $allowedCategoryIds Optional allowlist; if provided, only models in these category IDs are returned.
 * @param array       $modelIdAllowlist Optional allowlist; if provided, only models with these IDs are returned.
 * @return array                  ['total' => X, 'rows' => [...]]
 * @throws Exception
 */
function get_bookable_models(
    int $page = 1,
    string $search = '',
    ?int $categoryId = null,
    ?string $sort = null,
    int $perPage = 50,
    array $allowedCategoryIds = [],
    array $modelIdAllowlist = [],
    bool $includeNonRequestable = false
): array {
    $cached = snipeit_get_cached_bookable_models(
        $page,
        $search,
        $categoryId,
        $sort,
        $perPage,
        $allowedCategoryIds,
        $modelIdAllowlist,
        $includeNonRequestable
    );
    if ($cached !== null) {
        return $cached;
    }

    $page    = max(1, $page);
    $perPage = max(1, $perPage);
    $allowedMap = [];
    foreach ($allowedCategoryIds as $cid) {
        if (ctype_digit((string)$cid) || is_int($cid)) {
            $allowedMap[(int)$cid] = true;
        }
    }
    $allowedModelMap = [];
    foreach ($modelIdAllowlist as $mid) {
        if (ctype_digit((string)$mid) || is_int($mid)) {
            $mid = (int)$mid;
            if ($mid > 0) {
                $allowedModelMap[$mid] = true;
            }
        }
    }

    // If an allowlist exists and the requested category is not allowed, clear it to avoid wasted calls.
    $effectiveCategory = $categoryId;
    if (!empty($allowedMap) && $categoryId !== null && !isset($allowedMap[$categoryId])) {
        $effectiveCategory = null;
    }

    $allRows = fetch_all_models_from_snipeit($search, $effectiveCategory);

    // Filter by requestable flag (Snipe-IT uses 'requestable' on models)
    if (!$includeNonRequestable) {
        $allRows = array_values(array_filter($allRows, function ($row) {
            return !empty($row['requestable']);
        }));
    }

    // Apply optional category allowlist (overrides requestable-only default scope)
    if (!empty($allowedMap)) {
        $allRows = array_values(array_filter($allRows, function ($row) use ($allowedMap) {
            $cid = isset($row['category']['id']) ? (int)$row['category']['id'] : 0;
            return $cid > 0 && isset($allowedMap[$cid]);
        }));
    }

    if (!empty($allowedModelMap)) {
        $allRows = array_values(array_filter($allRows, function ($row) use ($allowedModelMap) {
            $modelId = isset($row['id']) ? (int)$row['id'] : 0;
            return $modelId > 0 && isset($allowedModelMap[$modelId]);
        }));
    }

    // Determine total after filtering
    $total = count($allRows);

    // Sort full set client-side according to requested sort
    $sort = $sort ?? '';

    usort($allRows, function ($a, $b) use ($sort) {
        $nameA  = $a['name'] ?? '';
        $nameB  = $b['name'] ?? '';
        $manA   = $a['manufacturer']['name'] ?? '';
        $manB   = $b['manufacturer']['name'] ?? '';
        $unitsA = isset($a['assets_count']) ? (int)$a['assets_count'] : 0;
        $unitsB = isset($b['assets_count']) ? (int)$b['assets_count'] : 0;

        switch ($sort) {
            case 'manu_asc':
                return strcasecmp($manA, $manB);
            case 'manu_desc':
                return strcasecmp($manB, $manA);

            case 'name_desc':
                return strcasecmp($nameB, $nameA);
            case 'name_asc':
            case '':
                return strcasecmp($nameA, $nameB);

            case 'units_asc':
                if ($unitsA === $unitsB) {
                    return strcasecmp($nameA, $nameB);
                }
                return ($unitsA <=> $unitsB);

            case 'units_desc':
                if ($unitsA === $unitsB) {
                    return strcasecmp($nameA, $nameB);
                }
                return ($unitsB <=> $unitsA);

            default:
                return strcasecmp($nameA, $nameB);
        }
    });

    // Local pagination
    $offsetLocal = ($page - 1) * $perPage;
    $rowsPage    = array_slice($allRows, $offsetLocal, $perPage);

    return [
        'total' => $total,
        'rows'  => $rowsPage,
    ];
}

/**
 * Fetch all model categories from Snipe-IT.
 * Always returned A–Z by name (client-side sort).
 *
 * @return array
 * @throws Exception
 */
function get_model_categories(bool $includeNonRequestable = false): array
{
    $cached = snipeit_get_cached_model_categories($includeNonRequestable);
    if ($cached !== null) {
        return $cached;
    }

    return fetch_model_categories_from_snipeit(true, $includeNonRequestable);
}

/**
 * Fetch all accessory categories from Snipe-IT.
 * Always returned A–Z by name (client-side sort).
 *
 * @return array
 * @throws Exception
 */
function get_accessory_categories(): array
{
    $cached = snipeit_get_cached_accessory_categories();
    if ($cached !== null) {
        return $cached;
    }

    return fetch_accessory_categories_from_snipeit();
}

/**
 * Fetch a single model by ID.
 *
 * @param int $modelId
 * @return array
 * @throws Exception
 */
function get_model(int $modelId): array
{
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Invalid model ID');
    }

    $cached = snipeit_get_cached_model($modelId);
    if ($cached !== null) {
        return $cached;
    }

    return snipeit_request('GET', 'models/' . $modelId);
}

/**
 * Get the number of hardware assets for a given model.
 *
 * @param int $modelId
 * @return int
 * @throws Exception
 */

function get_model_hardware_count(int $modelId): int
{
    $model = get_model($modelId);

    if (isset($model['assets_count']) && is_numeric($model['assets_count'])) {
        return (int)$model['assets_count'];
    }

    if (isset($model['assets_count_total']) && is_numeric($model['assets_count_total'])) {
        return (int)$model['assets_count_total'];
    }

    return 0;
}

/**
 * Find a single asset by asset_tag.
 *
 * This uses the /hardware endpoint with a search, then looks for an
 * exact asset_tag match. It does NOT rely on /hardware/bytag so it
 * stays compatible across Snipe-IT versions.
 *
 * @param string $tag
 * @return array
 * @throws Exception if no or ambiguous match
 */
function find_asset_by_tag(string $tag): array
{
    $tagTrim = trim($tag);
    if ($tagTrim === '') {
        throw new InvalidArgumentException('Asset tag cannot be empty.');
    }

    // Search hardware with a small limit
    $params = [
        'search' => $tagTrim,
        'limit'  => 50,
    ];

    $data = snipeit_request('GET', 'hardware', $params);
    if (!isset($data['rows']) || !is_array($data['rows']) || count($data['rows']) === 0) {
        throw new Exception("No assets found in Snipe-IT matching tag '{$tagTrim}'.");
    }

    // Look for an exact asset_tag match (case-insensitive)
    $exactMatches = [];
    foreach ($data['rows'] as $row) {
        $rowTag = $row['asset_tag'] ?? '';
        if (strcasecmp(trim($rowTag), $tagTrim) === 0) {
            $exactMatches[] = $row;
        }
    }

    if (count($exactMatches) === 1) {
        return $exactMatches[0];
    }

    if (count($exactMatches) > 1) {
        throw new Exception("Multiple assets found with asset_tag '{$tagTrim}'. Please disambiguate in Snipe-IT.");
    }

    // No exact matches, but we got some approximate results
    // You can choose to accept the first or to treat as "not found".
    // Here we treat as not found to avoid wrong checkouts.
    throw new Exception("No exact asset_tag match for '{$tagTrim}' in Snipe-IT.");
}

/**
 * Search assets by tag or name (Snipe-IT hardware search).
 *
 * @param string $query
 * @param int $limit
 * @param bool $requestableOnly
 * @return array
 * @throws Exception
 */
function search_assets(string $query, int $limit = 20, bool $requestableOnly = false): array
{
    $q = trim($query);
    if ($q === '') {
        return [];
    }

    $params = [
        'search' => $q,
        'limit'  => max(1, min(50, $limit)),
    ];

    $data = snipeit_request('GET', 'hardware', $params);
    $rows = isset($data['rows']) && is_array($data['rows']) ? $data['rows'] : [];

    $rows = array_values(array_filter($rows, function ($row) use ($requestableOnly) {
        $tag = $row['asset_tag'] ?? '';
        if ($tag === '') {
            return false;
        }
        if ($requestableOnly && empty($row['requestable'])) {
            return false;
        }
        return true;
    }));

    return $rows;
}

/**
 * List hardware assets for a given model.
 *
 * @param int $modelId
 * @param int $maxResults
 * @return array
 * @throws Exception
 */
function list_assets_by_model(int $modelId, int $maxResults = 300): array
{
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Model ID must be positive.');
    }

    $cached = snipeit_get_cached_assets_by_model($modelId, $maxResults);
    if ($cached !== null) {
        return $cached;
    }

    $all    = [];
    $limit  = min(200, max(1, $maxResults));
    $offset = 0;

    do {
        $params = [
            'model_id' => $modelId,
            'limit'    => $limit,
            'offset'   => $offset,
        ];

        $chunk = snipeit_request('GET', 'hardware', $params);
        $rows  = isset($chunk['rows']) && is_array($chunk['rows']) ? $chunk['rows'] : [];

        $all    = array_merge($all, $rows);
        $count  = count($rows);
        $offset += $limit;

        if ($count < $limit || count($all) >= $maxResults) {
            break;
        }
    } while (true);

    return $all;
}

/**
 * Count requestable assets for a model (asset-level requestable flag).
 *
 * @param int $modelId
 * @return int
 * @throws Exception
 */
function count_requestable_assets_by_model(int $modelId): int
{
    static $cache = [];
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Model ID must be positive.');
    }
    $allowedStatusMap = snipeit_catalogue_allowed_status_labels();
    $statusCacheKey = empty($allowedStatusMap)
        ? 'all'
        : implode('|', array_keys($allowedStatusMap));
    $cacheKey = $modelId . '|' . $statusCacheKey;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if (empty($allowedStatusMap)) {
        $cachedCount = snipeit_get_cached_requestable_asset_count($modelId);
        if ($cachedCount !== null) {
            $cache[$cacheKey] = $cachedCount;
            return $cache[$cacheKey];
        }
    }

    $assets = list_assets_by_model($modelId, 500);
    $count  = 0;

    foreach ($assets as $a) {
        if (snipeit_asset_allowed_for_catalogue_availability($a, $allowedStatusMap)) {
            $count++;
        }
    }

    $cache[$cacheKey] = $count;
    return $count;
}

/**
 * Count how many assets for a model are currently checked out/assigned.
 *
 * @param int $modelId
 * @return int
 * @throws Exception
 */
function count_checked_out_assets_by_model(int $modelId): int
{
    if ($modelId <= 0) {
        throw new InvalidArgumentException('Model ID must be positive.');
    }

    global $pdo;
    require_once SRC_PATH . '/db.php';

    $allowedStatusMap = snipeit_catalogue_allowed_status_labels();
    $params = [':model_id' => $modelId];
    $sql = "
        SELECT COUNT(*)
          FROM checked_out_asset_cache
         WHERE model_id = :model_id
    ";
    if (!empty($allowedStatusMap)) {
        $labels = array_values($allowedStatusMap);
        $placeholders = [];
        foreach ($labels as $idx => $label) {
            $key = ':status_' . $idx;
            $placeholders[] = $key;
            $params[$key] = $label;
        }

        if (!empty($placeholders)) {
            $sql .= ' AND status_label IN (' . implode(', ', $placeholders) . ')';
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Find a single Snipe-IT user by email or name.
 *
 * Uses /users?search=... and tries to reduce to a single match:
 *  - If exactly one row, returns it.
 *  - If multiple rows and one has an exact email match (case-insensitive),
 *    returns that.
 *  - Otherwise throws an exception listing how many matches there were.
 *
 * @param string $query
 * @return array
 * @throws Exception
 */
function find_single_user_by_email_or_name(string $query): array
{
    $q = trim($query);
    if ($q === '') {
        throw new InvalidArgumentException('User search query cannot be empty.');
    }

    $params = [
        'search' => $q,
        'limit'  => 20,
    ];

    $data = snipeit_request('GET', 'users', $params);

    if (!isset($data['rows']) || !is_array($data['rows']) || count($data['rows']) === 0) {
        throw new Exception("No Snipe-IT users found matching '{$q}'.");
    }

    $rows = $data['rows'];

    // If exactly one result, use it
    if (count($rows) === 1) {
        return $rows[0];
    }

    // Try to find exact email match
    $exactEmailMatches = [];
    $exactNameMatches  = [];
    $qLower = strtolower($q);
    foreach ($rows as $row) {
        $email = $row['email'] ?? '';
        $name  = $row['name'] ?? ($row['username'] ?? '');
        if ($email !== '' && strtolower(trim($email)) === $qLower) {
            $exactEmailMatches[] = $row;
        }
        if ($name !== '' && strtolower(trim($name)) === $qLower) {
            $exactNameMatches[] = $row;
        }
    }

    if (count($exactEmailMatches) === 1) {
        return $exactEmailMatches[0];
    }
    if (count($exactNameMatches) === 1) {
        return $exactNameMatches[0];
    }

    // Multiple matches, ambiguous
    $count = count($rows);
    throw new Exception("{$count} users matched '{$q}' in Snipe-IT; please refine (e.g. use full email).");
}

/**
 * Find a Snipe-IT user by email or name, returning candidates on ambiguity.
 *
 * @param string $query
 * @return array{user: ?array, candidates: array}
 * @throws Exception
 */
function find_user_by_email_or_name_with_candidates(string $query): array
{
    $q = trim($query);
    if ($q === '') {
        throw new InvalidArgumentException('User search query cannot be empty.');
    }

    $params = [
        'search' => $q,
        'limit'  => 20,
    ];

    $data = snipeit_request('GET', 'users', $params);

    if (!isset($data['rows']) || !is_array($data['rows']) || count($data['rows']) === 0) {
        throw new Exception("No Snipe-IT users found matching '{$q}'.");
    }

    $rows = $data['rows'];

    if (count($rows) === 1) {
        return ['user' => $rows[0], 'candidates' => []];
    }

    $exactEmailMatches = [];
    $exactNameMatches  = [];
    $qLower = strtolower($q);
    foreach ($rows as $row) {
        $email = $row['email'] ?? '';
        $name  = $row['name'] ?? ($row['username'] ?? '');
        if ($email !== '' && strtolower(trim($email)) === $qLower) {
            $exactEmailMatches[] = $row;
        }
        if ($name !== '' && strtolower(trim($name)) === $qLower) {
            $exactNameMatches[] = $row;
        }
    }

    if (count($exactEmailMatches) === 1) {
        return ['user' => $exactEmailMatches[0], 'candidates' => []];
    }
    if (count($exactNameMatches) === 1) {
        return ['user' => $exactNameMatches[0], 'candidates' => []];
    }

    $candidates = $rows;
    if (!empty($exactEmailMatches)) {
        $candidates = $exactEmailMatches;
    } elseif (!empty($exactNameMatches)) {
        $candidates = $exactNameMatches;
    }

    return ['user' => null, 'candidates' => $candidates];
}

/**
 * Check out a single asset to a Snipe-IT user by ID.
 *
 * Uses POST /hardware/{id}/checkout
 *
 * @param int         $assetId
 * @param int         $userId
 * @param string      $note
 * @param string|null $expectedCheckin ISO datetime string for expected checkin
 * @return void
 * @throws Exception
 */
function checkout_asset_to_user(int $assetId, int $userId, string $note = '', ?string $expectedCheckin = null): void
{
    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID for checkout.');
    }
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user ID for checkout.');
    }

    $payload = [
        'checkout_to_type' => 'user',
        // Snipe-IT checkout expects these for user checkouts
        'checkout_to_id'   => $userId,
        'assigned_user'    => $userId,
    ];

    if ($note !== '') {
        $payload['note'] = $note;
    }
    if (!empty($expectedCheckin)) {
        $payload['expected_checkin'] = $expectedCheckin;
    }

    // Snipe-IT may also support expected_checkin, etc., but we
    // keep it simple here.
    $resp = snipeit_request('POST', 'hardware/' . $assetId . '/checkout', $payload);

    // Basic sanity check: API should report success
    $status = $resp['status'] ?? 'success';

    // Flatten any messages into a readable string
    $messagesField = $resp['messages'] ?? ($resp['message'] ?? '');
    $flatMessages  = [];
    if (is_array($messagesField)) {
        array_walk_recursive($messagesField, function ($val) use (&$flatMessages) {
            if (is_string($val) && trim($val) !== '') {
                $flatMessages[] = $val;
            }
        });
    } elseif (is_string($messagesField) && trim($messagesField) !== '') {
        $flatMessages[] = $messagesField;
    }
    $message = $flatMessages ? implode('; ', $flatMessages) : 'Unknown API response';

    // Treat missing status as success unless we spotted explicit error messages
    $hasExplicitError = is_array($messagesField) && isset($messagesField['error']);

    if ($status !== 'success' || $hasExplicitError) {
        throw new Exception('Snipe-IT checkout did not succeed: ' . $message);
    }
}

/**
 * Update the expected check-in date for an asset.
 *
 * @param int    $assetId
 * @param string $expectedDate ISO date (YYYY-MM-DD)
 * @return void
 * @throws Exception
 */
function update_asset_expected_checkin(int $assetId, string $expectedDate): void
{
    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID.');
    }
    $expectedDate = trim($expectedDate);
    if ($expectedDate === '') {
        throw new InvalidArgumentException('Expected check-in date cannot be empty.');
    }

    $payload = [
        'expected_checkin' => $expectedDate,
    ];

    $resp = snipeit_request('PATCH', 'hardware/' . $assetId, $payload);
    $status = $resp['status'] ?? 'success';
    $messagesField = $resp['messages'] ?? ($resp['message'] ?? '');
    $flatMessages  = [];
    if (is_array($messagesField)) {
        array_walk_recursive($messagesField, function ($val) use (&$flatMessages) {
            if (is_string($val) && trim($val) !== '') {
                $flatMessages[] = $val;
            }
        });
    } elseif (is_string($messagesField) && trim($messagesField) !== '') {
        $flatMessages[] = $messagesField;
    }
    $message = $flatMessages ? implode('; ', $flatMessages) : 'Unknown API response';
    $hasExplicitError = is_array($messagesField) && isset($messagesField['error']);

    if ($status !== 'success' || $hasExplicitError) {
        throw new Exception('Failed to update expected check-in: ' . $message);
    }
}

/**
 * Check in a single asset in Snipe-IT by ID.
 *
 * @param int    $assetId
 * @param string $note
 * @return void
 * @throws Exception
 */
function checkin_asset(int $assetId, string $note = ''): void
{
    if ($assetId <= 0) {
        throw new InvalidArgumentException('Invalid asset ID for checkin.');
    }

    $payload = [];
    if ($note !== '') {
        $payload['note'] = $note;
    }

    $resp = snipeit_request('POST', 'hardware/' . $assetId . '/checkin', $payload);

    $status = $resp['status'] ?? 'success';
    $messagesField = $resp['messages'] ?? ($resp['message'] ?? '');
    $flatMessages  = [];
    if (is_array($messagesField)) {
        array_walk_recursive($messagesField, function ($val) use (&$flatMessages) {
            if (is_string($val) && trim($val) !== '') {
                $flatMessages[] = $val;
            }
        });
    } elseif (is_string($messagesField) && trim($messagesField) !== '') {
        $flatMessages[] = $messagesField;
    }
    $message = $flatMessages ? implode('; ', $flatMessages) : 'Unknown API response';
    $hasExplicitError = is_array($messagesField) && isset($messagesField['error']);

    if ($status !== 'success' || $hasExplicitError) {
        throw new Exception('Snipe-IT checkin did not succeed: ' . $message);
    }
}

/**
 * Fetch checked-out assets (requestable only) directly from Snipe-IT.
 *
 * @param bool $overdueOnly
 * @param int $maxResults Safety cap for total hardware rows fetched (0 to use config)
 * @return array
 * @throws Exception
 */
function fetch_checked_out_assets_from_snipeit(bool $overdueOnly = false, int $maxResults = 0, bool $allowResponseCache = true): array
{
    $all = fetch_all_hardware_from_snipeit($maxResults, $allowResponseCache);

    $now = time();
    $filtered = [];
    foreach ($all as $row) {
        // Only requestable assets
        if (empty($row['requestable'])) {
            continue;
        }

        // Consider "checked out" if assigned_to/user is present
        $assigned = $row['assigned_to'] ?? ($row['assigned_to_fullname'] ?? '');
        $assignedFields = snipeit_extract_assigned_user_fields($assigned);
        $hasAssignment = $assignedFields['id'] > 0
            || $assignedFields['name'] !== ''
            || $assignedFields['email'] !== ''
            || $assignedFields['username'] !== '';
        if (!$hasAssignment) {
            continue;
        }

        // Normalize date fields
        $lastCheckout = $row['last_checkout'] ?? '';
        if (is_array($lastCheckout)) {
            $lastCheckout = $lastCheckout['datetime'] ?? ($lastCheckout['date'] ?? '');
        }
        $expectedCheckin = $row['expected_checkin'] ?? '';
        if (is_array($expectedCheckin)) {
            $expectedCheckin = $expectedCheckin['datetime'] ?? ($expectedCheckin['date'] ?? '');
        }

        // Overdue check
        if ($overdueOnly) {
            // If Snipe-IT returns only a date (no time), treat it as due by end-of-day rather than midnight.
            $normalizedExpected = $expectedCheckin;
            if (is_string($expectedCheckin) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $expectedCheckin)) {
                $normalizedExpected = $expectedCheckin . ' 23:59:59';
            }
            $expTs = $normalizedExpected ? strtotime($normalizedExpected) : null;
            if (!$expTs || $expTs > $now) {
                continue;
            }
        }

        $row['_last_checkout_norm']   = $lastCheckout;
        $row['_expected_checkin_norm'] = $expectedCheckin;

        $filtered[] = $row;
    }

    return $filtered;
}

/**
 * Fetch checked-out assets from the local cache table.
 *
 * @param bool $overdueOnly
 * @return array
 * @throws Exception
 */
function list_checked_out_assets(bool $overdueOnly = false): array
{
    global $pdo;
    require_once SRC_PATH . '/db.php';

    $sql = "
        SELECT
            asset_id,
            asset_tag,
            asset_name,
            model_id,
            model_name,
            assigned_to_id,
            assigned_to_name,
            assigned_to_email,
            assigned_to_username,
            status_label,
            last_checkout,
            expected_checkin
        FROM checked_out_asset_cache
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $now = time();
    $results = [];
    foreach ($rows as $row) {
        $expectedCheckin = $row['expected_checkin'] ?? '';
        if ($overdueOnly) {
            $normalizedExpected = $expectedCheckin;
            if (is_string($expectedCheckin) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $expectedCheckin)) {
                $normalizedExpected = $expectedCheckin . ' 23:59:59';
            }
            $expTs = $normalizedExpected ? strtotime($normalizedExpected) : null;
            if (!$expTs || $expTs > $now) {
                continue;
            }
        }

        $assigned = [];
        $assignedId = (int)($row['assigned_to_id'] ?? 0);
        if ($assignedId > 0) {
            $assigned['id'] = $assignedId;
        }
        $assignedEmail = $row['assigned_to_email'] ?? '';
        $assignedName = $row['assigned_to_name'] ?? '';
        $assignedUsername = $row['assigned_to_username'] ?? '';
        if ($assignedEmail !== '') {
            $assigned['email'] = $assignedEmail;
        }
        if ($assignedUsername !== '') {
            $assigned['username'] = $assignedUsername;
        }
        if ($assignedName !== '') {
            $assigned['name'] = $assignedName;
        }

        $item = [
            'id' => (int)($row['asset_id'] ?? 0),
            'asset_tag' => $row['asset_tag'] ?? '',
            'name' => $row['asset_name'] ?? '',
            'model' => [
                'id' => (int)($row['model_id'] ?? 0),
                'name' => $row['model_name'] ?? '',
            ],
            'status_label' => $row['status_label'] ?? '',
            'last_checkout' => $row['last_checkout'] ?? '',
            'expected_checkin' => $expectedCheckin,
            '_last_checkout_norm' => $row['last_checkout'] ?? '',
            '_expected_checkin_norm' => $expectedCheckin,
        ];

        if (!empty($assigned)) {
            $item['assigned_to'] = $assigned;
        } elseif ($assignedName !== '') {
            $item['assigned_to_fullname'] = $assignedName;
        }

        $results[] = $item;
    }

    return $results;
}

function snipeit_extract_datetime_string($value): string
{
    if (is_array($value)) {
        $value = $value['datetime'] ?? ($value['date'] ?? ($value['formatted'] ?? ''));
    }

    return trim((string)$value);
}

function snipeit_extract_first_datetime_field(array $row, array $fieldCandidates): string
{
    foreach ($fieldCandidates as $field) {
        if (!array_key_exists($field, $row)) {
            continue;
        }

        $value = snipeit_extract_datetime_string($row[$field]);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function snipeit_accessory_checked_out_count_from_payload(array $accessory): int
{
    $checkedOutCandidates = [
        'checkout_count',
        'checked_out',
        'checked_out_count',
        'assigned_count',
        'assigned_qty',
        'users_count',
    ];
    foreach ($checkedOutCandidates as $field) {
        if (isset($accessory[$field]) && is_numeric($accessory[$field])) {
            return max(0, (int)$accessory[$field]);
        }
    }

    $qty = isset($accessory['qty']) && is_numeric($accessory['qty'])
        ? (int)$accessory['qty']
        : (isset($accessory['quantity']) && is_numeric($accessory['quantity']) ? (int)$accessory['quantity'] : 0);

    return max(0, $qty - snipeit_accessory_available_quantity_from_payload($accessory));
}

function snipeit_accessory_checked_out_quantity_from_payload(array $row): int
{
    $quantityCandidates = [
        'assigned_qty',
        'checkout_qty',
        'quantity',
        'qty',
        'checked_out',
        'count',
    ];
    foreach ($quantityCandidates as $field) {
        if (isset($row[$field]) && is_numeric($row[$field])) {
            return max(1, (int)$row[$field]);
        }
    }

    return 1;
}

function snipeit_normalize_checked_out_accessory_row(array $accessory, array $row): ?array
{
    $accessoryId = (int)($accessory['id'] ?? ($row['accessory_id'] ?? 0));
    if ($accessoryId <= 0) {
        return null;
    }

    $manufacturer = is_array($accessory['manufacturer'] ?? null)
        ? trim((string)($accessory['manufacturer']['name'] ?? ''))
        : trim((string)($accessory['manufacturer_name'] ?? ''));
    $category = is_array($accessory['category'] ?? null)
        ? trim((string)($accessory['category']['name'] ?? ''))
        : trim((string)($accessory['category_name'] ?? ''));

    $assignedSource = $row['assigned_to'] ?? ($row['assigned_user'] ?? ($row['user'] ?? ($row['target'] ?? ($accessory['assigned_to'] ?? ($accessory['assigned_to_fullname'] ?? '')))));
    $assigned = snipeit_extract_assigned_user_fields($assignedSource);
    if ($assigned['id'] <= 0 && $assigned['name'] === '' && $assigned['email'] === '' && $assigned['username'] === '') {
        $assigned = [
            'id' => (int)($row['assigned_to_id'] ?? ($row['assigned_user_id'] ?? ($row['user_id'] ?? ($row['target_id'] ?? 0)))),
            'name' => trim((string)($row['assigned_to_name'] ?? ($row['assigned_user_name'] ?? ($row['name'] ?? ($row['target_name'] ?? ''))))),
            'email' => trim((string)($row['assigned_to_email'] ?? ($row['assigned_user_email'] ?? ($row['email'] ?? ($row['target_email'] ?? ''))))),
            'username' => trim((string)($row['assigned_to_username'] ?? ($row['assigned_user_username'] ?? ($row['username'] ?? '')))),
        ];
    }

    $assigned = array_filter($assigned, static function ($value): bool {
        if (is_int($value)) {
            return $value > 0;
        }

        return trim((string)$value) !== '';
    });

    $lastCheckout = snipeit_extract_first_datetime_field($row, [
        'checkout_date',
        'last_checkout',
        'created_at',
        'assigned_at',
        'updated_at',
    ]);
    if ($lastCheckout === '') {
        $lastCheckout = snipeit_extract_datetime_string($accessory['last_checkout'] ?? '');
    }

    $expectedCheckin = snipeit_extract_first_datetime_field($row, [
        'expected_checkin',
        'expected_checkin_date',
        'expected_checkout',
        'due_date',
    ]);
    if ($expectedCheckin === '') {
        $expectedCheckin = snipeit_extract_datetime_string($accessory['expected_checkin'] ?? '');
    }

    $item = [
        'id' => $accessoryId,
        'item_type' => 'accessory',
        'accessory_id' => $accessoryId,
        'accessory_checkout_id' => (int)($row['id'] ?? 0),
        'asset_tag' => '',
        'name' => trim((string)($accessory['name'] ?? ('Accessory #' . $accessoryId))),
        'image' => $accessory['image'] ?? ($accessory['image_path'] ?? ($accessory['image_url'] ?? ($accessory['thumbnail'] ?? ''))),
        'image_path' => $accessory['image_path'] ?? ($accessory['image'] ?? ($accessory['image_url'] ?? ($accessory['thumbnail'] ?? ''))),
        'manufacturer_name' => $manufacturer,
        'category_name' => $category,
        'assigned_qty' => snipeit_accessory_checked_out_quantity_from_payload($row),
        'last_checkout' => $lastCheckout,
        'expected_checkin' => $expectedCheckin,
        '_last_checkout_norm' => $lastCheckout,
        '_expected_checkin_norm' => $expectedCheckin,
    ];

    if (!empty($assigned)) {
        $item['assigned_to'] = $assigned;
    }

    return $item;
}

function fetch_checked_out_accessories_from_snipeit(bool $allowResponseCache = true): array
{
    $accessories = fetch_all_accessories_from_snipeit('', $allowResponseCache);
    $results = [];

    foreach ($accessories as $accessory) {
        $accessoryId = (int)($accessory['id'] ?? 0);
        if ($accessoryId <= 0) {
            continue;
        }

        if (snipeit_accessory_checked_out_count_from_payload($accessory) <= 0) {
            continue;
        }

        try {
            $rows = snipeit_fetch_all_rows_from_endpoint('accessories/' . $accessoryId . '/checkedout', [], $allowResponseCache);
        } catch (Throwable $e) {
            $rows = [];
        }

        if (empty($rows)) {
            $fallbackRow = $accessory;
            unset($fallbackRow['id']);
            $fallbackItem = snipeit_normalize_checked_out_accessory_row($accessory, $fallbackRow);
            if ($fallbackItem !== null) {
                $results[] = $fallbackItem;
            }
            continue;
        }

        foreach ($rows as $row) {
            $item = snipeit_normalize_checked_out_accessory_row($accessory, is_array($row) ? $row : []);
            if ($item !== null) {
                $results[] = $item;
            }
        }
    }

    return $results;
}

function snipeit_array_is_list_compat(array $value): bool
{
    if (function_exists('array_is_list')) {
        return array_is_list($value);
    }

    $expectedKey = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $expectedKey) {
            return false;
        }
        $expectedKey++;
    }

    return true;
}

function snipeit_extract_rows_from_collection_payload(array $data): array
{
    if (isset($data['rows']) && is_array($data['rows'])) {
        return $data['rows'];
    }

    if (isset($data['data']) && is_array($data['data'])) {
        return $data['data'];
    }

    return snipeit_array_is_list_compat($data) ? $data : [];
}

function snipeit_fetch_all_rows_from_endpoint(string $endpoint, array $baseParams = [], bool $allowResponseCache = true): array
{
    $limit = 200;
    $offset = 0;
    $rows = [];

    do {
        $params = $baseParams;
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $data = snipeit_request('GET', $endpoint, $params, $allowResponseCache);
        $chunk = snipeit_extract_rows_from_collection_payload($data);
        if (empty($chunk)) {
            break;
        }

        $rows = array_merge($rows, $chunk);
        if (count($chunk) < $limit) {
            break;
        }

        $offset += $limit;
    } while (true);

    return $rows;
}

function snipeit_request_first_successful_get(array $endpointCandidates, array $params = [], bool $allowResponseCache = true): array
{
    $lastError = null;

    foreach ($endpointCandidates as $endpoint) {
        try {
            return snipeit_request('GET', $endpoint, $params, $allowResponseCache);
        } catch (Throwable $e) {
            $lastError = $e;
        }
    }

    if ($lastError instanceof Throwable) {
        throw $lastError;
    }

    throw new RuntimeException('No Snipe-IT endpoint candidates were provided.');
}

function snipeit_fetch_all_rows_from_candidates(array $endpointCandidates, array $baseParams = [], bool $allowResponseCache = true): array
{
    $lastError = null;

    foreach ($endpointCandidates as $endpoint) {
        try {
            return snipeit_fetch_all_rows_from_endpoint($endpoint, $baseParams, $allowResponseCache);
        } catch (Throwable $e) {
            $lastError = $e;
        }
    }

    if ($lastError instanceof Throwable) {
        throw $lastError;
    }

    throw new RuntimeException('No Snipe-IT endpoint candidates were provided.');
}

function snipeit_accessory_available_quantity_from_payload(array $accessory): int
{
    $directCandidates = [
        'remaining_qty',
        'remaining_count',
        'available_qty',
        'available_count',
        'num_remaining',
        'available',
    ];
    foreach ($directCandidates as $field) {
        if (isset($accessory[$field]) && is_numeric($accessory[$field])) {
            return max(0, (int)$accessory[$field]);
        }
    }

    $qty = isset($accessory['qty']) && is_numeric($accessory['qty'])
        ? (int)$accessory['qty']
        : (isset($accessory['quantity']) && is_numeric($accessory['quantity']) ? (int)$accessory['quantity'] : 0);

    $checkedOut = 0;
    $checkedOutCandidates = [
        'checkout_count',
        'checked_out',
        'checked_out_count',
        'assigned_count',
        'assigned_qty',
        'users_count',
    ];
    foreach ($checkedOutCandidates as $field) {
        if (isset($accessory[$field]) && is_numeric($accessory[$field])) {
            $checkedOut = (int)$accessory[$field];
            break;
        }
    }

    return max(0, $qty - $checkedOut);
}

function fetch_all_accessories_from_snipeit(string $search = '', bool $allowResponseCache = true): array
{
    if ($allowResponseCache) {
        $cached = snipeit_get_cached_accessories($search);
        if ($cached !== null) {
            return $cached;
        }
    }

    $params = [];
    if ($search !== '') {
        $params['search'] = $search;
    }

    return snipeit_fetch_all_rows_from_endpoint('accessories', $params, $allowResponseCache);
}

function fetch_accessory_categories_from_snipeit(bool $allowResponseCache = true): array
{
    return snipeit_collect_category_options(fetch_all_accessories_from_snipeit('', $allowResponseCache));
}

function get_bookable_accessories(
    int $page = 1,
    string $search = '',
    ?string $sort = null,
    int $perPage = 50,
    ?string $categoryFilter = null
): array {
    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $sort = trim((string)$sort);

    $rows = fetch_all_accessories_from_snipeit($search);
    $rows = array_values(array_filter($rows, static function (array $row): bool {
        return snipeit_accessory_available_quantity_from_payload($row) > 0;
    }));

    // Apply category filter if specified
    if ($categoryFilter !== null && $categoryFilter !== '') {
        $rows = array_values(array_filter($rows, static function (array $row) use ($categoryFilter): bool {
            return snipeit_category_filter_matches($row, $categoryFilter);
        }));
    }

    usort($rows, static function (array $a, array $b) use ($sort): int {
        $nameA = (string)($a['name'] ?? '');
        $nameB = (string)($b['name'] ?? '');
        $manA = (string)(is_array($a['manufacturer'] ?? null) ? ($a['manufacturer']['name'] ?? '') : ($a['manufacturer_name'] ?? ''));
        $manB = (string)(is_array($b['manufacturer'] ?? null) ? ($b['manufacturer']['name'] ?? '') : ($b['manufacturer_name'] ?? ''));
        $qtyA = snipeit_accessory_available_quantity_from_payload($a);
        $qtyB = snipeit_accessory_available_quantity_from_payload($b);

        switch ($sort) {
            case 'manu_asc':
                return strcasecmp($manA, $manB);
            case 'manu_desc':
                return strcasecmp($manB, $manA);
            case 'name_desc':
                return strcasecmp($nameB, $nameA);
            case 'units_asc':
                return $qtyA === $qtyB ? strcasecmp($nameA, $nameB) : ($qtyA <=> $qtyB);
            case 'units_desc':
                return $qtyA === $qtyB ? strcasecmp($nameA, $nameB) : ($qtyB <=> $qtyA);
            case 'name_asc':
            case '':
            default:
                return strcasecmp($nameA, $nameB);
        }
    });

    $total = count($rows);
    $offset = ($page - 1) * $perPage;

    return [
        'total' => $total,
        'rows' => array_slice($rows, $offset, $perPage),
    ];
}

function snipeit_find_accessory_in_collection(int $accessoryId, bool $allowResponseCache = true): ?array
{
    static $lookups = [];

    if ($accessoryId <= 0) {
        return null;
    }

    $cacheKey = $allowResponseCache ? 'cached' : 'fresh';
    if (!array_key_exists($cacheKey, $lookups)) {
        $lookups[$cacheKey] = [];
        try {
            foreach (fetch_all_accessories_from_snipeit('', $allowResponseCache) as $accessory) {
                if (!is_array($accessory)) {
                    continue;
                }

                $id = (int)($accessory['id'] ?? 0);
                if ($id > 0 && !isset($lookups[$cacheKey][$id])) {
                    $lookups[$cacheKey][$id] = $accessory;
                }
            }
        } catch (Throwable $e) {
            $lookups[$cacheKey] = [];
        }
    }

    return $lookups[$cacheKey][$accessoryId] ?? null;
}

function get_accessory(int $accessoryId): array
{
    if ($accessoryId <= 0) {
        throw new InvalidArgumentException('Invalid accessory ID');
    }

    $collectionAccessory = snipeit_find_accessory_in_collection($accessoryId);
    if ($collectionAccessory !== null) {
        return $collectionAccessory;
    }

    return snipeit_request('GET', 'accessories/' . $accessoryId);
}

function count_available_accessory_units(int $accessoryId): int
{
    static $cache = [];
    if ($accessoryId <= 0) {
        throw new InvalidArgumentException('Accessory ID must be positive.');
    }

    if (isset($cache[$accessoryId])) {
        return $cache[$accessoryId];
    }

    $accessory = snipeit_find_accessory_in_collection($accessoryId);
    if ($accessory === null) {
        $accessory = get_accessory($accessoryId);
    }
    $cache[$accessoryId] = snipeit_accessory_available_quantity_from_payload($accessory);

    return $cache[$accessoryId];
}

function checkout_accessory_to_user(int $accessoryId, int $userId, int $quantity = 1, string $note = ''): void
{
    if ($accessoryId <= 0) {
        throw new InvalidArgumentException('Invalid accessory ID for checkout.');
    }
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid user ID for checkout.');
    }
    if ($quantity <= 0) {
        throw new InvalidArgumentException('Accessory checkout quantity must be positive.');
    }

    $payload = [
        // Different Snipe-IT versions expect different accessory checkout keys,
        // so send both the newer validated names and the older assigned_to form.
        'checkout_to_type' => 'user',
        'checkout_to_id' => $userId,
        'assigned_user' => $userId,
        'assigned_to' => $userId,
        'assigned_qty' => $quantity,
        'checkout_qty' => $quantity,
        'quantity' => $quantity,
    ];
    if ($note !== '') {
        $payload['note'] = $note;
    }

    $resp = snipeit_request('POST', 'accessories/' . $accessoryId . '/checkout', $payload, false);
    $status = $resp['status'] ?? 'success';
    $messagesField = $resp['messages'] ?? ($resp['message'] ?? '');
    $flatMessages = [];
    if (is_array($messagesField)) {
        array_walk_recursive($messagesField, static function ($val) use (&$flatMessages): void {
            if (is_string($val) && trim($val) !== '') {
                $flatMessages[] = $val;
            }
        });
    } elseif (is_string($messagesField) && trim($messagesField) !== '') {
        $flatMessages[] = $messagesField;
    }

    $message = $flatMessages ? implode('; ', $flatMessages) : 'Unknown API response';
    $hasExplicitError = is_array($messagesField) && isset($messagesField['error']);
    if ($status !== 'success' || $hasExplicitError) {
        throw new Exception('Snipe-IT accessory checkout did not succeed: ' . $message);
    }
}

function checkin_accessory(int $accessoryCheckoutId, string $note = ''): void
{
    if ($accessoryCheckoutId <= 0) {
        throw new InvalidArgumentException('Invalid accessory checkout ID.');
    }

    $payload = [];
    if ($note !== '') {
        $payload['note'] = $note;
    }

    // Snipe-IT's accessory check-in route is named with {accessory}, but expects
    // the checked-out accessory row ID returned by accessories/{id}/checkedout.
    $resp = snipeit_request('POST', 'accessories/' . $accessoryCheckoutId . '/checkin', $payload, false);
    $status = $resp['status'] ?? 'success';
    $messagesField = $resp['messages'] ?? ($resp['message'] ?? '');
    $flatMessages = [];
    if (is_array($messagesField)) {
        array_walk_recursive($messagesField, static function ($val) use (&$flatMessages): void {
            if (is_string($val) && trim($val) !== '') {
                $flatMessages[] = $val;
            }
        });
    } elseif (is_string($messagesField) && trim($messagesField) !== '') {
        $flatMessages[] = $messagesField;
    }

    $message = $flatMessages ? implode('; ', $flatMessages) : 'Unknown API response';
    $hasExplicitError = is_array($messagesField) && isset($messagesField['error']);
    if ($status !== 'success' || $hasExplicitError) {
        throw new Exception('Snipe-IT accessory check-in did not succeed: ' . $message);
    }
}

function snipeit_kit_endpoint_candidates(): array
{
    return ['kits', 'predefined_kits', 'predefinedkits'];
}

function fetch_all_kits_from_snipeit(string $search = '', bool $allowResponseCache = true): array
{
    if ($allowResponseCache) {
        $cached = snipeit_get_cached_kits($search);
        if ($cached !== null) {
            return $cached;
        }
    }

    $params = [];
    if ($search !== '') {
        $params['search'] = $search;
    }

    return snipeit_fetch_all_rows_from_candidates(snipeit_kit_endpoint_candidates(), $params, $allowResponseCache);
}

function get_bookable_kits(
    int $page = 1,
    string $search = '',
    ?string $sort = null,
    int $perPage = 50
): array {
    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $sort = trim((string)$sort);

    $rows = fetch_all_kits_from_snipeit($search);
    usort($rows, static function (array $a, array $b) use ($sort): int {
        $nameA = (string)($a['name'] ?? '');
        $nameB = (string)($b['name'] ?? '');

        switch ($sort) {
            case 'name_desc':
                return strcasecmp($nameB, $nameA);
            case 'name_asc':
            case '':
            default:
                return strcasecmp($nameA, $nameB);
        }
    });

    $total = count($rows);
    $offset = ($page - 1) * $perPage;

    return [
        'total' => $total,
        'rows' => array_slice($rows, $offset, $perPage),
    ];
}

function get_kit(int $kitId): array
{
    if ($kitId <= 0) {
        throw new InvalidArgumentException('Invalid kit ID');
    }

    $cachedKits = snipeit_get_cached_kits();
    if ($cachedKits !== null) {
        foreach ($cachedKits as $kit) {
            if ((int)($kit['id'] ?? 0) === $kitId) {
                return $kit;
            }
        }
    }

    $endpoints = [];
    foreach (snipeit_kit_endpoint_candidates() as $baseEndpoint) {
        $endpoints[] = $baseEndpoint . '/' . $kitId;
    }

    return snipeit_request_first_successful_get($endpoints);
}

function snipeit_extract_kit_embedded_rows(array $kit, string $field): array
{
    $candidates = [
        $kit[$field] ?? null,
        $kit[$field . '_rows'] ?? null,
        $kit['included_' . $field] ?? null,
        $kit['kit_' . $field] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }

        $rows = snipeit_extract_rows_from_collection_payload($candidate);
        if (!empty($rows)) {
            return $rows;
        }

        if (snipeit_array_is_list_compat($candidate)) {
            return $candidate;
        }
    }

    return [];
}

function snipeit_get_kit_element_rows_from_payload(array $kit, int $kitId, string $field, bool $allowResponseCache = true): array
{
    $field = strtolower(trim($field));
    if ($field === '') {
        return [];
    }

    $embedded = snipeit_extract_kit_embedded_rows($kit, $field);
    if (!empty($embedded)) {
        return $embedded;
    }

    $endpoints = [];
    foreach (snipeit_kit_endpoint_candidates() as $baseEndpoint) {
        $endpoints[] = $baseEndpoint . '/' . $kitId . '/' . $field;
    }

    return snipeit_fetch_all_rows_from_candidates($endpoints, [], $allowResponseCache);
}

function snipeit_get_kit_element_rows(int $kitId, string $field): array
{
    $field = strtolower(trim($field));
    if ($field === '') {
        return [];
    }

    $cached = snipeit_get_cached_kit_element_rows($kitId, $field);
    if ($cached !== null) {
        return $cached;
    }

    $kit = get_kit($kitId);
    return snipeit_get_kit_element_rows_from_payload($kit, $kitId, $field);
}

function snipeit_kit_element_quantity(array $row): int
{
    $candidates = [
        $row['quantity'] ?? null,
        $row['qty'] ?? null,
        $row['count'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (is_numeric($candidate)) {
            return max(1, (int)$candidate);
        }
    }

    if (isset($row['pivot']) && is_array($row['pivot']) && is_numeric($row['pivot']['quantity'] ?? null)) {
        return max(1, (int)$row['pivot']['quantity']);
    }

    return 1;
}

function get_kit_booking_breakdown(int $kitId): array
{
    $kit = get_kit($kitId);
    $models = snipeit_get_kit_element_rows($kitId, 'models');
    $accessories = snipeit_get_kit_element_rows($kitId, 'accessories');

    $licenses = [];
    $consumables = [];
    try {
        $licenses = snipeit_get_kit_element_rows($kitId, 'licenses');
    } catch (Throwable $e) {
        $licenses = [];
    }
    try {
        $consumables = snipeit_get_kit_element_rows($kitId, 'consumables');
    } catch (Throwable $e) {
        $consumables = [];
    }

    $supportedItems = [];
    foreach ($models as $row) {
        $itemId = (int)($row['id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        $supportedItems[] = [
            'type' => 'model',
            'id' => $itemId,
            'name' => trim((string)($row['name'] ?? ('Model #' . $itemId))),
            'qty' => snipeit_kit_element_quantity($row),
        ];
    }
    foreach ($accessories as $row) {
        $itemId = (int)($row['id'] ?? 0);
        if ($itemId <= 0) {
            continue;
        }
        $supportedItems[] = [
            'type' => 'accessory',
            'id' => $itemId,
            'name' => trim((string)($row['name'] ?? ('Accessory #' . $itemId))),
            'qty' => snipeit_kit_element_quantity($row),
        ];
    }

    $unsupported = [];
    if (!empty($licenses)) {
        $unsupported[] = [
            'type' => 'license',
            'count' => count($licenses),
        ];
    }
    if (!empty($consumables)) {
        $unsupported[] = [
            'type' => 'consumable',
            'count' => count($consumables),
        ];
    }

    return [
        'kit' => $kit,
        'models' => $models,
        'accessories' => $accessories,
        'licenses' => $licenses,
        'consumables' => $consumables,
        'supported_items' => $supportedItems,
        'unsupported_items' => $unsupported,
    ];
}
