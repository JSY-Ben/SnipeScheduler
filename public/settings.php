<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/config_writer.php';
require_once SRC_PATH . '/inventory_client.php';
require_once SRC_PATH . '/email.php';

$active  = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
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
    'CATALOGUE_ITEMS_PER_PAGE'  => defined('CATALOGUE_ITEMS_PER_PAGE') ? CATALOGUE_ITEMS_PER_PAGE : 12,
];

function layout_test_db_connection(array $db): string
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

function layout_test_google_oauth(array $google, array $auth): string
{
    if (!function_exists('curl_init')) {
        throw new Exception('PHP cURL extension is not installed.');
    }

    if (empty($auth['google_oauth_enabled'])) {
        throw new Exception('Google OAuth is disabled.');
    }

    $clientId     = trim($google['client_id'] ?? '');
    $clientSecret = trim($google['client_secret'] ?? '');
    $redirectUri  = trim($google['redirect_uri'] ?? '');

    if ($clientId === '' || $clientSecret === '') {
        throw new Exception('Client ID and Client Secret are required.');
    }

    if ($redirectUri !== '' && !filter_var($redirectUri, FILTER_VALIDATE_URL)) {
        throw new Exception('Redirect URI is not a valid URL.');
    }

    $ch = curl_init('https://accounts.google.com/.well-known/openid-configuration');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Network check failed: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 400) {
        throw new Exception('Google OAuth endpoints unavailable (HTTP ' . $code . ').');
    }

    return 'Google OAuth settings look OK and endpoints are reachable.';
}

function layout_test_microsoft_oauth(array $ms, array $auth): string
{
    if (!function_exists('curl_init')) {
        throw new Exception('PHP cURL extension is not installed.');
    }

    if (empty($auth['microsoft_oauth_enabled'])) {
        throw new Exception('Microsoft OAuth is disabled.');
    }

    $clientId     = trim($ms['client_id'] ?? '');
    $clientSecret = trim($ms['client_secret'] ?? '');
    $tenant       = trim($ms['tenant'] ?? '');
    $redirectUri  = trim($ms['redirect_uri'] ?? '');

    if ($clientId === '' || $clientSecret === '') {
        throw new Exception('Client ID and Client Secret are required.');
    }

    if ($tenant === '') {
        throw new Exception('Tenant ID is required.');
    }

    if ($redirectUri !== '' && !filter_var($redirectUri, FILTER_VALIDATE_URL)) {
        throw new Exception('Redirect URI is not a valid URL.');
    }

    $wellKnown = 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/v2.0/.well-known/openid-configuration';
    $ch = curl_init($wellKnown);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Network check failed: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 400) {
        throw new Exception('Microsoft OAuth endpoints unavailable (HTTP ' . $code . ').');
    }

    return 'Microsoft OAuth settings look OK and endpoints are reachable.';
}

function layout_test_ldap(array $ldap): string
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

    $cataloguePP = max(1, (int)$post('catalogue_items_per_page', $definedValues['CATALOGUE_ITEMS_PER_PAGE']));

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

        $auth = $config['auth'] ?? [];
        $auth['ldap_enabled']        = isset($_POST['auth_ldap_enabled']);
        $auth['google_oauth_enabled'] = isset($_POST['auth_google_enabled']);
        $auth['microsoft_oauth_enabled'] = isset($_POST['auth_microsoft_enabled']);
        $adminCnsRaw     = $post('admin_group_cn', '');
        $checkoutCnsRaw  = $post('checkout_group_cn', '');
        $adminGroupCns    = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $adminCnsRaw))));
        $checkoutGroupCns = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $checkoutCnsRaw))));
        $auth['admin_group_cn'] = $adminGroupCns;
        $auth['checkout_group_cn'] = $checkoutGroupCns;

        $googleAdminRaw = $post('google_admin_emails', '');
        $googleCheckoutRaw = $post('google_checkout_emails', '');
        $googleAdminList = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $googleAdminRaw))));
        $googleCheckoutList = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $googleCheckoutRaw))));
        $auth['google_admin_emails'] = $googleAdminList;
        $auth['google_checkout_emails'] = $googleCheckoutList;

        $msAdminRaw = $post('microsoft_admin_emails', '');
        $msCheckoutRaw = $post('microsoft_checkout_emails', '');
        $msAdminList = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $msAdminRaw))));
        $msCheckoutList = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $msCheckoutRaw))));
        $auth['microsoft_admin_emails'] = $msAdminList;
        $auth['microsoft_checkout_emails'] = $msCheckoutList;

        $google = $config['google_oauth'] ?? [];
        $google['client_id']     = $post('google_client_id', $google['client_id'] ?? '');
        $googleSecretInput       = $_POST['google_client_secret'] ?? '';
        if ($useRawSecrets) {
            $google['client_secret'] = $googleSecretInput;
        } else {
            $google['client_secret'] = $googleSecretInput === '' ? ($loadedConfig['google_oauth']['client_secret'] ?? '') : $googleSecretInput;
        }
        $google['redirect_uri']  = $post('google_redirect_uri', $google['redirect_uri'] ?? '');
        $domainsRaw = $post('google_allowed_domains', '');
        $google['allowed_domains'] = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $domainsRaw))));

        $ms = $config['microsoft_oauth'] ?? [];
        $ms['client_id']     = $post('microsoft_client_id', $ms['client_id'] ?? '');
        $msSecretInput       = $_POST['microsoft_client_secret'] ?? '';
        if ($useRawSecrets) {
            $ms['client_secret'] = $msSecretInput;
        } else {
            $ms['client_secret'] = $msSecretInput === '' ? ($loadedConfig['microsoft_oauth']['client_secret'] ?? '') : $msSecretInput;
        }
        $ms['tenant']        = $post('microsoft_tenant', $ms['tenant'] ?? '');
        $ms['redirect_uri']  = $post('microsoft_redirect_uri', $ms['redirect_uri'] ?? '');
        $msDomainsRaw = $post('microsoft_allowed_domains', '');
        $ms['allowed_domains'] = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $msDomainsRaw))));

        $app = $config['app'] ?? [];
        $app['timezone']              = $post('app_timezone', $app['timezone'] ?? 'Europe/Jersey');
        $app['debug']                 = isset($_POST['app_debug']);
        $app['logo_url']              = $post('app_logo_url', $app['logo_url'] ?? '');
        $app['primary_color']         = $post('app_primary_color', $app['primary_color'] ?? '#660000');
        $app['missed_cutoff_minutes'] = max(0, (int)$post('app_missed_cutoff', $app['missed_cutoff_minutes'] ?? 60));
        $app['overdue_staff_email']   = $post('app_overdue_staff_email', $app['overdue_staff_email'] ?? '');
        $app['overdue_staff_name']    = $post('app_overdue_staff_name', $app['overdue_staff_name'] ?? '');
        $app['block_catalogue_overdue'] = isset($_POST['app_block_catalogue_overdue']);

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
        $smtp['from_name']  = $post('smtp_from_name', $smtp['from_name'] ?? ($config['app']['name'] ?? 'KitGrab'));

        $newConfig = $config;
        $newConfig['db_booking'] = $db;
        $newConfig['ldap']       = $ldap;
        $newConfig['auth']       = $auth;
        $newConfig['google_oauth'] = $google;
        $newConfig['microsoft_oauth'] = $ms;
        $newConfig['app']        = $app;
        $newConfig['catalogue']  = $catalogue;
        $newConfig['smtp']       = $smtp;

        // Keep posted values in the form
        $config        = $newConfig;
        $definedValues = [
            'CATALOGUE_ITEMS_PER_PAGE' => $cataloguePP,
        ];

        if ($action === 'test_db') {
            try {
                $messages[] = layout_test_db_connection($db);
            } catch (Throwable $e) {
                $errors[] = 'Database test failed: ' . $e->getMessage();
            }
        } elseif ($action === 'test_microsoft') {
            try {
                $messages[] = layout_test_microsoft_oauth($ms, $auth);
            } catch (Throwable $e) {
                $errors[] = 'Microsoft OAuth test failed: ' . $e->getMessage();
            }
        } elseif ($action === 'test_google') {
            try {
                $messages[] = layout_test_google_oauth($google, $auth);
            } catch (Throwable $e) {
                $errors[] = 'Google OAuth test failed: ' . $e->getMessage();
            }
        } elseif ($action === 'test_ldap') {
            try {
                $messages[] = layout_test_ldap($ldap);
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
                $sent = layout_send_notification(
                    $targetEmail,
                    $targetName,
                    'KitGrab SMTP test',
                    ['This is a test email from KitGrab SMTP settings.'],
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
            $content = layout_build_config_file($newConfig, [
                'CATALOGUE_ITEMS_PER_PAGE' => $cataloguePP,
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

function layout_textarea_value(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$adminGroupList = $cfg(['auth', 'admin_group_cn'], []);
if (!is_array($adminGroupList)) {
    $adminGroupList = [];
}
$adminGroupText = implode("\n", $adminGroupList);

$checkoutGroupList = $cfg(['auth', 'checkout_group_cn'], []);
if (!is_array($checkoutGroupList)) {
    $checkoutGroupList = [];
}
$checkoutGroupText = implode("\n", $checkoutGroupList);

$googleAdminList = $cfg(['auth', 'google_admin_emails'], []);
if (!is_array($googleAdminList)) {
    $googleAdminList = [];
}
$googleAdminText = implode("\n", $googleAdminList);

$googleCheckoutList = $cfg(['auth', 'google_checkout_emails'], []);
if (!is_array($googleCheckoutList)) {
    $googleCheckoutList = [];
}
$googleCheckoutText = implode("\n", $googleCheckoutList);

$msAdminList = $cfg(['auth', 'microsoft_admin_emails'], []);
if (!is_array($msAdminList)) {
    $msAdminList = [];
}
$msAdminText = implode("\n", $msAdminList);

$msCheckoutList = $cfg(['auth', 'microsoft_checkout_emails'], []);
if (!is_array($msCheckoutList)) {
    $msCheckoutList = [];
}
$msCheckoutText = implode("\n", $msCheckoutList);

$googleAllowedDomains = $cfg(['google_oauth', 'allowed_domains'], []);
if (!is_array($googleAllowedDomains)) {
    $googleAllowedDomains = [];
}
$googleAllowedDomainsText = implode("\n", $googleAllowedDomains);

$msAllowedDomains = $cfg(['microsoft_oauth', 'allowed_domains'], []);
if (!is_array($msAllowedDomains)) {
    $msAllowedDomains = [];
}
$msAllowedDomainsText = implode("\n", $msAllowedDomains);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? '';
$dir    = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
$dir    = ($dir === '' || $dir === '.') ? '' : $dir;
$googleRedirectDefault = $host
    ? $scheme . '://' . $host . $dir . '/login_process.php?provider=google'
    : 'https://your-app-domain/login_process.php?provider=google';
$msRedirectDefault = $host
    ? $scheme . '://' . $host . $dir . '/login_process.php?provider=microsoft'
    : 'https://your-app-domain/login_process.php?provider=microsoft';

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
    <title>Admin – KitGrab</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles($config) ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag($config) ?>
        <div class="page-header">
            <h1>Admin</h1>
            <div class="page-subtitle">
                Administrator-only configuration for database, LDAP, and app options. Leave secret fields blank to keep existing values.
            </div>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

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

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link" href="activity_log.php">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" href="settings.php">Settings</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="users.php">Users</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="inventory_admin.php">Inventory</a>
            </li>
        </ul>

        <form method="post" action="<?= h($active) ?>" class="row g-3 settings-form" id="settings-form">
            <div class="col-12">
                <div class="card" id="admin-settings">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Database</h5>
                        <p class="text-muted small mb-3">Connection for the booking app tables. Password is optional to update; leave blank to keep the current value.</p>
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
                <div class="card" id="admin-access">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Authentication</h5>
                        <p class="text-muted small mb-3">Configure sign-in methods below. Toggle each method on/off and add its settings.</p>

                        <h6 class="mt-2 pb-1 border-bottom text-uppercase fw-bold border-3 border-primary border-start ps-2">LDAP / Active Directory</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="auth_ldap_enabled" id="auth_ldap_enabled" <?= $cfg(['auth', 'ldap_enabled'], true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="auth_ldap_enabled">Enable LDAP sign-in</label>
                                </div>
                            </div>
                        </div>
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
                            <div class="col-12">
                                <label class="form-label">LDAP/AD Administrators Group(s)</label>
                                <textarea name="admin_group_cn" rows="3" class="form-control" placeholder="ICT Admins&#10;Another Admin Group"><?= layout_textarea_value($adminGroupText) ?></textarea>
                                <div class="form-text">Comma or newline separated group names with full admin access.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">LDAP/AD Checkout Staff Group(s)</label>
                                <textarea name="checkout_group_cn" rows="3" class="form-control" placeholder="Checkout Staff&#10;Equipment Desk"><?= layout_textarea_value($checkoutGroupText) ?></textarea>
                                <div class="form-text">Comma or newline separated group names for staff who can use all features except Admin.</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="ldap-test-result"></div>
                            <button type="button" name="action" value="test_ldap" class="btn btn-outline-primary btn-sm" data-test-action="test_ldap" data-target="ldap-test-result">Test LDAP connection</button>
                        </div>

                        <hr class="my-4">

                        <h6 class="mt-4 pb-1 border-bottom text-uppercase fw-bold border-3 border-success border-start ps-2">Google OAuth</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="auth_google_enabled" id="auth_google_enabled" <?= $cfg(['auth', 'google_oauth_enabled'], false) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="auth_google_enabled">Enable Google OAuth sign-in</label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label">Google Client ID</label>
                                <input type="text" name="google_client_id" class="form-control" value="<?= h($cfg(['google_oauth', 'client_id'], '')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Google Client Secret</label>
                                <input type="password" name="google_client_secret" class="form-control" placeholder="Leave blank to keep">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Redirect URI (optional)</label>
                                <input type="text" name="google_redirect_uri" class="form-control" value="<?= h($cfg(['google_oauth', 'redirect_uri'], '')) ?>" placeholder="<?= h($googleRedirectDefault) ?>">
                                <div class="form-text">
                                    Leave blank to auto-detect. Typical authorised redirect URI: <code><?= h($googleRedirectDefault) ?></code>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Allowed Google domains (optional)</label>
                                <textarea name="google_allowed_domains" rows="3" class="form-control" placeholder="example.com&#10;sub.example.com"><?= layout_textarea_value($googleAllowedDomainsText) ?></textarea>
                                <div class="form-text">Comma or newline separated. Leave empty to allow any Google account.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Google administrator emails (optional)</label>
                                <textarea name="google_admin_emails" rows="3" class="form-control" placeholder="admin1@example.com&#10;admin2@example.com"><?= layout_textarea_value($googleAdminText) ?></textarea>
                                <div class="form-text">Comma or newline separated addresses with full admin access.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Google checkout staff emails (optional)</label>
                                <textarea name="google_checkout_emails" rows="3" class="form-control" placeholder="staff1@example.com&#10;staff2@example.com"><?= layout_textarea_value($googleCheckoutText) ?></textarea>
                                <div class="form-text">Comma or newline separated addresses that can access staff features (excluding Admin).</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="google-test-result"></div>
                            <button type="button" name="action" value="test_google" class="btn btn-outline-primary btn-sm" data-test-action="test_google" data-target="google-test-result">Test Google OAuth</button>
                        </div>

                        <hr class="my-4">

                        <h6 class="mt-2 pb-1 border-bottom text-uppercase fw-bold border-3 border-info border-start ps-2">Microsoft Entra / 365</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="auth_microsoft_enabled" id="auth_microsoft_enabled" <?= $cfg(['auth', 'microsoft_oauth_enabled'], false) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="auth_microsoft_enabled">Enable Microsoft sign-in</label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label">Client ID (Application ID)</label>
                                <input type="text" name="microsoft_client_id" class="form-control" value="<?= h($cfg(['microsoft_oauth', 'client_id'], '')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Client Secret</label>
                                <input type="password" name="microsoft_client_secret" class="form-control" placeholder="Leave blank to keep">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tenant ID (GUID)</label>
                                <input type="text" name="microsoft_tenant" class="form-control" value="<?= h($cfg(['microsoft_oauth', 'tenant'], '')) ?>" placeholder="00000000-0000-0000-0000-000000000000">
                                <div class="form-text">Required. Use the Directory (tenant) ID from Entra.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Redirect URI (optional)</label>
                                <input type="text" name="microsoft_redirect_uri" class="form-control" value="<?= h($cfg(['microsoft_oauth', 'redirect_uri'], '')) ?>" placeholder="<?= h($msRedirectDefault) ?>">
                                <div class="form-text">
                                    Leave blank to auto-detect. Typical authorised redirect URI: <code><?= h($msRedirectDefault) ?></code>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Allowed domains (optional)</label>
                                <textarea name="microsoft_allowed_domains" rows="3" class="form-control" placeholder="example.com&#10;sub.example.com"><?= layout_textarea_value($msAllowedDomainsText) ?></textarea>
                                <div class="form-text">Comma or newline separated. Leave empty to allow any Microsoft account.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Microsoft administrator emails (optional)</label>
                                <textarea name="microsoft_admin_emails" rows="3" class="form-control" placeholder="admin1@example.com&#10;admin2@example.com"><?= layout_textarea_value($msAdminText) ?></textarea>
                                <div class="form-text">Comma or newline separated addresses with full admin access.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Microsoft checkout staff emails (optional)</label>
                                <textarea name="microsoft_checkout_emails" rows="3" class="form-control" placeholder="staff1@example.com&#10;staff2@example.com"><?= layout_textarea_value($msCheckoutText) ?></textarea>
                                <div class="form-text">Comma or newline separated addresses that can access staff features (excluding Admin).</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="ms-test-result"></div>
                            <button type="button" name="action" value="test_microsoft" class="btn btn-outline-primary btn-sm" data-test-action="test_microsoft" data-target="ms-test-result">Test Microsoft OAuth</button>
                        </div>
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
                                <input type="text" name="smtp_from_name" class="form-control" value="<?= h($cfg(['smtp', 'from_name'], $cfg(['app', 'name'], 'KitGrab'))) ?>">
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
                        <p class="text-muted small mb-3">Control how many items appear per page in the catalogue.</p>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Items per page</label>
                                <input type="number" name="catalogue_items_per_page" min="1" class="form-control" value="<?= (int)$definedValues['CATALOGUE_ITEMS_PER_PAGE'] ?>">
                                <div class="form-text">Adjust to show more or fewer items on each catalogue page.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Catalogue categories</h5>
                        <p class="text-muted small mb-3">Choose which inventory categories appear in the catalogue filter. Unchecked categories are hidden entirely from the catalogue. Leave everything unticked to show all categories.</p>
                        <?php if ($categoryFetchError): ?>
                            <div class="alert alert-warning small mb-3">
                                Could not load categories: <?= h($categoryFetchError) ?>
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
                                <label class="form-label">Overdue Asset Staff Reminder Email Address</label>
                                <textarea name="app_overdue_staff_email" class="form-control" rows="2"><?= h($cfg(['app', 'overdue_staff_email'], '')) ?></textarea>
                                <div class="form-text">Multiple emails allowed (comma or new line). Used by `scripts/email_overdue_staff.php` (cron recommended). Each run sends a list of overdue assets to every address.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Overdue Asset Staff Reminder Email Name</label>
                                <textarea name="app_overdue_staff_name" class="form-control" rows="2"><?= h($cfg(['app', 'overdue_staff_name'], '')) ?></textarea>
                                <div class="form-text">Optional. Provide one name per email in the same order (comma or new line). If blank, the email address is used.</div>
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
                        <div class="row g-3 mt-2">
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="app_block_catalogue_overdue"
                                           id="app_block_catalogue_overdue"
                                        <?= $cfg(['app', 'block_catalogue_overdue'], true) ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="app_block_catalogue_overdue">
                                        Block catalogue for users with overdue checkouts
                                    </label>
                                </div>
                                <div class="form-text mt-1">
                                    When enabled, users with overdue assets cannot access the catalogue until items are returned.
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
<?php layout_footer(); ?>
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
