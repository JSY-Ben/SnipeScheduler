<?php
// snipeit_client.php
//
// Thin client for talking to the Snipe-IT API.
// Uses config.php for base URL, API token and SSL verification settings.
//
// Exposes:
//   - get_bookable_models($page, $search, $categoryId, $sort, $perPage)
//   - get_model_categories()
//   - get_model($id)
//   - get_model_hardware_count($modelId)

$config       = require __DIR__ . '/config.php';
$snipeConfig  = $config['snipeit'] ?? [];

$snipeBaseUrl   = rtrim($snipeConfig['base_url'] ?? '', '/');
$snipeApiToken  = $snipeConfig['api_token'] ?? '';
$snipeVerifySsl = !empty($snipeConfig['verify_ssl']);

$limit = min(200, SNIPEIT_MAX_MODELS_FETCH);

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
    global $snipeBaseUrl, $snipeApiToken, $snipeVerifySsl;

    if ($snipeBaseUrl === '' || $snipeApiToken === '') {
        throw new Exception('Snipe-IT API is not configured (missing base_url or api_token).');
    }

    $url = $snipeBaseUrl . '/api/v1/' . ltrim($endpoint, '/');

    $ch = curl_init();
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $snipeApiToken,
    ];

    $method = strtoupper($method);

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
 * @return array                  ['total' => X, 'rows' => [...]]
 * @throws Exception
 */
function get_bookable_models(
    int $page = 1,
    string $search = '',
    ?int $categoryId = null,
    ?string $sort = null,
    int $perPage = 50
): array {
    $page    = max(1, $page);
    $perPage = max(1, $perPage);

    $allRows      = [];
    $totalFromApi = null;

    $limit  = min(200, SNIPEIT_MAX_MODELS_FETCH); // per-API-call limit
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

        if (!empty($categoryId)) {
            $params['category_id'] = $categoryId;
        }

        $chunk = snipeit_request('GET', 'models', $params);

        if (!isset($chunk['rows']) || !is_array($chunk['rows'])) {
            break;
        }

        if ($totalFromApi === null && isset($chunk['total'])) {
            $totalFromApi = (int)$chunk['total'];
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

    // Determine total
    $total = $totalFromApi ?? count($allRows);
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
