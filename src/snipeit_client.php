<?php
// snipeit_client.php
//
// Thin client for talking to the Snipe-IT API.
// Uses config.php for base URL, API token and SSL verification settings.
//
// Exposes:
//   - get_bookable_models($page, $search, $categoryId, $sort, $perPage, $allowedCategoryIds)
//   - get_model_categories()
//   - get_model($id)
//   - get_model_hardware_count($modelId)

require_once __DIR__ . '/bootstrap.php';

$config       = load_config();
$snipeConfig  = $config['snipeit'] ?? [];

$snipeBaseUrl   = rtrim($snipeConfig['base_url'] ?? '', '/');
$snipeApiToken  = $snipeConfig['api_token'] ?? '';
$snipeVerifySsl = !empty($snipeConfig['verify_ssl']);
$cacheTtl       = isset($config['app']['api_cache_ttl_seconds'])
    ? max(0, (int)$config['app']['api_cache_ttl_seconds'])
    : 60;
$cacheDir       = CONFIG_PATH . '/cache';

$limit = min(200, SNIPEIT_MAX_MODELS_FETCH);

function snipeit_cache_path(string $key): string
{
    global $cacheDir;
    return rtrim($cacheDir, '/\\') . '/' . $key . '.json';
}

function snipeit_cache_get(string $key, int $ttl)
{
    $path = snipeit_cache_path($key);
    if ($ttl <= 0 || !is_file($path)) {
        return null;
    }
    $age = time() - (int)@filemtime($path);
    if ($age > $ttl) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function snipeit_cache_set(string $key, array $data): void
{
    global $cacheDir;
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $path = snipeit_cache_path($key);
    @file_put_contents($path, json_encode($data), LOCK_EX);
}

/**
 * Core HTTP wrapper for Snipe-IT API.
 *
 * @param string $method   HTTP method (GET, POST, etc.)
 * @param string $endpoint Relative endpoint, e.g. "models" or "models/5"
 * @param array  $params   Query/body params
 * @return array           Decoded JSON response
 * @throws Exception       On HTTP or decode errors
 */
function snipeit_request(string $method, string $endpoint, array $params = []): array
{
    global $snipeBaseUrl, $snipeApiToken, $snipeVerifySsl, $cacheTtl;

    if ($snipeBaseUrl === '' || $snipeApiToken === '') {
        throw new Exception('Snipe-IT API is not configured (missing base_url or api_token).');
    }

    $url = $snipeBaseUrl . '/api/v1/' . ltrim($endpoint, '/');

    $method = strtoupper($method);
    $cacheKey = null;

    // Simple GET cache to reduce repeated hits
    if ($method === 'GET' && $cacheTtl > 0) {
        $cacheKey = sha1($url . '|' . json_encode($params));
        $cached = snipeit_cache_get($cacheKey, $cacheTtl);
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

    if ($cacheKey !== null && $cacheTtl > 0) {
        snipeit_cache_set($cacheKey, $decoded);
    }

    return $decoded;
}

/**
 * Fetch **all** matching models from Snipe-IT (up to SNIPEIT_MAX_MODELS_FETCH),
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
 * @return array                  ['total' => X, 'rows' => [...]]
 * @throws Exception
 */
function get_bookable_models(
    int $page = 1,
    string $search = '',
    ?int $categoryId = null,
    ?string $sort = null,
    int $perPage = 50,
    array $allowedCategoryIds = []
): array {
    $page    = max(1, $page);
    $perPage = max(1, $perPage);
    $allowedMap = [];
    foreach ($allowedCategoryIds as $cid) {
        if (ctype_digit((string)$cid) || is_int($cid)) {
            $allowedMap[(int)$cid] = true;
        }
    }

    // If an allowlist exists and the requested category is not allowed, clear it to avoid wasted calls.
    $effectiveCategory = $categoryId;
    if (!empty($allowedMap) && $categoryId !== null && !isset($allowedMap[$categoryId])) {
        $effectiveCategory = null;
    }

    $limit  = min(200, SNIPEIT_MAX_MODELS_FETCH); // per-API-call limit
    $allRows = [];

    $offset = 0;
    // Pull pages from Snipe-IT until we have everything (or hit our max fetch cap)
    do {
        $params = [
            'limit'  => $limit,
            'offset' => $offset,
        ];

        if ($search !== '') {
            $params['search'] = $search;
        }

        if (!empty($effectiveCategory)) {
            $params['category_id'] = $effectiveCategory;
        }

        $chunk = snipeit_request('GET', 'models', $params);

        if (!isset($chunk['rows']) || !is_array($chunk['rows'])) {
            break;
        }

        $rows    = $chunk['rows'];
        $allRows = array_merge($allRows, $rows);

        $fetchedThisCall = count($rows);
        $offset += $limit;

        // Stop if we didn't get a full page (end of data),
        // or we have reached our max safety cap.
        if ($fetchedThisCall < $limit || count($allRows) >= SNIPEIT_MAX_MODELS_FETCH) {
            break;
        }
    } while (true);

    // Filter by requestable flag (Snipe-IT uses 'requestable' on models)
    $allRows = array_values(array_filter($allRows, function ($row) {
        return !empty($row['requestable']);
    }));

    // Apply optional category allowlist (overrides requestable-only default scope)
    if (!empty($allowedMap)) {
        $allRows = array_values(array_filter($allRows, function ($row) use ($allowedMap) {
            $cid = isset($row['category']['id']) ? (int)$row['category']['id'] : 0;
            return $cid > 0 && isset($allowedMap[$cid]);
        }));
    }

    // Determine total after filtering
    $total = count($allRows);
    if ($total > SNIPEIT_MAX_MODELS_FETCH) {
        $total = SNIPEIT_MAX_MODELS_FETCH; // we’ve capped at this many
    }

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
function get_model_categories(): array
{
    $params = [
        'limit' => 500,
    ];

    $data = snipeit_request('GET', 'categories', $params);

    if (!isset($data['rows']) || !is_array($data['rows'])) {
        return [];
    }

    $rows = $data['rows'];
    // Keep only categories that have at least one requestable model if API returns requestable_count
    $rows = array_values(array_filter($rows, function ($row) {
        if (isset($row['requestable_count']) && is_numeric($row['requestable_count'])) {
            return (int)$row['requestable_count'] > 0;
        }
        return true;
    }));

    usort($rows, function ($a, $b) {
        $na = $a['name'] ?? '';
        $nb = $b['name'] ?? '';
        return strcasecmp($na, $nb);
    });

    return $rows;
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
    if (isset($cache[$modelId])) {
        return $cache[$modelId];
    }

    $assets = list_assets_by_model($modelId, 500);
    $count  = 0;

    foreach ($assets as $a) {
        if (!empty($a['requestable'])) {
            $count++;
        }
    }

    $cache[$modelId] = $count;
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
    $assets = list_assets_by_model($modelId, 500);
    $count = 0;
    foreach ($assets as $a) {
        $assigned = $a['assigned_to'] ?? ($a['assigned_to_fullname'] ?? '');
        $statusRaw = $a['status_label'] ?? '';
        if (is_array($statusRaw)) {
            $statusRaw = $statusRaw['name'] ?? ($statusRaw['status_meta'] ?? '');
        }
        $status = strtolower((string)$statusRaw);

        if (!empty($assigned) || strpos($status, 'checked out') !== false) {
            $count++;
        }
    }
    return $count;
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
 * Fetch checked-out assets (requestable only).
 *
 * @param bool $overdueOnly
 * @return array
 * @throws Exception
 */
function list_checked_out_assets(bool $overdueOnly = false): array
{
    $params = [
        'limit'  => 500,
    ];

    $data = snipeit_request('GET', 'hardware', $params);
    if (!isset($data['rows']) || !is_array($data['rows'])) {
        return [];
    }

    $now = time();
    $filtered = [];
    foreach ($data['rows'] as $row) {
        // Only requestable assets
        if (empty($row['requestable'])) {
            continue;
        }

        // Consider "checked out" if assigned_to/user is present
        $assigned = $row['assigned_to'] ?? ($row['assigned_to_fullname'] ?? '');
        if ($assigned === '') {
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
