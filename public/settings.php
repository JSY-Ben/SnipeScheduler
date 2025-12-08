<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/footer.php';
require_once SRC_PATH . '/config_writer.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/email.php';

$active  = basename($_SERVER['PHP_SELF']);
$isStaff = !empty($currentUser['is_admin']);

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$configPath  = CONFIG_PATH . '/config.php';
$examplePath = CONFIG_PATH . '/config.example.php';

$messages = [];
$errors   = [];
$isAjax   = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    || (isset($_POST['ajax']) && $_POST['ajax'] == '1');

try {
    $config = load_config();
} catch (Throwable $e) {
    // Fall back to example config if we can't load a real one yet.
    $config = is_file($examplePath) ? require $examplePath : [];
    $errors[] = 'Config file missing – showing defaults from config.example.php.';
}
$loadedConfig = $config;

$categoryOptions    = [];
$categoryFetchError = '';
try {
    $categoryOptions = get_model_categories();
} catch (Throwable $e) {
    $categoryOptions    = [];
    $categoryFetchError = $e->getMessage();
}

$definedValues = [
    'SNIPEIT_API_PAGE_LIMIT'    => defined('SNIPEIT_API_PAGE_LIMIT') ? SNIPEIT_API_PAGE_LIMIT : 12,
    'CATALOGUE_ITEMS_PER_PAGE'  => defined('CATALOGUE_ITEMS_PER_PAGE') ? CATALOGUE_ITEMS_PER_PAGE : 12,
    'SNIPEIT_MAX_MODELS_FETCH'  => defined('SNIPEIT_MAX_MODELS_FETCH') ? SNIPEIT_MAX_MODELS_FETCH : 1000,
];

function reserveit_test_db_connection(array $db): string
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'] ?? 'localhost',
        (int)($db['port'] ?? 3306),
        $db['dbname'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    $row = $pdo->query('SELECT 1')->fetchColumn();
    if ((int)$row !== 1) {
        throw new Exception('Connected but validation query failed.');
    }

    return 'Database connection succeeded.';
}

function reserveit_test_snipe_api(array $snipe): string
{
    if (!function_exists('curl_init')) {
        throw new Exception('PHP cURL extension is not installed.');
    }

    $base   = rtrim($snipe['base_url'] ?? '', '/');
    $token  = $snipe['api_token'] ?? '';
    $verify = !empty($snipe['verify_ssl']);

    if ($base === '' || $token === '') {
        throw new Exception('Base URL or API token is missing.');
    }

    $url = $base . '/api/v1/models?limit=1';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL error: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if ($code >= 400) {
        $msg = $decoded['message'] ?? $raw;
        throw new Exception('HTTP ' . $code . ': ' . $msg);
    }

    return 'Snipe-IT API reachable (HTTP ' . $code . ').';
}

function reserveit_test_ldap(array $ldap): string
{
    if (!function_exists('ldap_connect')) {
        throw new Exception('PHP LDAP extension is not installed.');
    }

    $host    = $ldap['host'] ?? '';
    $baseDn  = $ldap['base_dn'] ?? '';
    $bindDn  = $ldap['bind_dn'] ?? '';
    $bindPwd = $ldap['bind_password'] ?? '';
    $ignore  = !empty($ldap['ignore_cert']);

    if ($host === '') {
        throw new Exception('LDAP host is missing.');
    }

    if ($ignore && function_exists('ldap_set_option')) {
        @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_ALLOW);
    }

    $conn = @ldap_connect($host);
    if (!$conn) {
        throw new Exception('Could not connect to LDAP host.');
    }
    @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
        @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
    }

    $bindOk = $bindDn !== ''
        ? @ldap_bind($conn, $bindDn, $bindPwd)
        : @ldap_bind($conn);

    if ($bindOk === false) {
        $err = function_exists('ldap_error') ? @ldap_error($conn) : 'Unknown LDAP error';
        throw new Exception('Bind failed: ' . $err);
    }

    if ($baseDn !== '') {
        $search = @ldap_search($conn, $baseDn, '(objectClass=*)', ['dn'], 0, 1, 3);
        if ($search === false) {
            $err = function_exists('ldap_error') ? @ldap_error($conn) : 'Unknown LDAP error';
            throw new Exception('Search failed: ' . $err);
        }
    }

    @ldap_unbind($conn);
    return 'LDAP connection and bind succeeded.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    $post = static function (string $key, $fallback = '') {
        return trim($_POST[$key] ?? $fallback);
    };

    $pageLimit   = $definedValues['SNIPEIT_API_PAGE_LIMIT'];
    $cataloguePP = max(1, (int)$post('catalogue_items_per_page', $definedValues['CATALOGUE_ITEMS_PER_PAGE']));
    $maxModels   = $definedValues['SNIPEIT_MAX_MODELS_FETCH'];

    $useRawSecrets = $action !== 'save';

    $db = $config['db_booking'] ?? [];
    $db['host']     = $post('db_host', $db['host'] ?? 'localhost');
    $db['port']     = (int)$post('db_port', $db['port'] ?? 3306);
    $db['dbname']   = $post('db_name', $db['dbname'] ?? '');
    $db['username'] = $post('db_username', $db['username'] ?? '');
    $dbPassInput    = $_POST['db_password'] ?? '';
    if ($useRawSecrets) {
        $db['password'] = $dbPassInput;
    } else {
        $db['password'] = $dbPassInput === '' ? ($loadedConfig['db_booking']['password'] ?? '') : $dbPassInput;
    }
    $db['charset']  = $post('db_charset', $db['charset'] ?? 'utf8mb4');

    $ldap = $config['ldap'] ?? [];
    $ldap['host']          = $post('ldap_host', $ldap['host'] ?? 'ldaps://');
    $ldap['base_dn']       = $post('ldap_base_dn', $ldap['base_dn'] ?? '');
    $ldap['bind_dn']       = $post('ldap_bind_dn', $ldap['bind_dn'] ?? '');
    $ldapPassInput         = $_POST['ldap_bind_password'] ?? '';
    if ($useRawSecrets) {
        $ldap['bind_password'] = $ldapPassInput;
    } else {
        $ldap['bind_password'] = $ldapPassInput === '' ? ($loadedConfig['ldap']['bind_password'] ?? '') : $ldapPassInput;
    }
    $ldap['ignore_cert']   = isset($_POST['ldap_ignore_cert']);

    $snipe = $config['snipeit'] ?? [];
    $snipe['base_url']  = $post('snipe_base_url', $snipe['base_url'] ?? '');
    $snipeTokenInput    = $_POST['snipe_api_token'] ?? '';
    if ($useRawSecrets) {
        $snipe['api_token'] = $snipeTokenInput;
    } else {
        $snipe['api_token'] = $snipeTokenInput === '' ? ($loadedConfig['snipeit']['api_token'] ?? '') : $snipeTokenInput;
    }
    $snipe['verify_ssl'] = isset($_POST['snipe_verify_ssl']);

    $auth = $config['auth'] ?? [];
    $staffCnsRaw   = $post('staff_group_cn', '');
    $staffGroupCns = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $staffCnsRaw))));
    $auth['staff_group_cn'] = $staffGroupCns;

    $app = $config['app'] ?? [];
    $app['timezone']              = $post('app_timezone', $app['timezone'] ?? 'Europe/Jersey');
    $app['debug']                 = isset($_POST['app_debug']);
    $app['logo_url']              = $post('app_logo_url', $app['logo_url'] ?? '');
    $app['primary_color']         = $post('app_primary_color', $app['primary_color'] ?? '#660000');
    $app['missed_cutoff_minutes'] = max(0, (int)$post('app_missed_cutoff', $app['missed_cutoff_minutes'] ?? 60));
    $app['api_cache_ttl_seconds'] = max(0, (int)$post('app_api_cache_ttl', $app['api_cache_ttl_seconds'] ?? 60));

    $catalogue = $config['catalogue'] ?? [];
    $allowedRaw = $_POST['catalogue_allowed_categories'] ?? [];
    $allowedCategories = [];
    if (is_array($allowedRaw)) {
        foreach ($allowedRaw as $cid) {
            if (ctype_digit((string)$cid)) {
                $allowedCategories[] = (int)$cid;
            }
        }
    }
    $catalogue['allowed_categories'] = $allowedCategories;

    $smtp = $config['smtp'] ?? [];
    $smtp['host']       = $post('smtp_host', $smtp['host'] ?? '');
    $smtp['port']       = (int)$post('smtp_port', $smtp['port'] ?? 587);
    $smtp['username']   = $post('smtp_username', $smtp['username'] ?? '');
    $smtpPassInput      = $_POST['smtp_password'] ?? '';
    $smtp['password']   = $smtpPassInput === '' ? ($config['smtp']['password'] ?? '') : $smtpPassInput;
    $smtp['encryption'] = $post('smtp_encryption', $smtp['encryption'] ?? 'tls');
    $smtp['auth_method'] = $post('smtp_auth_method', $smtp['auth_method'] ?? 'login');
    $smtp['from_email'] = $post('smtp_from_email', $smtp['from_email'] ?? '');
    $smtp['from_name']  = $post('smtp_from_name', $smtp['from_name'] ?? 'ReserveIT');

    $newConfig = $config;
    $newConfig['db_booking'] = $db;
    $newConfig['ldap']       = $ldap;
    $newConfig['snipeit']    = $snipe;
    $newConfig['auth']       = $auth;
    $newConfig['app']        = $app;
    $newConfig['catalogue']  = $catalogue;
    $newConfig['smtp']       = $smtp;

    // Keep posted values in the form
    $config        = $newConfig;
    $definedValues = [
        'SNIPEIT_API_PAGE_LIMIT'   => $pageLimit,
        'CATALOGUE_ITEMS_PER_PAGE' => $cataloguePP,
        'SNIPEIT_MAX_MODELS_FETCH' => $maxModels,
    ];

    if ($action === 'test_db') {
        try {
            $messages[] = reserveit_test_db_connection($db);
        } catch (Throwable $e) {
            $errors[] = 'Database test failed: ' . $e->getMessage();
        }
    } elseif ($action === 'test_api') {
        try {
            $messages[] = reserveit_test_snipe_api($snipe);
        } catch (Throwable $e) {
            $errors[] = 'Snipe-IT API test failed: ' . $e->getMessage();
        }
    } elseif ($action === 'test_ldap') {
        try {
            $messages[] = reserveit_test_ldap($ldap);
        } catch (Throwable $e) {
            $errors[] = 'LDAP test failed: ' . $e->getMessage();
        }
    } elseif ($action === 'test_smtp') {
        try {
            if (empty($smtp['host']) || empty($smtp['from_email'])) {
                throw new Exception('SMTP host and from email are required.');
            }
            $targetEmail = $smtp['from_email'];
            $targetName  = $smtp['from_name'] ?? $targetEmail;
            $sent = reserveit_send_notification(
                $targetEmail,
                $targetName,
                'ReserveIT SMTP test',
                ['This is a test email from ReserveIT SMTP settings.'],
                ['smtp' => $smtp] + $config
            );
            if ($sent) {
                $messages[] = 'SMTP test email sent to ' . $targetEmail . '.';
            } else {
                throw new Exception('SMTP send failed (see logs).');
            }
        } catch (Throwable $e) {
            $errors[] = 'SMTP test failed: ' . $e->getMessage();
        }
    } else {
        $content = reserveit_build_config_file($newConfig, [
            'SNIPEIT_API_PAGE_LIMIT'   => $pageLimit,
            'CATALOGUE_ITEMS_PER_PAGE' => $cataloguePP,
            'SNIPEIT_MAX_MODELS_FETCH' => $maxModels,
        ]);

        if (!is_dir(CONFIG_PATH)) {
            @mkdir(CONFIG_PATH, 0755, true);
        }

        if (@file_put_contents($configPath, $content, LOCK_EX) === false) {
            $errors[] = 'Could not write config.php. Check file permissions on the config/ directory.';
        } else {
            $messages[] = 'Config saved successfully.';
        }
    }

    if ($isAjax && $action !== 'save') {
        header('Content-Type: application/json');
        echo json_encode([
            'ok'       => empty($errors),
            'messages' => $messages,
            'errors'   => $errors,
        ]);
        exit;
    }
}

// Convenience helpers for output
$cfg = static function (array $path, $fallback = '') use ($config) {
    $ref = $config;
    foreach ($path as $key) {
        if (!is_array($ref) || !array_key_exists($key, $ref)) {
            return $fallback;
        }
        $ref = $ref[$key];
    }
    return $ref === null ? $fallback : $ref;
};

function reserveit_textarea_value(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$staffGroupList = $cfg(['auth', 'staff_group_cn'], []);
if (!is_array($staffGroupList)) {
    $staffGroupList = [];
}
$staffGroupText = implode("\n", $staffGroupList);

$allowedCategoryIds = $cfg(['catalogue', 'allowed_categories'], []);
if (!is_array($allowedCategoryIds)) {
    $allowedCategoryIds = [];
}
$allowedCategoryIds = array_map('intval', $allowedCategoryIds);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings – ReserveIT</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= reserveit_theme_styles($config) ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= reserveit_logo_tag($config) ?>
        <div class="page-header">
            <h1>Settings</h1>
            <div class="page-subtitle">
                Staff-only configuration for database, LDAP, Snipe-IT, and app options. Leave secret fields blank to keep existing values.
            </div>
        </div>

        <?= reserveit_render_nav($active, $isStaff) ?>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if ($messages): ?>
            <div class="alert alert-success">
                <?= implode('<br>', array_map('h', $messages)) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?= implode('<br>', array_map('h', $errors)) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="settings.php" class="row g-3" id="settings-form">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Database</h5>
                        <p class="text-muted small mb-3">Connection for the booking app tables (not the Snipe-IT DB). Password is optional to update; leave blank to keep the current value.</p>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Host</label>
                                <input type="text" name="db_host" class="form-control" value="<?= h($cfg(['db_booking', 'host'], 'localhost')) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Port</label>
                                <input type="number" name="db_port" class="form-control" value="<?= (int)$cfg(['db_booking', 'port'], 3306) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Database name</label>
                                <input type="text" name="db_name" class="form-control" value="<?= h($cfg(['db_booking', 'dbname'], '')) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Username</label>
                                <input type="text" name="db_username" class="form-control" value="<?= h($cfg(['db_booking', 'username'], '')) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Password</label>
                                <input type="password" name="db_password" class="form-control" placeholder="Leave blank to keep">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Charset</label>
                                <input type="text" name="db_charset" class="form-control" value="<?= h($cfg(['db_booking', 'charset'], 'utf8mb4')) ?>">
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="db-test-result"></div>
                            <button type="button" name="action" value="test_db" class="btn btn-outline-primary btn-sm" data-test-action="test_db" data-target="db-test-result">Test database connection</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Snipe-IT API</h5>
                        <p class="text-muted small mb-3">Connection details for the Snipe-IT instance.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Base URL</label>
                                <input type="text" name="snipe_base_url" class="form-control" value="<?= h($cfg(['snipeit', 'base_url'], '')) ?>">
                                <div class="form-text">Example: https://snipeit.example.com</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">API token</label>
                                <input type="password" name="snipe_api_token" class="form-control" placeholder="Leave blank to keep">
                                <div class="form-text">Token stays unchanged if left blank.</div>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="snipe_verify_ssl" id="snipe_verify_ssl" <?= $cfg(['snipeit', 'verify_ssl'], false) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="snipe_verify_ssl">Verify SSL certificate</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="api-test-result"></div>
                            <button type="button" name="action" value="test_api" class="btn btn-outline-primary btn-sm" data-test-action="test_api" data-target="api-test-result">Test Snipe-IT API</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">LDAP / Active Directory</h5>
                        <p class="text-muted small mb-3">Settings used to authenticate and look up users.</p>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">LDAP host (e.g. ldaps://host)</label>
                                <input type="text" name="ldap_host" class="form-control" value="<?= h($cfg(['ldap', 'host'], 'ldaps://')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Base DN</label>
                                <input type="text" name="ldap_base_dn" class="form-control" value="<?= h($cfg(['ldap', 'base_dn'], '')) ?>" placeholder="dc=company,dc=local">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bind DN (service account)</label>
                                <input type="text" name="ldap_bind_dn" class="form-control" value="<?= h($cfg(['ldap', 'bind_dn'], '')) ?>" placeholder="CN=binduser,CN=Users,DC=company,DC=local">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bind password</label>
                                <input type="password" name="ldap_bind_password" class="form-control" placeholder="Leave blank to keep">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="ldap_ignore_cert" id="ldap_ignore_cert" <?= $cfg(['ldap', 'ignore_cert'], false) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="ldap_ignore_cert">Ignore SSL certificate errors</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="ldap-test-result"></div>
                            <button type="button" name="action" value="test_ldap" class="btn btn-outline-primary btn-sm" data-test-action="test_ldap" data-target="ldap-test-result">Test LDAP connection</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">LDAP/AD Admin Group(s)</h5>
                        <p class="text-muted small mb-3">Comma or newline separated LDAP/AD Group names that contain users that you wish to be Administrators/Staff on this app.</p>
                        <textarea name="staff_group_cn" rows="3" class="form-control" placeholder="ICT Staff&#10;Another Group"><?= reserveit_textarea_value($staffGroupText) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">SMTP (email)</h5>
                        <p class="text-muted small mb-3">Used for notification emails. Leave password blank to keep existing.</p>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">SMTP host</label>
                                <input type="text" name="smtp_host" class="form-control" value="<?= h($cfg(['smtp', 'host'], '')) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Port</label>
                                <input type="number" name="smtp_port" class="form-control" value="<?= (int)$cfg(['smtp', 'port'], 587) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Encryption</label>
                                <select name="smtp_encryption" class="form-select">
                                    <?php
                                    $enc = strtolower($cfg(['smtp', 'encryption'], 'tls'));
                                    foreach (['none', 'ssl', 'tls'] as $opt) {
                                        $sel = $enc === $opt ? 'selected' : '';
                                        echo "<option value=\"{$opt}\" {$sel}>" . strtoupper($opt) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Auth method</label>
                                <select name="smtp_auth_method" class="form-select">
                                    <?php
                                    $auth = strtolower($cfg(['smtp', 'auth_method'], 'login'));
                                    foreach (['login', 'plain', 'none'] as $opt) {
                                        $sel = $auth === $opt ? 'selected' : '';
                                        echo "<option value=\"{$opt}\" {$sel}>" . strtoupper($opt) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="smtp_username" class="form-control" value="<?= h($cfg(['smtp', 'username'], '')) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="smtp_password" class="form-control" placeholder="Leave blank to keep">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">From email</label>
                                <input type="email" name="smtp_from_email" class="form-control" value="<?= h($cfg(['smtp', 'from_email'], '')) ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">From name</label>
                                <input type="text" name="smtp_from_name" class="form-control" value="<?= h($cfg(['smtp', 'from_name'], 'ReserveIT')) ?>">
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="smtp-test-result"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-test-action="test_smtp" data-target="smtp-test-result">Test SMTP</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Catalogue display</h5>
                        <p class="text-muted small mb-3">Control how many items appear per page in the catalogue and how long to cache API responses.</p>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Items per page</label>
                                <input type="number" name="catalogue_items_per_page" min="1" class="form-control" value="<?= (int)$definedValues['CATALOGUE_ITEMS_PER_PAGE'] ?>">
                                <div class="form-text">Adjust to show more or fewer items on each catalogue page.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">API cache TTL (seconds)</label>
                                <input type="number" name="app_api_cache_ttl" class="form-control" min="0" value="<?= (int)$cfg(['app', 'api_cache_ttl_seconds'], 60) ?>">
                                <div class="form-text">Cache Snipe-IT GET responses. Set 0 to disable.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Catalogue categories</h5>
                        <p class="text-muted small mb-3">Choose which Snipe-IT categories appear in the catalogue filter. Unchecked categories are hidden entirely from the catalogue. Leave everything unticked to show all categories.</p>
                        <?php if ($categoryFetchError): ?>
                            <div class="alert alert-warning small mb-3">
                                Could not load categories from Snipe-IT: <?= h($categoryFetchError) ?>
                            </div>
                        <?php elseif (empty($categoryOptions)): ?>
                            <div class="text-muted small">No categories available.</div>
                        <?php else: ?>
                            <div class="row g-2">
                                <?php foreach ($categoryOptions as $cat): ?>
                                    <?php
                                    $cid = (int)($cat['id'] ?? 0);
                                    $cname = $cat['name'] ?? '';
                                    if ($cid <= 0) {
                                        continue;
                                    }
                                    ?>
                                    <div class="col-md-4 col-sm-6">
                                        <div class="form-check">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   name="catalogue_allowed_categories[]"
                                                   id="cat_filter_<?= $cid ?>"
                                                   value="<?= $cid ?>"
                                                <?= in_array($cid, $allowedCategoryIds, true) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="cat_filter_<?= $cid ?>">
                                                <?= h($cname) ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text mt-2">Tip: leave all unchecked to allow every category to show in the dropdown.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">App preferences</h5>
                        <p class="text-muted small mb-3">UI customisation and behaviour tweaks.</p>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Timezone (PHP identifier)</label>
                                <input type="text" name="app_timezone" class="form-control" value="<?= h($cfg(['app', 'timezone'], 'Europe/Jersey')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Primary colour (hex)</label>
                                <input type="text" name="app_primary_color" class="form-control" value="<?= h($cfg(['app', 'primary_color'], '#660000')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Missed cutoff minutes</label>
                                <input type="number" name="app_missed_cutoff" class="form-control" min="0" value="<?= (int)$cfg(['app', 'missed_cutoff_minutes'], 60) ?>">
                                <div class="form-text">After this many minutes past start, mark reservation as missed.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Logo URL</label>
                                <input type="text" name="app_logo_url" class="form-control" value="<?= h($cfg(['app', 'logo_url'], '')) ?>">
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="app_debug" id="app_debug" <?= $cfg(['app', 'debug'], false) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="app_debug">Enable debug mode (more verbose errors)</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 d-flex justify-content-end">
                <button type="submit" name="action" value="save" class="btn btn-primary">Save settings</button>
            </div>
        </form>
    </div>
</div>
<?php reserveit_footer(); ?>
<script>
(function () {
    const form = document.getElementById('settings-form');
    if (!form) return;

    const clearStatus = (el) => {
        if (!el) return;
        el.textContent = '';
        el.classList.remove('text-success', 'text-danger');
        el.classList.add('text-muted');
    };

    const setStatus = (el, text, isError) => {
        if (!el) return;
        el.textContent = text;
        el.classList.remove('text-muted');
        el.classList.toggle('text-success', !isError);
        el.classList.toggle('text-danger', isError);
    };

    form.querySelectorAll('[data-test-action]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const action = btn.getAttribute('data-test-action');
            const targetId = btn.getAttribute('data-target');
            const target = targetId ? document.getElementById(targetId) : null;
            clearStatus(target);
            setStatus(target, 'Testing...', false);
            btn.disabled = true;

            const fd = new FormData(form);
            fd.set('action', action);
            fd.set('ajax', '1');

            const actionUrl = (form.getAttribute('action') || window.location.href).split('#')[0];

            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 8000);

            fetch(actionUrl, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal
            })
                .then(async (res) => {
                    clearTimeout(timeout);
                    if (!res.ok) {
                        const text = await res.text().catch(() => '');
                        throw new Error(text || 'Request failed');
                    }
                    try {
                        return await res.json();
                    } catch (_) {
                        const text = await res.text().catch(() => '');
                        throw new Error(text || 'Invalid response');
                    }
                })
                .then((data) => {
                    const errs = Array.isArray(data.errors) ? data.errors : [];
                    const msgs = Array.isArray(data.messages) ? data.messages : [];
                    if (errs.length) {
                        setStatus(target, errs.join(' | '), true);
                    } else if (msgs.length) {
                        setStatus(target, msgs.join(' | '), false);
                    } else {
                        setStatus(target, 'No response received.', true);
                    }
                })
                .catch((err) => {
                    clearTimeout(timeout);
                    if (err.name === 'AbortError') {
                        setStatus(target, 'Request timed out. Please check the host/URL.', true);
                    } else {
                        setStatus(target, err.message || 'Test failed.', true);
                    }
                })
                .finally(() => {
                    btn.disabled = false;
                });
        });
    });
})();
</script>
</body>
</html>
