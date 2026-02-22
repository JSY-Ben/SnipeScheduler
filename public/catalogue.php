<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$config   = load_config();
$authCfg  = $config['auth'] ?? [];
$isAdmin  = !empty($currentUser['is_admin']);
$isStaff  = !empty($currentUser['is_staff']) || $isAdmin;
$ldapEnabled = array_key_exists('ldap_enabled', $authCfg) ? !empty($authCfg['ldap_enabled']) : true;
$googleEnabled = !empty($authCfg['google_oauth_enabled']);
$msEnabled     = !empty($authCfg['microsoft_oauth_enabled']);

$bookingOverride = $_SESSION['booking_user_override'] ?? null;
$activeUser      = $bookingOverride ?: $currentUser;

$ldapCfg  = $config['ldap'] ?? [];
$appCfg   = $config['app'] ?? [];
$debugOn  = !empty($appCfg['debug']);
$blockCatalogueOverdue = array_key_exists('block_catalogue_overdue', $appCfg)
    ? !empty($appCfg['block_catalogue_overdue'])
    : true;
$overdueCacheTtl = 0;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['prefetch']) && !isset($_GET['ajax'])) {
    $query = $_GET;
    $query['prefetch'] = 1;
    $fullUrl = 'catalogue.php' . (empty($query) ? '' : '?' . http_build_query($query));
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Catalogue – Book Equipment</title>
        <link rel="stylesheet"
              href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="assets/style.css">
        <?= layout_theme_styles() ?>
    </head>
    <body class="p-4">
        <div class="loading-overlay">
            <div class="loading-card">
                <div class="loading-spinner" aria-hidden="true"></div>
                <div class="loading-text">Fetching assets...</div>
            </div>
        </div>
        <script>
            window.location.replace("<?= h($fullUrl) ?>");
        </script>
    </body>
    </html>
    <?php
    exit;
}

if (($_GET['ajax'] ?? '') === 'overdue_check') {
    header('Content-Type: application/json');
    if (!$blockCatalogueOverdue) {
        echo json_encode(['blocked' => false, 'assets' => []]);
        exit;
    }

    $bookingOverride = $_SESSION['booking_user_override'] ?? null;
    $activeUser      = $bookingOverride ?: $currentUser;

    $activeUserEmail = trim($activeUser['email'] ?? '');
    $activeUserUsername = trim($activeUser['username'] ?? '');
    $activeUserDisplay = trim($activeUser['display_name'] ?? '');
    $activeUserName = trim(trim($activeUser['first_name'] ?? '') . ' ' . trim($activeUser['last_name'] ?? ''));
    $cacheKey = strtolower(trim($activeUserEmail !== '' ? $activeUserEmail : ($activeUserUsername !== '' ? $activeUserUsername : $activeUserDisplay)));
    if ($cacheKey === '') {
        $cacheKey = 'user_' . (int)($activeUser['id'] ?? 0);
    }

    $cacheBucket = $_SESSION['overdue_check_cache'] ?? [];
    $cached = is_array($cacheBucket) && isset($cacheBucket[$cacheKey]) ? $cacheBucket[$cacheKey] : null;
    if (is_array($cached) && isset($cached['ts'], $cached['data']) && $overdueCacheTtl > 0 && (time() - (int)$cached['ts']) <= $overdueCacheTtl) {
        echo json_encode($cached['data']);
        exit;
    }

    try {
        $lookupKeys = build_lookup_keys(
            $activeUserEmail,
            $activeUserUsername,
            $activeUserDisplay,
            $activeUserName
        );
        $lookupSqlValues = build_sql_lookup_values(
            $activeUserEmail,
            $activeUserUsername,
            $activeUserDisplay,
            $activeUserName
        );

        $snipeUserId = 0;
        $lookupQueries = array_values(array_filter(array_unique([
            $activeUserEmail,
            $activeUserUsername,
            $activeUserDisplay,
            $activeUserName,
        ]), 'strlen'));

        foreach ($lookupQueries as $query) {
            try {
                $matched = find_single_user_by_email_or_name($query);
                $snipeUserId = (int)($matched['id'] ?? 0);
                if ($snipeUserId > 0) {
                    break;
                }
            } catch (Throwable $e) {
                // Try next identifier.
            }
        }

        $overdueAssets = fetch_overdue_assets_for_user($lookupSqlValues, $snipeUserId);

        $payload = [
            'blocked' => !empty($overdueAssets),
            'assets'  => $overdueAssets,
        ];
        if ($overdueCacheTtl > 0) {
            $_SESSION['overdue_check_cache'][$cacheKey] = [
                'ts'   => time(),
                'data' => $payload,
            ];
        }
        echo json_encode($payload);
    } catch (Throwable $e) {
        $payload = [
            'blocked' => false,
            'assets'  => [],
            'error'   => $debugOn ? $e->getMessage() : 'Unable to check overdue items at the moment.',
        ];
        if ($overdueCacheTtl > 0) {
            $_SESSION['overdue_check_cache'][$cacheKey] = [
                'ts'   => time(),
                'data' => $payload,
            ];
        }
        echo json_encode($payload);
    }
    exit;
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function http_post_form_json(string $url, array $fields, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('HTTP request failed: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status >= 400) {
        throw new Exception('HTTP request failed with status ' . $status . ': ' . $raw);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new Exception('Unexpected response format.');
    }
    return $data;
}

function http_get_json(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('HTTP request failed: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status >= 400) {
        throw new Exception('HTTP request failed with status ' . $status . ': ' . $raw);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new Exception('Unexpected response format.');
    }
    return $data;
}

function google_directory_search(string $q, array $config): array
{
    $dirCfg = $config['google_directory'] ?? [];
    $svcJson = $dirCfg['service_account_json'] ?? '';
    $svcPath = $dirCfg['service_account_path'] ?? '';
    $impersonate = trim($dirCfg['impersonated_user'] ?? '');

    if ($svcJson === '' && $svcPath !== '' && is_file($svcPath)) {
        $svcJson = file_get_contents($svcPath) ?: '';
    }

    if ($svcJson === '' || $impersonate === '') {
        return [];
    }

    $json = json_decode($svcJson, true);
    if (!is_array($json)) {
        throw new Exception('Google directory service account JSON is invalid.');
    }

    $clientEmail = $json['client_email'] ?? '';
    $privateKey  = $json['private_key'] ?? '';
    if ($clientEmail === '' || $privateKey === '') {
        throw new Exception('Google directory service account credentials are missing.');
    }

    $now = time();
    $header  = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode([
        'iss'   => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/admin.directory.user.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
        'sub'   => $impersonate,
    ]));

    $signingInput = $header . '.' . $payload;
    $signature = '';
    $key = openssl_pkey_get_private($privateKey);
    if (!$key || !openssl_sign($signingInput, $signature, $key, 'sha256')) {
        throw new Exception('Failed to sign Google service account JWT.');
    }
    openssl_pkey_free($key);
    $jwt = $signingInput . '.' . base64url_encode($signature);

    $token = http_post_form_json('https://oauth2.googleapis.com/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion'  => $jwt,
    ]);
    $accessToken = $token['access_token'] ?? '';
    if ($accessToken === '') {
        throw new Exception('Google directory token response missing access token.');
    }

    $qEsc = str_replace(['\\', '"'], ['\\\\', '\"'], $q);
    $qWild = '*' . $qEsc . '*';
    $query = 'email:' . $qWild
        . ' OR name:' . $qWild
        . ' OR givenName:' . $qWild
        . ' OR familyName:' . $qWild;
    $url = 'https://admin.googleapis.com/admin/directory/v1/users?'
        . http_build_query([
            'query'      => $query,
            'maxResults' => 20,
            'orderBy'    => 'email',
        ]);

    $data = http_get_json($url, [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ]);

    $results = [];
    $users = $data['users'] ?? [];
    if (is_array($users)) {
        foreach ($users as $user) {
            $email = $user['primaryEmail'] ?? '';
            $name  = $user['name']['fullName'] ?? '';
            if ($email === '' && $name === '') {
                continue;
            }
            $results[] = [
                'email' => $email,
                'name'  => $name !== '' ? $name : $email,
            ];
        }
    }

    return $results;
}

function entra_directory_search(string $q, array $config): array
{
    $accessToken = $_SESSION['ms_access_token'] ?? '';
    if ($accessToken === '') {
        return [];
    }
    $expiresAt = (int)($_SESSION['ms_access_token_expires_at'] ?? 0);
    if ($expiresAt > 0 && $expiresAt <= time()) {
        unset($_SESSION['ms_access_token'], $_SESSION['ms_access_token_expires_at']);
        return [];
    }

    $data = null;
    try {
        $qSearch = str_replace('"', '\"', $q);
        $search = '"displayName:' . $qSearch . '"'
            . ' OR "mail:' . $qSearch . '"'
            . ' OR "userPrincipalName:' . $qSearch . '"'
            . ' OR "givenName:' . $qSearch . '"'
            . ' OR "surname:' . $qSearch . '"';
        $url = 'https://graph.microsoft.com/v1.0/users?'
            . http_build_query([
                '$select' => 'displayName,mail,userPrincipalName',
                '$top'    => 20,
                '$count'  => 'true',
                '$search' => $search,
            ]);

        $data = http_get_json($url, [
            'Authorization: Bearer ' . $accessToken,
            'ConsistencyLevel: eventual',
            'Accept: application/json',
        ]);
    } catch (Throwable $e) {
        $data = null;
    }

    if (!is_array($data)) {
        $qEsc = str_replace("'", "''", $q);
        $filter = "startswith(displayName,'{$qEsc}')"
            . " or startswith(mail,'{$qEsc}')"
            . " or startswith(userPrincipalName,'{$qEsc}')"
            . " or startswith(givenName,'{$qEsc}')"
            . " or startswith(surname,'{$qEsc}')";
        $url = 'https://graph.microsoft.com/v1.0/users?'
            . http_build_query([
                '$select' => 'displayName,mail,userPrincipalName',
                '$top'    => 20,
                '$filter' => $filter,
            ]);

        $data = http_get_json($url, [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);
    }

    $results = [];
    $users = $data['value'] ?? [];
    if (is_array($users)) {
        foreach ($users as $user) {
            $email = $user['mail'] ?? ($user['userPrincipalName'] ?? '');
            $name  = $user['displayName'] ?? '';
            if ($email === '' && $name === '') {
                continue;
            }
            $results[] = [
                'email' => $email,
                'name'  => $name !== '' ? $name : $email,
            ];
        }
    }

    return $results;
}

// Staff-only directory autocomplete endpoint
if ($isStaff && ($_GET['ajax'] ?? '') === 'user_search') {
    header('Content-Type: application/json');

    if (!$ldapEnabled && !$googleEnabled && !$msEnabled) {
        http_response_code(403);
        echo json_encode(['error' => 'Directory search is disabled.']);
        exit;
    }
    if ($msEnabled && !$ldapEnabled && !$googleEnabled && empty($_SESSION['ms_access_token'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Microsoft directory search requires signing in with Microsoft.']);
        exit;
    }

    $q = trim($_GET['q'] ?? '');
    if ($q === '' || strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }

    try {
        $results = [];
        $seen = [];
        $addResult = static function (string $email, string $name) use (&$results, &$seen): void {
            $key = strtolower(trim($email !== '' ? $email : $name));
            if ($key === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $results[] = [
                'email' => $email,
                'name'  => $name !== '' ? $name : $email,
            ];
        };

        if ($ldapEnabled) {
            if (!empty($ldapCfg['ignore_cert'])) {
                putenv('LDAPTLS_REQCERT=never');
            }

            $ldap = @ldap_connect($ldapCfg['host']);
            if (!$ldap) {
                throw new Exception('Cannot connect to LDAP host');
            }

            ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

            if (!@ldap_bind($ldap, $ldapCfg['bind_dn'], $ldapCfg['bind_password'])) {
                throw new Exception('LDAP service bind failed: ' . ldap_error($ldap));
            }

            $filter = sprintf(
                '(|(mail=*%1$s*)(displayName=*%1$s*)(sAMAccountName=*%1$s*))',
                ldap_escape($q, null, LDAP_ESCAPE_FILTER)
            );

            $attrs = ['mail', 'displayName', 'givenName', 'sn', 'sAMAccountName'];
            $search = @ldap_search($ldap, $ldapCfg['base_dn'], $filter, $attrs, 0, 20);
            $entries = $search ? ldap_get_entries($ldap, $search) : ['count' => 0];

            for ($i = 0; $i < ($entries['count'] ?? 0); $i++) {
                $e    = $entries[$i];
                $mail = $e['mail'][0] ?? '';
                $dn   = $e['displayname'][0] ?? '';
                $fn   = $e['givenname'][0] ?? '';
                $ln   = $e['sn'][0] ?? '';
                $name = $dn !== '' ? $dn : trim($fn . ' ' . $ln);
                $sam  = $e['samaccountname'][0] ?? '';

                $addResult($mail, $name !== '' ? $name : $mail);
            }

            ldap_unbind($ldap);
        }

        if ($googleEnabled) {
            $googleResults = google_directory_search($q, $config);
            foreach ($googleResults as $row) {
                $addResult($row['email'] ?? '', $row['name'] ?? '');
            }
        }

        if ($msEnabled) {
            $entraResults = entra_directory_search($q, $config);
            foreach ($entraResults as $row) {
                $addResult($row['email'] ?? '', $row['name'] ?? '');
            }
        }

        echo json_encode(['results' => $results]);
    } catch (Throwable $e) {
        if (isset($ldap) && $ldap) {
            @ldap_unbind($ldap);
        }
        http_response_code(500);
        echo json_encode(['error' => $debugOn ? $e->getMessage() : 'Directory search error']);
    }
    exit;
}

// Handle staff override selection
if ($isStaff && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'set_booking_user') {
    $revert   = isset($_POST['booking_user_revert']) && $_POST['booking_user_revert'] === '1';
    $selEmail = trim($_POST['booking_user_email'] ?? '');
    $selName  = trim($_POST['booking_user_name'] ?? '');
    if ($revert || $selEmail === '') {
        unset($_SESSION['booking_user_override']);
    } else {
        $_SESSION['booking_user_override'] = [
            'email'      => $selEmail,
            'first_name' => $selName,
            'last_name'  => '',
            'id'         => 0,
        ];
    }
    header('Location: catalogue.php');
    exit;
}

// Active nav + staff flag
$active  = basename($_SERVER['PHP_SELF']);

// ---------------------------------------------------------------------
// Helper: decode Snipe-IT strings safely
// ---------------------------------------------------------------------
function label_safe(?string $str): string
{
    if ($str === null) {
        return '';
    }
    $decoded = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return htmlspecialchars($decoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function format_overdue_date($val): string
{
    if (is_array($val)) {
        $val = $val['datetime'] ?? ($val['date'] ?? '');
    }
    if (empty($val)) {
        return '';
    }
    return app_format_date($val);
}

function normalize_lookup_key(?string $value): string
{
    $value = trim((string)($value ?? ''));
    if ($value === '') {
        return '';
    }

    $lower = strtolower($value);
    if (strpos($lower, '@') !== false) {
        // Preserve emails/usernames with domains.
        return $lower;
    }

    // Normalize names for more reliable matching.
    $lower = preg_replace('/[(),]+/', ' ', $lower);
    $lower = preg_replace('/\s+/', ' ', $lower);
    return trim($lower);
}

function build_lookup_keys(string $email, string $username, string $display, string $name): array
{
    $raw = [$email, $username, $display, $name];
    $keys = array_map('normalize_lookup_key', $raw);

    $nameCandidates = array_filter([$name, $display], 'strlen');
    foreach ($nameCandidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        if (strpos($candidate, ',') !== false) {
            $parts = array_map('trim', explode(',', $candidate, 2));
            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $keys[] = normalize_lookup_key($parts[1] . ' ' . $parts[0]);
            }
        } else {
            $parts = preg_split('/\s+/', $candidate);
            if (count($parts) >= 2) {
                $first = array_shift($parts);
                $last = array_pop($parts);
                if ($first !== '' && $last !== '') {
                    $keys[] = normalize_lookup_key($last . ' ' . $first);
                }
            }
        }
    }

    $keys = array_values(array_filter(array_unique($keys), 'strlen'));
    return $keys;
}

function build_name_variants(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $variants = [$value];
    if (strpos($value, ',') !== false) {
        $parts = array_map('trim', explode(',', $value, 2));
        if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
            $variants[] = $parts[1] . ' ' . $parts[0];
        }
    } else {
        $parts = preg_split('/\\s+/', $value);
        if (count($parts) >= 2) {
            $first = array_shift($parts);
            $last = array_pop($parts);
            if ($first !== '' && $last !== '') {
                $variants[] = $last . ' ' . $first;
            }
        }
    }

    $variants = array_values(array_filter(array_unique($variants), 'strlen'));
    return $variants;
}

function build_sql_lookup_values(string $email, string $username, string $display, string $name): array
{
    $email = strtolower(trim($email));
    $username = strtolower(trim($username));
    $nameVariants = array_merge(
        build_name_variants($name),
        build_name_variants($display)
    );
    $nameVariants = array_values(array_filter(array_unique(array_map('strtolower', $nameVariants)), 'strlen'));

    return [
        'emails' => $email !== '' ? [$email] : [],
        'usernames' => $username !== '' ? [$username] : [],
        'names' => $nameVariants,
    ];
}

function expected_to_timestamp($value): ?int
{
    if (is_array($value)) {
        $value = $value['datetime'] ?? ($value['date'] ?? '');
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {
        $value .= ' 23:59:59';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return $ts;
}

function fetch_overdue_assets_for_user(array $lookup, int $snipeUserId): array
{
    global $pdo;

    $where = [];
    $params = [];
    if ($snipeUserId > 0) {
        $where[] = 'assigned_to_id = ?';
        $params[] = $snipeUserId;
    }
    if (!empty($lookup['emails'])) {
        $placeholders = implode(',', array_fill(0, count($lookup['emails']), '?'));
        $where[] = "(assigned_to_email IS NOT NULL AND LOWER(assigned_to_email) IN ({$placeholders}))";
        $params = array_merge($params, $lookup['emails']);
    }
    if (!empty($lookup['usernames'])) {
        $placeholders = implode(',', array_fill(0, count($lookup['usernames']), '?'));
        $where[] = "(assigned_to_username IS NOT NULL AND LOWER(assigned_to_username) IN ({$placeholders}))";
        $params = array_merge($params, $lookup['usernames']);
    }
    if (!empty($lookup['names'])) {
        $placeholders = implode(',', array_fill(0, count($lookup['names']), '?'));
        $where[] = "(assigned_to_name IS NOT NULL AND LOWER(assigned_to_name) IN ({$placeholders}))";
        $params = array_merge($params, $lookup['names']);
    }

    if (empty($where)) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT asset_tag, model_name, expected_checkin
          FROM checked_out_asset_cache
         WHERE " . implode(' OR ', $where)
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $now = time();
    $overdueAssets = [];
    foreach ($rows as $row) {
        $ts = expected_to_timestamp($row['expected_checkin'] ?? '');
        if ($ts === null || $ts > $now) {
            continue;
        }
        $tag = $row['asset_tag'] ?? 'Unknown tag';
        $modelName = $row['model_name'] ?? '';
        $due = format_overdue_date($row['expected_checkin'] ?? '');
        $overdueAssets[] = [
            'tag' => $tag,
            'model' => $modelName,
            'due' => $due,
        ];
    }

    return $overdueAssets;
}

function row_assigned_to_matches_user(array $row, array $keys, int $userId): bool
{
    $assigned = $row['assigned_to'] ?? ($row['assigned_to_fullname'] ?? '');
    $assignedId = 0;
    $assignedKeys = [];

    if (is_array($assigned)) {
        $assignedId = (int)($assigned['id'] ?? 0);
        $assignedKeys[] = $assigned['email'] ?? '';
        $assignedKeys[] = $assigned['username'] ?? '';
        $assignedKeys[] = $assigned['name'] ?? '';
    } elseif (is_string($assigned)) {
        $assignedKeys[] = $assigned;
    }

    if ($userId > 0 && $assignedId === $userId) {
        return true;
    }

    foreach ($assignedKeys as $key) {
        $norm = normalize_lookup_key($key);
        if ($norm !== '' && in_array($norm, $keys, true)) {
            return true;
        }
    }

    return false;
}

function normalize_model_notes_text($notes): string
{
    if (is_array($notes)) {
        $notes = $notes['text'] ?? '';
    }

    $text = trim((string)$notes);
    if ($text === '') {
        return '';
    }

    $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decoded = preg_replace('/<br\\s*\\/?\\s*>/i', "\n", $decoded);
    $decoded = preg_replace('/<\\/p\\s*>/i', "\n\n", $decoded);
    $plain = strip_tags($decoded);
    $plain = preg_replace('/\\R{3,}/', "\n\n", $plain);
    return trim($plain);
}

function fetch_catalogue_model_bookings(PDO $pdo, int $modelId, array $assetIds): array
{
    $allowedStatuses = ['pending', 'confirmed', 'completed', 'missed'];
    $bookingsById = [];

    $modelSql = "
        SELECT
            r.id,
            r.status,
            r.start_datetime,
            r.end_datetime,
            COALESCE(SUM(ri.quantity), 0) AS model_qty
        FROM reservations r
        JOIN reservation_items ri
          ON ri.reservation_id = r.id
        WHERE ri.model_id = :model_id
          AND r.status IN ('pending','confirmed','completed','missed')
        GROUP BY r.id, r.status, r.start_datetime, r.end_datetime
    ";
    $modelStmt = $pdo->prepare($modelSql);
    $modelStmt->execute([':model_id' => $modelId]);
    $modelRows = $modelStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($modelRows as $row) {
        $reservationId = (int)($row['id'] ?? 0);
        if ($reservationId <= 0) {
            continue;
        }
        $bookingsById[$reservationId] = [
            'id' => $reservationId,
            'status' => (string)($row['status'] ?? ''),
            'start_datetime' => (string)($row['start_datetime'] ?? ''),
            'end_datetime' => (string)($row['end_datetime'] ?? ''),
            'quantity' => max(1, (int)($row['model_qty'] ?? 0)),
            'via_model' => true,
            'via_asset' => false,
        ];
    }

    $assetIds = array_values(array_filter(array_unique(array_map('intval', $assetIds)), static function (int $assetId): bool {
        return $assetId > 0;
    }));

    if (!empty($assetIds)) {
        $assetChunks = array_chunk($assetIds, 250);
        foreach ($assetChunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $assetSql = "
                SELECT id, status, start_datetime, end_datetime
                  FROM reservations
                 WHERE status IN ('pending','confirmed','completed','missed')
                   AND asset_id IN ({$placeholders})
            ";
            $assetStmt = $pdo->prepare($assetSql);
            $assetStmt->execute($chunk);
            $assetRows = $assetStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($assetRows as $row) {
                $reservationId = (int)($row['id'] ?? 0);
                if ($reservationId <= 0) {
                    continue;
                }

                if (isset($bookingsById[$reservationId])) {
                    $bookingsById[$reservationId]['via_asset'] = true;
                    continue;
                }

                $bookingsById[$reservationId] = [
                    'id' => $reservationId,
                    'status' => (string)($row['status'] ?? ''),
                    'start_datetime' => (string)($row['start_datetime'] ?? ''),
                    'end_datetime' => (string)($row['end_datetime'] ?? ''),
                    'quantity' => 1,
                    'via_model' => false,
                    'via_asset' => true,
                ];
            }
        }
    }

    $result = [];
    foreach ($bookingsById as $booking) {
        $status = strtolower(trim((string)($booking['status'] ?? '')));
        if (!in_array($status, $allowedStatuses, true)) {
            continue;
        }

        if (!empty($booking['via_model']) && !empty($booking['via_asset'])) {
            $source = 'model_and_asset';
        } elseif (!empty($booking['via_asset'])) {
            $source = 'asset';
        } else {
            $source = 'model';
        }

        $startRaw = (string)($booking['start_datetime'] ?? '');
        $endRaw = (string)($booking['end_datetime'] ?? '');

        $result[] = [
            'id' => (int)($booking['id'] ?? 0),
            'status' => $status,
            'start_datetime' => $startRaw,
            'end_datetime' => $endRaw,
            'start_display' => app_format_datetime($startRaw),
            'end_display' => app_format_datetime($endRaw),
            'quantity' => max(1, (int)($booking['quantity'] ?? 1)),
            'source' => $source,
        ];
    }

    usort($result, static function (array $a, array $b): int {
        $startCmp = strcmp((string)($a['start_datetime'] ?? ''), (string)($b['start_datetime'] ?? ''));
        if ($startCmp !== 0) {
            return $startCmp;
        }
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });

    return $result;
}

if (($_GET['ajax'] ?? '') === 'model_details') {
    header('Content-Type: application/json');

    $modelId = isset($_GET['model_id']) ? (int)$_GET['model_id'] : 0;
    if ($modelId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid model ID.']);
        exit;
    }

    try {
        $model = get_model($modelId);
        if (empty($model['id'])) {
            throw new RuntimeException('Model not found.');
        }

        $notes = normalize_model_notes_text($model['notes'] ?? '');
        $assetIds = [];
        $warnings = [];

        try {
            $assetsCount = isset($model['assets_count']) && is_numeric($model['assets_count'])
                ? (int)$model['assets_count']
                : 0;
            $assetLookupLimit = min(5000, max(500, $assetsCount + 25));
            $assets = list_assets_by_model($modelId, $assetLookupLimit);
            foreach ($assets as $asset) {
                $assetId = (int)($asset['id'] ?? 0);
                if ($assetId > 0) {
                    $assetIds[] = $assetId;
                }
            }
        } catch (Throwable $e) {
            $warnings[] = $debugOn
                ? 'Could not load model assets: ' . $e->getMessage()
                : 'Could not load all model assets.';
        }

        $bookings = fetch_catalogue_model_bookings($pdo, $modelId, $assetIds);

        echo json_encode([
            'model' => [
                'id' => (int)$modelId,
                'name' => (string)($model['name'] ?? ('Model #' . $modelId)),
            ],
            'notes' => $notes,
            'bookings' => $bookings,
            'asset_count' => count($assetIds),
            'warnings' => $warnings,
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error' => $debugOn
                ? $e->getMessage()
                : 'Unable to load model details right now.',
        ]);
    }
    exit;
}

// ---------------------------------------------------------------------
// Current basket count (for "View basket (X)")
// ---------------------------------------------------------------------
$basket       = $_SESSION['basket'] ?? [];
$basketCount  = 0;
foreach ($basket as $qty) {
    $basketCount += (int)$qty;
}

// ---------------------------------------------------------------------
// Cached overdue state (session cache)
// ---------------------------------------------------------------------
$overdueAssets = [];
$overdueErr = '';
$catalogueBlocked = false;
$skipOverdueCheck = !$blockCatalogueOverdue;
$activeUserEmail = trim($activeUser['email'] ?? '');
$activeUserUsername = trim($activeUser['username'] ?? '');
$activeUserDisplay = trim($activeUser['display_name'] ?? '');
$activeUserName = trim(trim($activeUser['first_name'] ?? '') . ' ' . trim($activeUser['last_name'] ?? ''));
$cacheKey = strtolower(trim($activeUserEmail !== '' ? $activeUserEmail : ($activeUserUsername !== '' ? $activeUserUsername : $activeUserDisplay)));
if ($cacheKey === '') {
    $cacheKey = 'user_' . (int)($activeUser['id'] ?? 0);
}
$lookupKeys = build_lookup_keys(
    $activeUserEmail,
    $activeUserUsername,
    $activeUserDisplay,
    $activeUserName
);
$lookupSqlValues = build_sql_lookup_values(
    $activeUserEmail,
    $activeUserUsername,
    $activeUserDisplay,
    $activeUserName
);
$cacheBucket = $_SESSION['overdue_check_cache'] ?? [];
$cached = is_array($cacheBucket) && isset($cacheBucket[$cacheKey]) ? $cacheBucket[$cacheKey] : null;
if (!$skipOverdueCheck && is_array($cached) && isset($cached['ts'], $cached['data']) && $overdueCacheTtl > 0 && (time() - (int)$cached['ts']) <= $overdueCacheTtl) {
    $cachedData = $cached['data'];
    $catalogueBlocked = !empty($cachedData['blocked']);
    $overdueAssets = $cachedData['assets'] ?? [];
    $overdueErr = $cachedData['error'] ?? '';
}
if (!$skipOverdueCheck && !$catalogueBlocked && empty($overdueAssets)) {
    try {
        $snipeUserId = 0;
        $lookupQueries = array_values(array_filter(array_unique([
            $activeUserEmail,
            $activeUserUsername,
            $activeUserDisplay,
            $activeUserName,
        ]), 'strlen'));

        foreach ($lookupQueries as $query) {
            try {
                $matched = find_single_user_by_email_or_name($query);
                $snipeUserId = (int)($matched['id'] ?? 0);
                if ($snipeUserId > 0) {
                    break;
                }
            } catch (Throwable $e) {
                // Try next identifier.
            }
        }

        $overdueAssets = fetch_overdue_assets_for_user($lookupSqlValues, $snipeUserId);
        $catalogueBlocked = !empty($overdueAssets);
    } catch (Throwable $e) {
        $overdueErr = $debugOn ? $e->getMessage() : 'Unable to check overdue items at the moment.';
    }
}

// ---------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------
$searchRaw    = trim($_GET['q'] ?? '');
$categoryRaw  = trim($_GET['category'] ?? '');
$sortRaw      = trim($_GET['sort'] ?? '');
$page         = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$windowStartRaw = trim($_GET['start_datetime'] ?? '');
$windowEndRaw   = trim($_GET['end_datetime'] ?? '');

// Normalise filters
$search   = $searchRaw !== '' ? $searchRaw : null;
$category = ctype_digit($categoryRaw) ? (int)$categoryRaw : null;
$sort     = $sortRaw !== '' ? $sortRaw : null;

if ($windowStartRaw === '' && $windowEndRaw === '') {
    $sessionStart = trim((string)($_SESSION['reservation_window_start'] ?? ''));
    $sessionEnd   = trim((string)($_SESSION['reservation_window_end'] ?? ''));
    if ($sessionStart !== '' && $sessionEnd !== '') {
        $windowStartRaw = $sessionStart;
        $windowEndRaw   = $sessionEnd;
    }
}
if ($windowStartRaw === '' && $windowEndRaw === '') {
    $windowTz = app_get_timezone($config);
    $windowStartDt = $windowTz ? new DateTime('now', $windowTz) : new DateTime('now');
    $windowEndDt = clone $windowStartDt;
    $windowEndDt->modify('+1 day');
    $windowEndDt->setTime(9, 0, 0);

    $windowStartRaw = $windowStartDt->format('Y-m-d\TH:i');
    $windowEndRaw   = $windowEndDt->format('Y-m-d\TH:i');
}

$windowStartTs = $windowStartRaw !== '' ? strtotime($windowStartRaw) : false;
$windowEndTs   = $windowEndRaw !== '' ? strtotime($windowEndRaw) : false;
$windowActive  = false;
$windowError   = '';
if ($windowStartRaw !== '' || $windowEndRaw !== '') {
    if ($windowStartTs === false || $windowEndTs === false) {
        $windowError = 'Please enter a valid start and end date/time.';
    } elseif ($windowEndTs <= $windowStartTs) {
        $windowError = 'End date/time must be after start date/time.';
    } else {
        $windowActive = true;
        $_SESSION['reservation_window_start'] = $windowStartRaw;
        $_SESSION['reservation_window_end']   = $windowEndRaw;
    }
}

// Pagination limit (from config constants)
$perPage = defined('CATALOGUE_ITEMS_PER_PAGE')
    ? (int)CATALOGUE_ITEMS_PER_PAGE
    : 12;

// Deferred loading of categories/models happens after initial render flush.
$categories   = [];
$categoryErr  = '';
$allowedCategoryMap = [];
$allowedCategoryIds = [];
$models      = [];
$modelErr    = '';
$totalModels = 0;
$totalPages  = 1;
$nowIso      = date('Y-m-d H:i:s');
$windowStartIso = $windowActive ? date('Y-m-d H:i:s', $windowStartTs) : '';
$windowEndIso   = $windowActive ? date('Y-m-d H:i:s', $windowEndTs) : '';
$checkedOutCounts = [];
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Catalogue – Book Equipment</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4"
      data-catalogue-overdue="<?= $blockCatalogueOverdue ? '1' : '0' ?>"
      data-date-format="<?= h(app_get_date_format()) ?>"
      data-time-format="<?= h(app_get_time_format()) ?>">
<div id="catalogue-loading" class="loading-overlay" aria-live="polite" aria-busy="true">
    <div class="loading-card">
        <div class="loading-spinner" aria-hidden="true"></div>
        <div class="loading-text">Fetching assets...</div>
    </div>
</div>
<?php
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
if (function_exists('ob_flush')) {
    @ob_flush();
}
@flush();

// ---------------------------------------------------------------------
// Load categories from Snipe-IT (deferred so loader shows immediately)
// ---------------------------------------------------------------------
try {
    $categories = get_model_categories();
} catch (Throwable $e) {
    $categories  = [];
    $categoryErr = $e->getMessage();
}

// Optional admin-controlled allowlist for categories shown in the filter
$allowedCfg = $config['catalogue']['allowed_categories'] ?? [];
if (is_array($allowedCfg)) {
    foreach ($allowedCfg as $cid) {
        if (ctype_digit((string)$cid) || is_int($cid)) {
            $cid = (int)$cid;
            $allowedCategoryMap[$cid] = true;
            $allowedCategoryIds[]     = $cid;
        }
    }
}

// ---------------------------------------------------------------------
// Load models from Snipe-IT (deferred so loader shows immediately)
// ---------------------------------------------------------------------
try {
    $data = get_bookable_models($page, $search ?? '', $category, $sort, $perPage, $allowedCategoryIds);

    if (isset($data['rows']) && is_array($data['rows'])) {
        $models = $data['rows'];
    }

    if (isset($data['total'])) {
        $totalModels = (int)$data['total'];
    } else {
        $totalModels = count($models);
    }

    if ($perPage > 0) {
        $totalPages = max(1, (int)ceil($totalModels / $perPage));
    } else {
        $totalPages = 1;
    }
} catch (Throwable $e) {
    $models   = [];
    $modelErr = $e->getMessage();
}

if (!empty($models)) {
    try {
        $stmt = $pdo->query("
            SELECT model_id, COUNT(*) AS cnt
              FROM checked_out_asset_cache
             GROUP BY model_id
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $mid = (int)($row['model_id'] ?? 0);
            if ($mid > 0) {
                $checkedOutCounts[$mid] = (int)($row['cnt'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        $checkedOutCounts = [];
    }
}

// Apply allowlist if configured; otherwise show all categories returned by Snipe-IT
if (!empty($allowedCategoryMap) && !empty($categories)) {
    $categories = array_values(array_filter($categories, function ($cat) use ($allowedCategoryMap) {
        $id = isset($cat['id']) ? (int)$cat['id'] : 0;
        return $id > 0 && isset($allowedCategoryMap[$id]);
    }));
}
?>
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Equipment catalogue</h1>
            <div class="page-subtitle">
                Browse bookable equipment models and add them to your basket.
            </div>
        </div>

        <!-- App navigation -->
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <?php if ($blockCatalogueOverdue): ?>
            <div id="overdue-warning" class="alert alert-warning<?= $overdueErr ? '' : ' d-none' ?>">
                <?= h($overdueErr) ?>
            </div>
        <?php endif; ?>

        <!-- Top bar -->
        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= htmlspecialchars(trim($currentUser['first_name'] . ' ' . $currentUser['last_name'])) ?></strong>
                (<?= htmlspecialchars($currentUser['email']) ?>)
            </div>
            <div class="top-bar-actions d-flex gap-2">
                <a href="basket.php"
                   class="btn btn-lg btn-primary fw-semibold shadow-sm px-4"
                   style="font-size:16px;"
                   id="view-basket-btn">
                    View basket<?= $basketCount > 0 ? ' (' . $basketCount . ')' : '' ?>
                </a>
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if ($isStaff): ?>
            <div class="alert alert-info d-flex flex-column flex-md-row align-items-md-center justify-content-md-between booking-for-alert">
                <div class="mb-2 mb-md-0">
                    <strong>Booking for:</strong>
                    <?= h($activeUser['email'] ?? '') ?>
                    <?php if (!empty($activeUser['first_name'])): ?>
                        (<?= h(trim(($activeUser['first_name'] ?? '') . ' ' . ($activeUser['last_name'] ?? ''))) ?>)
                    <?php endif; ?>
                </div>
                <form method="post" id="booking_user_form" class="d-flex gap-2 mb-0 flex-wrap position-relative" style="z-index: 9998;">
                    <input type="hidden" name="mode" value="set_booking_user">
                    <input type="hidden" name="booking_user_email" id="booking_user_email">
                    <input type="hidden" name="booking_user_name" id="booking_user_name">
                    <div class="position-relative">
                        <input type="text"
                               id="booking_user_input"
                               class="form-control form-control-sm"
                               placeholder="Start typing email or name"
                               autocomplete="off">
                        <div class="list-group position-absolute w-100"
                             id="booking_user_suggestions"
                             style="z-index: 9999; max-height: 260px; overflow-y: auto; display: none; box-shadow: 0 12px 24px rgba(0,0,0,0.18);"></div>
                    </div>
                    <button class="btn btn-sm btn-primary" type="submit">Use</button>
                    <button class="btn btn-sm btn-outline-secondary" type="submit" name="booking_user_revert" value="1">Revert to logged in user</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($blockCatalogueOverdue): ?>
            <div id="overdue-alert" class="alert alert-danger<?= $catalogueBlocked ? '' : ' d-none' ?>">
                <div class="fw-semibold mb-2">Catalogue unavailable</div>
                <div class="mb-2">
                    You have overdue items. Please return them before booking more equipment.
                </div>
                <ul class="mb-0" id="overdue-list">
                    <?php foreach ($overdueAssets as $asset): ?>
                        <?php
                            $tag = $asset['tag'] ?? 'Unknown tag';
                            $modelName = $asset['model'] ?? '';
                            $due = $asset['due'] ?? '';
                        ?>
                        <li>
                            <?= h($tag) ?>
                            <?= $modelName !== '' ? ' (' . h($modelName) . ')' : '' ?>
                            <?= $due !== '' ? ' — due ' . h($due) : '' ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div id="catalogue-content" class="<?= $catalogueBlocked ? 'd-none' : '' ?>">
            <?php if ($categoryErr): ?>
                <div class="alert alert-warning">
                    Could not load categories from Snipe-IT: <?= htmlspecialchars($categoryErr) ?>
                </div>
            <?php endif; ?>

            <?php if ($modelErr): ?>
                <div class="alert alert-danger">
                    Error talking to Snipe-IT (models): <?= htmlspecialchars($modelErr) ?>
                </div>
            <?php endif; ?>

        <!-- Filters -->

        <form class="filter-panel mb-4" method="get" action="catalogue.php" id="catalogue-filter-form">
            <div class="filter-panel__header d-flex align-items-center gap-3">
                <span class="filter-panel__dot"></span>
                <div class="filter-panel__title">SEARCH</div>
            </div>

            <input type="hidden" name="start_datetime" value="<?= h($windowStartRaw) ?>">
            <input type="hidden" name="end_datetime" value="<?= h($windowEndRaw) ?>">
            <input type="hidden" name="prefetch" value="1">

            <div class="row g-3 align-items-end">
                <div class="col-12 col-lg-5">
                    <label class="form-label mb-1 fw-semibold">Search by name</label>
                    <div class="input-group filter-search">
                        <span class="input-group-text filter-search__icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/>
                                <line x1="15.5" y1="15.5" x2="21" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <input type="text"
                               name="q"
                               class="form-control form-control-lg filter-search__input"
                               placeholder="Search by model name or manufacturer"
                               value="<?= htmlspecialchars($searchRaw) ?>">
                    </div>
                </div>

                <div class="col-6 col-lg-3">
                    <label class="form-label mb-1 fw-semibold">Category</label>
                    <select name="category" class="form-select">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <?php
                            $cid   = (int)($cat['id'] ?? 0);
                            $cname = $cat['name'] ?? '';
                            ?>
                            <option value="<?= $cid ?>"
                                <?= ($category === $cid) ? 'selected' : '' ?>>
                                <?= label_safe($cname) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label mb-1 fw-semibold">Sort</label>
                    <select name="sort" class="form-select">
                        <option value="">Model name (A–Z)</option>
                        <option value="name_asc"   <?= $sort === 'name_asc'   ? 'selected' : '' ?>>Model Name (Ascending)</option>
                        <option value="name_desc"  <?= $sort === 'name_desc'  ? 'selected' : '' ?>>Model Name (Descending)</option>
                        <option value="manu_asc"   <?= $sort === 'manu_asc'   ? 'selected' : '' ?>>Manufacturer (Ascending)</option>
                        <option value="manu_desc"  <?= $sort === 'manu_desc'  ? 'selected' : '' ?>>Manufacturer (Descending)</option>
                        <option value="units_asc"  <?= $sort === 'units_asc'  ? 'selected' : '' ?>>Units in Total (Ascending)</option>
                        <option value="units_desc" <?= $sort === 'units_desc' ? 'selected' : '' ?>>Units in Total (Descending)</option>
                    </select>
                </div>

                <div class="col-12 col-lg-2 d-grid">
                    <button class="btn btn-primary btn-lg" type="submit">Filter results</button>
                </div>
            </div>
        </form>

        <form class="filter-panel filter-panel--compact mb-4" method="get" action="catalogue.php" id="catalogue-window-form">
            <div class="filter-panel__header d-flex align-items-center gap-3">
                <span class="filter-panel__dot"></span>
                <div class="filter-panel__title">RESERVATION WINDOW</div>
            </div>
            <input type="hidden" name="q" value="<?= h($searchRaw) ?>">
            <input type="hidden" name="category" value="<?= h($categoryRaw) ?>">
            <input type="hidden" name="sort" value="<?= h($sortRaw) ?>">
            <input type="hidden" name="prefetch" value="1">
            <div class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Start date &amp; time</label>
                    <input type="datetime-local"
                           name="start_datetime"
                           id="catalogue_start_datetime"
                           class="form-control form-control-lg"
                           value="<?= h($windowStartRaw) ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">End date &amp; time</label>
                    <input type="datetime-local"
                           name="end_datetime"
                           id="catalogue_end_datetime"
                           class="form-control form-control-lg"
                           value="<?= h($windowEndRaw) ?>">
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary btn-lg w-100 flex-md-fill mt-3 mt-md-0 reservation-window-btn" type="button" id="catalogue-today-btn">
                        Today
                    </button>
                </div>
            </div>
            <?php if ($windowError !== ''): ?>
                <div class="text-danger small mt-2"><?= h($windowError) ?></div>
            <?php endif; ?>
        </form>

        <?php if (empty($models) && !$modelErr): ?>
            <div class="alert alert-info">
                No models found. Try adjusting your filters.
            </div>
        <?php endif; ?>

        <?php if (!empty($models)): ?>
            <div class="row g-3">
                <?php foreach ($models as $model): ?>
                    <?php
                    $modelId    = (int)($model['id'] ?? 0);
                    $name       = $model['name'] ?? 'Model';
                    $manuName   = $model['manufacturer']['name'] ?? '';
                    $catName    = $model['category']['name'] ?? '';
                    $imagePath  = $model['image'] ?? '';
                    $assetCount = null;
                    $freeNow     = 0;
                    $maxQty      = 0;
                    $isRequestable = false;
                    try {
                        $assetCount = count_requestable_assets_by_model($modelId);

                        if ($windowActive) {
                            $stmt = $pdo->prepare("
                                SELECT
                                    COALESCE(SUM(CASE WHEN r.status IN ('pending','confirmed') THEN ri.quantity END), 0) AS pending_qty,
                                    COALESCE(SUM(CASE WHEN r.status = 'completed' THEN ri.quantity END), 0) AS completed_qty
                                FROM reservation_items ri
                                JOIN reservations r ON r.id = ri.reservation_id
                                WHERE ri.model_id = :mid
                                  AND r.status IN ('pending','confirmed','completed')
                                  AND (r.start_datetime < :end AND r.end_datetime > :start)
                            ");
                            $stmt->execute([
                                ':mid' => $modelId,
                                ':start' => $windowStartIso,
                                ':end' => $windowEndIso,
                            ]);
                        } else {
                            // Active reservations overlapping "now"
                            $stmt = $pdo->prepare("
                                SELECT
                                    COALESCE(SUM(CASE WHEN r.status IN ('pending','confirmed') THEN ri.quantity END), 0) AS pending_qty,
                                    COALESCE(SUM(CASE WHEN r.status = 'completed' THEN ri.quantity END), 0) AS completed_qty
                                FROM reservation_items ri
                                JOIN reservations r ON r.id = ri.reservation_id
                                WHERE ri.model_id = :mid
                                  AND r.status IN ('pending','confirmed','completed')
                                  AND r.start_datetime <= :now
                                  AND r.end_datetime   > :now
                            ");
                            $stmt->execute([
                                ':mid' => $modelId,
                                ':now' => $nowIso,
                            ]);
                        }
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        $pendingQty   = $row ? (int)$row['pending_qty'] : 0;

                        // How many are actually still checked out (from local cache)
                        if (array_key_exists($modelId, $checkedOutCounts)) {
                            $activeCheckedOut = $checkedOutCounts[$modelId];
                        } else {
                            $activeCheckedOut = count_checked_out_assets_by_model($modelId);
                        }

                        $booked = $pendingQty + $activeCheckedOut;
                        $freeNow = max(0, $assetCount - $booked);
                        $maxQty = $freeNow;
                        $isRequestable = $assetCount > 0;
                    } catch (Throwable $e) {
                        $assetCount = $assetCount ?? 0;
                        $freeNow    = 0;
                        $maxQty     = 0;
                        $isRequestable = $assetCount > 0;
                    }
                    $notes      = $model['notes'] ?? '';
                    if (is_array($notes)) {
                        $notes = $notes['text'] ?? '';
                    }

                    $proxiedImage = '';
                    if ($imagePath !== '') {
                        $proxiedImage = 'image_proxy.php?src=' . urlencode($imagePath);
                    }
                    ?>
                    <div class="col-md-4">
                        <div class="card h-100 model-card model-card--details"
                             data-model-id="<?= $modelId ?>"
                             data-model-name="<?= h($name) ?>"
                             role="button"
                             tabindex="0"
                             aria-label="Open notes and bookings for <?= h($name) ?>">
                            <?php if ($proxiedImage !== ''): ?>
                                <div class="model-image-wrapper">
                                    <img src="<?= htmlspecialchars($proxiedImage) ?>"
                                         alt=""
                                         class="model-image img-fluid">
                                </div>
                            <?php else: ?>
                                <div class="model-image-wrapper model-image-wrapper--placeholder">
                                    <div class="model-image-placeholder">
                                        No image
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title">
                                    <?= label_safe($name) ?>
                                </h5>
                                <p class="card-text small text-muted mb-2">
                                    <?php if ($manuName): ?>
                                        <span><strong>Manufacturer:</strong> <?= label_safe($manuName) ?></span><br>
                                    <?php endif; ?>
                                    <?php if ($catName): ?>
                                        <span><strong>Category:</strong> <?= label_safe($catName) ?></span><br>
                                    <?php endif; ?>
                                    <?php if ($assetCount !== null): ?>
                                        <span><strong>Requestable units:</strong> <?= $assetCount ?></span><br>
                                    <?php endif; ?>
                                    <span><strong><?= $windowActive ? 'Available for selected dates:' : 'Available now:' ?></strong> <?= $freeNow ?></span>
                                    <?php if (!empty($notes)): ?>
                                        <div class="mt-2 text-muted clamp-3">
                                            <?= label_safe($notes) ?>
                                        </div>
                                    <?php endif; ?>
                                </p>

                                <form method="post"
                                      action="basket_add.php"
                                      class="mt-auto add-to-basket-form">
                                    <input type="hidden" name="model_id" value="<?= $modelId ?>">
                                    <?php if ($windowActive): ?>
                                        <input type="hidden" name="start_datetime" value="<?= h($windowStartRaw) ?>">
                                        <input type="hidden" name="end_datetime" value="<?= h($windowEndRaw) ?>">
                                    <?php endif; ?>

                                    <?php if ($isRequestable && $freeNow > 0): ?>
                                        <div class="row g-2 align-items-center mb-2">
                                            <div class="col-6">
                                                <label class="form-label mb-0 small">Quantity</label>
                                                <input type="number"
                                                       name="quantity"
                                                       class="form-control form-control-sm"
                                                       value="1"
                                                       min="1"
                                                       max="<?= $maxQty ?>">
                                            </div>
                                        </div>

                                        <button type="submit"
                                                class="btn btn-sm btn-success w-100">
                                            Add to basket
                                        </button>
                                    <?php else: ?>
                                        <div class="alert alert-secondary small mb-0">
                                            <?php if (!$isRequestable): ?>
                                                No requestable units available.
                                            <?php else: ?>
                                                <?= $windowActive ? 'No units available for selected dates.' : 'No units available right now.' ?>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button"
                                                class="btn btn-sm btn-secondary w-100 mt-2"
                                                disabled>
                                            Add to basket
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination">
                        <?php
                        $baseQuery = [
                            'q'        => $searchRaw,
                            'category' => $categoryRaw,
                            'sort'     => $sortRaw,
                            'start_datetime' => $windowStartRaw,
                            'end_datetime' => $windowEndRaw,
                            'prefetch' => 1,
                        ];
                        ?>
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <?php $q = http_build_query(array_merge($baseQuery, ['page' => $p])); ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                <a class="page-link" href="catalogue.php?<?= $q ?>">
                                    <?= $p ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
</div>

<div id="basket-toast"
     class="basket-toast"
     role="status"
     aria-live="polite"
     aria-hidden="true"></div>

<div id="model-details-modal"
     class="catalogue-modal"
     role="dialog"
     aria-modal="true"
     aria-hidden="true"
     aria-labelledby="model-details-title"
     hidden>
    <div class="catalogue-modal__backdrop" data-model-modal-close></div>
    <div class="catalogue-modal__dialog" role="document">
        <div class="catalogue-modal__header">
            <h2 id="model-details-title" class="catalogue-modal__title">Model details</h2>
            <button type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-model-modal-close>
                Close
            </button>
        </div>
        <div class="catalogue-modal__body">
            <div id="model-details-feedback" class="d-none"></div>

            <section class="model-details-section filter-panel filter-panel--compact model-details-info-panel">
                <div class="filter-panel__header d-flex align-items-center gap-3">
                    <span class="filter-panel__dot"></span>
                    <div class="filter-panel__title">MORE INFORMATION</div>
                </div>
                <div id="model-details-notes" class="model-details-notes">
                    Select a model to load more information.
                </div>
            </section>

            <section class="model-details-section filter-panel filter-panel--compact model-calendar-panel">
                <div class="model-calendar-toolbar">
                    <div class="filter-panel__header d-flex align-items-center gap-3 mb-0">
                        <span class="filter-panel__dot"></span>
                        <div class="filter-panel__title">BOOKINGS CALENDAR</div>
                    </div>
                    <div class="d-flex align-items-center gap-2 model-calendar-controls">
                        <button type="button" class="btn btn-sm btn-light model-calendar-nav-btn" id="model-calendar-prev">Previous</button>
                        <div id="model-calendar-month" class="model-calendar-month"></div>
                        <button type="button" class="btn btn-sm btn-light model-calendar-nav-btn" id="model-calendar-next">Next</button>
                    </div>
                </div>
                <div id="model-calendar-grid" class="model-calendar-grid" aria-live="polite"></div>
            </section>

            <section class="model-details-section">
                <h3 class="model-details-section__title">Selected Month&apos;s Bookings</h3>
                <div class="table-responsive">
                    <table class="table table-sm align-middle model-bookings-table mb-0">
                        <thead>
                            <tr>
                                <th>Reservation</th>
                                <th>Status</th>
                                <th>Source</th>
                                <th>Qty</th>
                                <th>Start</th>
                                <th>End</th>
                            </tr>
                        </thead>
                        <tbody id="model-bookings-body"></tbody>
                    </table>
                </div>
                <div id="model-bookings-empty" class="small text-muted mt-2 d-none">
                    No bookings found for the selected month.
                </div>
            </section>
        </div>
    </div>
</div>

<!-- AJAX add-to-basket + update basket count text -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const loadingOverlay = document.getElementById('catalogue-loading');
    if (loadingOverlay) {
        loadingOverlay.classList.add('is-hidden');
        loadingOverlay.setAttribute('aria-busy', 'false');
    }
    const overdueAlert = document.getElementById('overdue-alert');
    const overdueList = document.getElementById('overdue-list');
    const overdueWarning = document.getElementById('overdue-warning');
    const catalogueContent = document.getElementById('catalogue-content');
    const viewBasketBtn = document.getElementById('view-basket-btn');
    const forms = document.querySelectorAll('.add-to-basket-form');
    const bookingInput = document.getElementById('booking_user_input');
    const bookingList  = document.getElementById('booking_user_suggestions');
    const bookingEmail = document.getElementById('booking_user_email');
    const bookingName  = document.getElementById('booking_user_name');
    const basketToast  = document.getElementById('basket-toast');
    const filterForm = document.getElementById('catalogue-filter-form');
    const categorySelect = filterForm ? filterForm.querySelector('select[name="category"]') : null;
    const sortSelect = filterForm ? filterForm.querySelector('select[name="sort"]') : null;
    const windowStartInput = document.getElementById('catalogue_start_datetime');
    const windowEndInput = document.getElementById('catalogue_end_datetime');
    const windowForm = document.getElementById('catalogue-window-form');
    const todayBtn = document.getElementById('catalogue-today-btn');
    let windowSubmitInFlight = false;
    let lastSubmittedWindow = (windowStartInput && windowEndInput)
        ? (windowStartInput.value.trim() + '|' + windowEndInput.value.trim())
        : '';
    const modelDetailCards = document.querySelectorAll('.model-card--details');
    const modelDetailsModal = document.getElementById('model-details-modal');
    const modelDetailsDialog = modelDetailsModal ? modelDetailsModal.querySelector('.catalogue-modal__dialog') : null;
    const modelDetailsTitle = document.getElementById('model-details-title');
    const modelDetailsFeedback = document.getElementById('model-details-feedback');
    const modelDetailsNotes = document.getElementById('model-details-notes');
    const modelCalendarGrid = document.getElementById('model-calendar-grid');
    const modelCalendarMonth = document.getElementById('model-calendar-month');
    const modelCalendarPrev = document.getElementById('model-calendar-prev');
    const modelCalendarNext = document.getElementById('model-calendar-next');
    const modelBookingsBody = document.getElementById('model-bookings-body');
    const modelBookingsEmpty = document.getElementById('model-bookings-empty');
    let bookingTimer   = null;
    let bookingQuery   = '';
    let basketToastTimer = null;
    let modelCalendarMonthCursor = new Date();
    let modelBookings = [];
    let modelDetailsRequestId = 0;
    let modelModalOpen = false;
    let modelModalOpenAnimation = null;
    let modalLastFocusedElement = null;

    function showLoadingOverlay() {
        if (!loadingOverlay) return;
        loadingOverlay.classList.remove('is-hidden');
        loadingOverlay.setAttribute('aria-busy', 'true');
    }

    function maybeSubmitWindow() {
        if (windowSubmitInFlight || !windowForm || !windowStartInput || !windowEndInput) return;
        const startVal = windowStartInput.value.trim();
        const endVal = windowEndInput.value.trim();
        if (startVal === '' && endVal === '') return;
        if (startVal === '' || endVal === '') return;
        const startMs = Date.parse(startVal);
        const endMs = Date.parse(endVal);
        if (Number.isNaN(startMs) || Number.isNaN(endMs) || endMs <= startMs) return;
        const windowKey = startVal + '|' + endVal;
        if (windowKey === lastSubmittedWindow) return;
        lastSubmittedWindow = windowKey;
        windowSubmitInFlight = true;
        showLoadingOverlay();
        windowForm.submit();
    }

    function toLocalDatetimeValue(date) {
        const pad = function (n) { return String(n).padStart(2, '0'); };
        return date.getFullYear()
            + '-' + pad(date.getMonth() + 1)
            + '-' + pad(date.getDate())
            + 'T' + pad(date.getHours())
            + ':' + pad(date.getMinutes());
    }

    function setDatetimeInputValue(input, value) {
        if (!input) return;
        if (input._flatpickr) {
            input._flatpickr.setDate(value, true, input._flatpickr.config.dateFormat);
            return;
        }
        input.value = value;
    }

    function setTodayWindow() {
        if (!windowStartInput || !windowEndInput) return;
        const now = new Date();
        const tomorrow = new Date(now);
        tomorrow.setDate(now.getDate() + 1);
        tomorrow.setHours(9, 0, 0, 0);
        setDatetimeInputValue(windowStartInput, toLocalDatetimeValue(now));
        setDatetimeInputValue(windowEndInput, toLocalDatetimeValue(tomorrow));
        showLoadingOverlay();
        maybeSubmitWindow();
    }

    function normalizeWindowEnd() {
        if (!windowStartInput || !windowEndInput) return;
        const startVal = windowStartInput.value.trim();
        const endVal = windowEndInput.value.trim();
        if (startVal === '' || endVal === '') return;
        const startMs = Date.parse(startVal);
        const endMs = Date.parse(endVal);
        if (Number.isNaN(startMs) || Number.isNaN(endMs)) return;
        if (endMs <= startMs) {
            const startDate = new Date(startMs);
            const nextDay = new Date(startDate);
            nextDay.setDate(startDate.getDate() + 1);
            nextDay.setHours(9, 0, 0, 0);
            setDatetimeInputValue(windowEndInput, toLocalDatetimeValue(nextDay));
        }
    }

    function applyOverdueBlock(items) {
        if (catalogueContent) {
            catalogueContent.classList.add('d-none');
        }
        if (overdueList) {
            overdueList.innerHTML = '';
            items.forEach(function (item) {
                const tag = item.tag || 'Unknown tag';
                const model = item.model || '';
                const due = item.due || '';
                let label = tag;
                if (model) {
                    label += ' (' + model + ')';
                }
                if (due) {
                    label += ' — due ' + due;
                }
                const li = document.createElement('li');
                li.textContent = label;
                overdueList.appendChild(li);
            });
        }
        if (overdueAlert) {
            overdueAlert.classList.remove('d-none');
        }
    }

    function showBasketToast(message) {
        if (!basketToast) return;
        basketToast.textContent = message;
        basketToast.setAttribute('aria-hidden', 'false');
        basketToast.classList.add('show');
        if (basketToastTimer) {
            clearTimeout(basketToastTimer);
        }
        basketToastTimer = setTimeout(function () {
            basketToast.classList.remove('show');
            basketToast.setAttribute('aria-hidden', 'true');
        }, 2200);
    }

    function setModelFeedback(message, tone) {
        if (!modelDetailsFeedback) return;

        const text = (message || '').trim();
        if (text === '') {
            modelDetailsFeedback.className = 'd-none';
            modelDetailsFeedback.textContent = '';
            return;
        }

        let classes = 'alert alert-info small mb-3';
        if (tone === 'danger') {
            classes = 'alert alert-danger small mb-3';
        } else if (tone === 'warning') {
            classes = 'alert alert-warning small mb-3';
        }

        modelDetailsFeedback.className = classes;
        modelDetailsFeedback.textContent = text;
    }

    function parseSqlDatetime(value) {
        const raw = String(value || '').trim();
        if (raw === '') {
            return null;
        }

        const normalized = raw.replace(' ', 'T');
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) {
            return null;
        }
        return date;
    }

    function bookingStatusLabel(status) {
        const normalized = String(status || '').toLowerCase();
        switch (normalized) {
            case 'pending':
                return 'Pending';
            case 'confirmed':
                return 'Confirmed';
            case 'completed':
                return 'Completed';
            case 'missed':
                return 'Missed';
            case 'cancelled':
                return 'Cancelled';
            default:
                return normalized === '' ? 'Unknown' : normalized.charAt(0).toUpperCase() + normalized.slice(1);
        }
    }

    function bookingStatusClass(status) {
        const normalized = String(status || '').toLowerCase();
        if (normalized === 'pending') return 'status-pending';
        if (normalized === 'confirmed') return 'status-confirmed';
        if (normalized === 'completed') return 'status-completed';
        if (normalized === 'missed') return 'status-missed';
        if (normalized === 'cancelled') return 'status-cancelled';
        return 'status-default';
    }

    function bookingSourceLabel(source) {
        const normalized = String(source || '').toLowerCase();
        if (normalized === 'asset') return 'Asset';
        if (normalized === 'model_and_asset') return 'Model + Asset';
        return 'Model';
    }

    function normalizeModelBooking(raw) {
        if (!raw || typeof raw !== 'object') {
            return null;
        }

        const start = parseSqlDatetime(raw.start_datetime || '');
        const end = parseSqlDatetime(raw.end_datetime || '');
        if (!start || !end) {
            return null;
        }

        return {
            id: parseInt(raw.id, 10) || 0,
            status: String(raw.status || '').toLowerCase(),
            source: String(raw.source || 'model').toLowerCase(),
            quantity: Math.max(1, parseInt(raw.quantity, 10) || 1),
            start: start,
            end: end,
            startDisplay: String(raw.start_display || ''),
            endDisplay: String(raw.end_display || '')
        };
    }

    function renderModelBookingsTable() {
        if (!modelBookingsBody || !modelBookingsEmpty) return;

        const cursor = new Date(modelCalendarMonthCursor.getFullYear(), modelCalendarMonthCursor.getMonth(), 1);
        const monthLabel = cursor.toLocaleString(undefined, { month: 'long', year: 'numeric' });
        const monthStart = new Date(cursor.getFullYear(), cursor.getMonth(), 1, 0, 0, 0, 0);
        const monthEnd = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1, 0, 0, 0, 0);

        modelBookingsBody.innerHTML = '';
        const monthBookings = modelBookings
            .filter(function (booking) {
                return booking.start < monthEnd && booking.end > monthStart;
            })
            .sort(function (a, b) {
                const timeDiff = a.start.getTime() - b.start.getTime();
                if (timeDiff !== 0) {
                    return timeDiff;
                }
                return a.id - b.id;
            });

        if (!monthBookings.length) {
            modelBookingsEmpty.textContent = 'No bookings found for ' + monthLabel + '.';
            modelBookingsEmpty.classList.remove('d-none');
            return;
        }

        modelBookingsEmpty.classList.add('d-none');

        monthBookings.forEach(function (booking) {
            const tr = document.createElement('tr');

            const idCell = document.createElement('td');
            idCell.textContent = booking.id > 0 ? '#' + booking.id : 'N/A';

            const statusCell = document.createElement('td');
            const statusBadge = document.createElement('span');
            statusBadge.className = 'model-booking-status ' + bookingStatusClass(booking.status);
            statusBadge.textContent = bookingStatusLabel(booking.status);
            statusCell.appendChild(statusBadge);

            const sourceCell = document.createElement('td');
            sourceCell.textContent = bookingSourceLabel(booking.source);

            const qtyCell = document.createElement('td');
            qtyCell.textContent = String(booking.quantity);

            const startCell = document.createElement('td');
            startCell.textContent = booking.startDisplay !== ''
                ? booking.startDisplay
                : booking.start.toLocaleString();

            const endCell = document.createElement('td');
            endCell.textContent = booking.endDisplay !== ''
                ? booking.endDisplay
                : booking.end.toLocaleString();

            tr.appendChild(idCell);
            tr.appendChild(statusCell);
            tr.appendChild(sourceCell);
            tr.appendChild(qtyCell);
            tr.appendChild(startCell);
            tr.appendChild(endCell);

            modelBookingsBody.appendChild(tr);
        });
    }

    function bookingOverlapsDay(booking, dayStart, dayEnd) {
        return booking.start < dayEnd && booking.end > dayStart;
    }

    function isCompactCalendarView() {
        return window.matchMedia && window.matchMedia('(max-width: 576px)').matches;
    }

    function renderModelCalendar() {
        if (!modelCalendarGrid || !modelCalendarMonth) return;

        const cursor = new Date(modelCalendarMonthCursor.getFullYear(), modelCalendarMonthCursor.getMonth(), 1);
        const year = cursor.getFullYear();
        const month = cursor.getMonth();
        const today = new Date();
        const firstDay = new Date(year, month, 1);
        const lastDay = new Date(year, month + 1, 0);
        const leadingBlanks = firstDay.getDay();
        const daysInMonth = lastDay.getDate();
        const compact = isCompactCalendarView();
        const weekdayLabels = compact
            ? ['S', 'M', 'T', 'W', 'T', 'F', 'S']
            : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        modelCalendarMonth.textContent = cursor.toLocaleString(undefined, { month: 'long', year: 'numeric' });
        modelCalendarGrid.innerHTML = '';

        weekdayLabels.forEach(function (label) {
            const cell = document.createElement('div');
            cell.className = 'model-calendar-weekday';
            cell.textContent = label;
            modelCalendarGrid.appendChild(cell);
        });

        for (let i = 0; i < leadingBlanks; i += 1) {
            const blank = document.createElement('div');
            blank.className = 'model-calendar-day model-calendar-day--blank';
            modelCalendarGrid.appendChild(blank);
        }

        for (let day = 1; day <= daysInMonth; day += 1) {
            const dayCell = document.createElement('div');
            dayCell.className = 'model-calendar-day';

            const dayNumber = document.createElement('div');
            dayNumber.className = 'model-calendar-day-number';
            dayNumber.textContent = String(day);
            dayCell.appendChild(dayNumber);

            const isToday = day === today.getDate()
                && month === today.getMonth()
                && year === today.getFullYear();
            if (isToday) {
                dayCell.classList.add('model-calendar-day--today');
            }

            const dayStart = new Date(year, month, day, 0, 0, 0, 0);
            const dayEnd = new Date(year, month, day + 1, 0, 0, 0, 0);
            const dayBookings = modelBookings
                .filter(function (booking) {
                    return bookingOverlapsDay(booking, dayStart, dayEnd);
                })
                .sort(function (a, b) {
                    return a.start.getTime() - b.start.getTime();
                });

            if (dayBookings.length > 0) {
                dayCell.classList.add('has-bookings');
                dayCell.dataset.bookingCount = String(dayBookings.length);
                dayCell.title = dayBookings.length + (dayBookings.length === 1 ? ' booking' : ' bookings');

                if (compact) {
                    const countDot = document.createElement('span');
                    countDot.className = 'model-calendar-compact-count';
                    countDot.textContent = String(dayBookings.length);
                    dayCell.appendChild(countDot);
                } else {
                    const countLabel = document.createElement('div');
                    countLabel.className = 'model-calendar-count';
                    countLabel.textContent = dayBookings.length + (dayBookings.length === 1 ? ' booking' : ' bookings');
                    dayCell.appendChild(countLabel);

                    const eventsList = document.createElement('div');
                    eventsList.className = 'model-calendar-events';
                    dayBookings.slice(0, 3).forEach(function (booking) {
                        const eventPill = document.createElement('span');
                        eventPill.className = 'model-calendar-event ' + bookingStatusClass(booking.status);
                        eventPill.textContent = '#' + booking.id;
                        eventsList.appendChild(eventPill);
                    });

                    if (dayBookings.length > 3) {
                        const more = document.createElement('span');
                        more.className = 'model-calendar-event status-default';
                        more.textContent = '+' + (dayBookings.length - 3) + ' more';
                        eventsList.appendChild(more);
                    }

                    dayCell.appendChild(eventsList);
                }
            }

            modelCalendarGrid.appendChild(dayCell);
        }
    }

    function cancelModelOpenAnimation() {
        if (!modelModalOpenAnimation) {
            return;
        }
        if (typeof modelModalOpenAnimation.cancel === 'function') {
            modelModalOpenAnimation.cancel();
        }
        modelModalOpenAnimation = null;
        if (modelDetailsDialog) {
            modelDetailsDialog.classList.remove('is-zooming');
        }
    }

    function animateModelDetailsOpenFromCard(triggerCard) {
        if (!modelDetailsDialog) {
            return;
        }

        cancelModelOpenAnimation();

        const reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || !triggerCard || !triggerCard.getBoundingClientRect) {
            return;
        }

        const cardRect = triggerCard.getBoundingClientRect();
        const dialogRect = modelDetailsDialog.getBoundingClientRect();
        if (cardRect.width <= 0 || cardRect.height <= 0 || dialogRect.width <= 0 || dialogRect.height <= 0) {
            return;
        }

        const fromScaleX = Math.max(0.2, Math.min(1, cardRect.width / dialogRect.width));
        const fromScaleY = Math.max(0.2, Math.min(1, cardRect.height / dialogRect.height));
        const cardCenterX = cardRect.left + (cardRect.width / 2);
        const cardCenterY = cardRect.top + (cardRect.height / 2);
        const dialogCenterX = dialogRect.left + (dialogRect.width / 2);
        const dialogCenterY = dialogRect.top + (dialogRect.height / 2);
        const shiftX = cardCenterX - dialogCenterX;
        const shiftY = cardCenterY - dialogCenterY;

        modelDetailsDialog.classList.add('is-zooming');
        modelModalOpenAnimation = modelDetailsDialog.animate([
            {
                transform: 'translate(' + shiftX + 'px, ' + shiftY + 'px) scale(' + fromScaleX + ', ' + fromScaleY + ')',
                opacity: 0.45
            },
            {
                transform: 'translate(0px, 0px) scale(1, 1)',
                opacity: 1
            }
        ], {
            duration: 300,
            easing: 'cubic-bezier(0.16, 1, 0.3, 1)',
            fill: 'both'
        });

        modelModalOpenAnimation.onfinish = function () {
            if (modelDetailsDialog) {
                modelDetailsDialog.classList.remove('is-zooming');
            }
            modelModalOpenAnimation = null;
        };
        modelModalOpenAnimation.oncancel = function () {
            if (modelDetailsDialog) {
                modelDetailsDialog.classList.remove('is-zooming');
            }
            modelModalOpenAnimation = null;
        };
    }

    function closeModelDetailsModal() {
        if (!modelDetailsModal || !modelModalOpen) return;

        modelModalOpen = false;
        modelDetailsRequestId += 1;
        cancelModelOpenAnimation();
        modelDetailsModal.classList.remove('is-open');
        modelDetailsModal.hidden = true;
        modelDetailsModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('catalogue-modal-open');
        setModelFeedback('', 'info');

        if (modalLastFocusedElement && typeof modalLastFocusedElement.focus === 'function') {
            modalLastFocusedElement.focus();
        }
        modalLastFocusedElement = null;
    }

    function openModelDetailsModal(modelId, modelName, triggerCard) {
        if (!modelDetailsModal || modelId <= 0) return;

        modalLastFocusedElement = document.activeElement;
        modelModalOpen = true;
        modelDetailsModal.classList.remove('is-open');
        modelDetailsModal.hidden = false;
        modelDetailsModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('catalogue-modal-open');
        window.requestAnimationFrame(function () {
            if (!modelModalOpen || !modelDetailsModal) {
                return;
            }
            modelDetailsModal.classList.add('is-open');
            animateModelDetailsOpenFromCard(triggerCard);
        });

        if (modelDetailsTitle) {
            modelDetailsTitle.textContent = (modelName || 'Model') + ' details';
        }
        if (modelDetailsNotes) {
            modelDetailsNotes.textContent = 'Loading more information...';
        }

        modelBookings = [];
        modelCalendarMonthCursor = new Date();
        modelCalendarMonthCursor.setDate(1);
        renderModelBookingsTable();
        renderModelCalendar();
        setModelFeedback('Loading bookings...', 'info');

        const requestId = ++modelDetailsRequestId;
        fetch('catalogue.php?ajax=model_details&model_id=' + encodeURIComponent(String(modelId)), {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
            .then(function (response) {
                if (response.ok) {
                    return response.json();
                }
                return response.json()
                    .catch(function () { return null; })
                    .then(function (payload) {
                        const message = payload && payload.error
                            ? payload.error
                            : 'Unable to load model details.';
                        throw new Error(message);
                    });
            })
            .then(function (data) {
                if (requestId !== modelDetailsRequestId || !modelModalOpen) {
                    return;
                }

                const notes = data && typeof data.notes === 'string'
                    ? data.notes.trim()
                    : '';
                if (modelDetailsNotes) {
                    modelDetailsNotes.textContent = notes !== '' ? notes : 'No additional information available for this model.';
                }

                const warnings = data && Array.isArray(data.warnings)
                    ? data.warnings.filter(function (warning) {
                        return String(warning || '').trim() !== '';
                    })
                    : [];

                const rawBookings = data && Array.isArray(data.bookings) ? data.bookings : [];
                modelBookings = rawBookings
                    .map(normalizeModelBooking)
                    .filter(function (booking) { return booking !== null; });

                renderModelBookingsTable();
                renderModelCalendar();

                if (warnings.length > 0) {
                    setModelFeedback(warnings.join(' '), 'warning');
                } else if (modelBookings.length === 0) {
                    setModelFeedback('No bookings found for this model.', 'info');
                } else {
                    setModelFeedback('', 'info');
                }
            })
            .catch(function (error) {
                if (requestId !== modelDetailsRequestId || !modelModalOpen) {
                    return;
                }

                if (modelDetailsNotes) {
                    modelDetailsNotes.textContent = 'Unable to load more information for this model.';
                }
                modelBookings = [];
                renderModelBookingsTable();
                renderModelCalendar();
                setModelFeedback(error && error.message ? error.message : 'Unable to load model details.', 'danger');
            });
    }

    function shouldIgnoreModelCardOpen(target) {
        if (!target || !target.closest) {
            return false;
        }

        return Boolean(target.closest('.add-to-basket-form, button, input, select, textarea, a, label'));
    }

    if (filterForm) {
        filterForm.addEventListener('submit', function () {
            showLoadingOverlay();
        });
    }

    if (categorySelect) {
        categorySelect.addEventListener('change', function () {
            showLoadingOverlay();
            filterForm.submit();
        });
    }

    if (sortSelect) {
        sortSelect.addEventListener('change', function () {
            showLoadingOverlay();
            filterForm.submit();
        });
    }

    const searchInput = filterForm ? filterForm.querySelector('input[name="q"]') : null;
    if (searchInput) {
        searchInput.addEventListener('blur', function () {
            if (!filterForm) return;
            const value = searchInput.value.trim();
            if (value === '' && searchInput.defaultValue.trim() === '') {
                return;
            }
            showLoadingOverlay();
            filterForm.submit();
        });
    }

    if (windowStartInput && windowEndInput) {
        windowStartInput.addEventListener('change', function () {
            normalizeWindowEnd();
            if (!windowStartInput._flatpickr || !windowEndInput._flatpickr) {
                maybeSubmitWindow();
            }
        });
        windowEndInput.addEventListener('change', function () {
            normalizeWindowEnd();
            if (!windowStartInput._flatpickr || !windowEndInput._flatpickr) {
                maybeSubmitWindow();
            }
        });
    }
    if (windowForm) {
        windowForm.addEventListener('submit', () => {
            normalizeWindowEnd();
            if (windowStartInput && windowEndInput) {
                lastSubmittedWindow = windowStartInput.value.trim() + '|' + windowEndInput.value.trim();
            }
            windowSubmitInFlight = true;
            showLoadingOverlay();
        });
    }
    if (todayBtn) {
        todayBtn.addEventListener('click', setTodayWindow);
    }
    document.addEventListener('click', function (event) {
        if (!windowStartInput || !windowEndInput) return;
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;
        const confirmButton = target.closest('.flatpickr-confirm');
        if (!confirmButton) return;
        const calendar = confirmButton.closest('.flatpickr-calendar');
        if (!calendar) return;

        const startCalendar = windowStartInput._flatpickr
            ? windowStartInput._flatpickr.calendarContainer
            : null;
        const endCalendar = windowEndInput._flatpickr
            ? windowEndInput._flatpickr.calendarContainer
            : null;
        if (calendar !== startCalendar && calendar !== endCalendar) return;

        normalizeWindowEnd();
        maybeSubmitWindow();
    });

    const overdueEnabled = document.body.dataset.catalogueOverdue === '1';
    if (overdueEnabled) {
        fetch('catalogue.php?ajax=overdue_check', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (data) {
                if (!data) return;
                if (data.error && overdueWarning) {
                    overdueWarning.textContent = data.error;
                    overdueWarning.classList.remove('d-none');
                }
                if (data.blocked && Array.isArray(data.assets)) {
                    applyOverdueBlock(data.assets);
                }
            })
            .catch(function () {
                // Ignore overdue check failures; catalogue remains accessible.
            });
    }

    forms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            const formData = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(function (response) {
                    const ct = response.headers.get('Content-Type') || '';
                    if (ct.indexOf('application/json') !== -1) {
                        return response.json();
                    }
                    return null;
                })
                .then(function (data) {
                    if (!viewBasketBtn) return;

                    if (data && typeof data.basket_count !== 'undefined') {
                        const count = parseInt(data.basket_count, 10) || 0;
                        if (count > 0) {
                            viewBasketBtn.textContent = 'View basket (' + count + ')';
                        } else {
                            viewBasketBtn.textContent = 'View basket';
                        }
                        showBasketToast('Added to basket');
                    }
                })
                .catch(function () {
                // Fallback: if AJAX fails for any reason, do normal form submit
                form.submit();
            });
        });
    });

    function hideBookingSuggestions() {
        if (!bookingList) return;
        bookingList.style.display = 'none';
        bookingList.innerHTML = '';
    }

    function renderBookingSuggestions(items) {
        if (!bookingList) return;
        bookingList.innerHTML = '';
        if (!items || !items.length) {
            hideBookingSuggestions();
            return;
        }
        items.forEach(function (item) {
            const email = item.email || '';
            const name = item.name || '';
            const label = (name && email && name !== email) ? (name + ' (' + email + ')') : (name || email);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action';
            btn.textContent = label;
            btn.addEventListener('click', function () {
                bookingInput.value = label;
                bookingEmail.value = email;
                bookingName.value  = name || email;
                hideBookingSuggestions();
            });
            bookingList.appendChild(btn);
        });
        bookingList.style.display = 'block';
    }

    if (bookingInput && bookingList) {
        bookingInput.addEventListener('input', function () {
            const q = bookingInput.value.trim();
            if (q.length < 2) {
                hideBookingSuggestions();
                return;
            }
            if (bookingTimer) clearTimeout(bookingTimer);
            bookingTimer = setTimeout(function () {
                bookingQuery = q;
                fetch('catalogue.php?ajax=user_search&q=' + encodeURIComponent(q), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (res) { return res.ok ? res.json() : null; })
                    .then(function (data) {
                        if (bookingQuery !== q) return;
                        renderBookingSuggestions(data && data.results ? data.results : []);
                    })
                    .catch(function () {
                        hideBookingSuggestions();
                    });
            }, 250);
        });

        bookingInput.addEventListener('blur', function () {
            setTimeout(hideBookingSuggestions, 150);
        });
    }

    if (modelCalendarPrev) {
        modelCalendarPrev.addEventListener('click', function () {
            modelCalendarMonthCursor = new Date(
                modelCalendarMonthCursor.getFullYear(),
                modelCalendarMonthCursor.getMonth() - 1,
                1
            );
            renderModelCalendar();
            renderModelBookingsTable();
        });
    }

    if (modelCalendarNext) {
        modelCalendarNext.addEventListener('click', function () {
            modelCalendarMonthCursor = new Date(
                modelCalendarMonthCursor.getFullYear(),
                modelCalendarMonthCursor.getMonth() + 1,
                1
            );
            renderModelCalendar();
            renderModelBookingsTable();
        });
    }

    if (modelDetailsModal) {
        modelDetailsModal.addEventListener('click', function (event) {
            const target = event.target;
            if (target && target.closest && target.closest('[data-model-modal-close]')) {
                closeModelDetailsModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modelModalOpen) {
            event.preventDefault();
            closeModelDetailsModal();
        }
    });

    modelDetailCards.forEach(function (card) {
        card.addEventListener('click', function (event) {
            if (shouldIgnoreModelCardOpen(event.target)) {
                return;
            }
            const modelId = parseInt(card.dataset.modelId || '0', 10);
            const modelName = card.dataset.modelName || 'Model';
            openModelDetailsModal(modelId, modelName, card);
        });

        card.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') {
                return;
            }
            if (shouldIgnoreModelCardOpen(event.target)) {
                return;
            }
            event.preventDefault();
            const modelId = parseInt(card.dataset.modelId || '0', 10);
            const modelName = card.dataset.modelName || 'Model';
            openModelDetailsModal(modelId, modelName, card);
        });
    });
});

function clearBookingUser() {
    const email = document.getElementById('booking_user_email');
    const name  = document.getElementById('booking_user_name');
    const input = document.getElementById('booking_user_input');
    if (email) email.value = '';
    if (name) name.value = '';
    if (input) input.value = '';
}

function revertToLoggedIn(e) {
    if (e) e.preventDefault();
    const email = document.getElementById('booking_user_email');
    const name  = document.getElementById('booking_user_name');
    const input = document.getElementById('booking_user_input');
    const form  = document.getElementById('booking_user_form');
    if (email) email.value = '';
    if (name) name.value = '';
    if (input) input.value = '';
    // Submit form via hidden revert button to mirror normal submit
    const revertBtn = document.querySelector('button[name="booking_user_revert"]');
    if (revertBtn) {
        revertBtn.click();
    } else if (form) {
        form.submit();
    }
}
</script>
<?php layout_footer(); ?>
</body>
</html>
