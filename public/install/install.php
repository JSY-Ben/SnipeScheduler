<?php
/**
 * Web installer for SnipeScheduler.
 *
 * Builds config/config.php and (optionally) creates the database using schema.sql.
 * Use only during initial setup. If config.php already exists, you must confirm overwriting it.
 */

// Minimal bootstrapping (avoid loading config-dependent code)
define('APP_ROOT', dirname(__DIR__, 2));
define('CONFIG_PATH', APP_ROOT . '/config');

require_once APP_ROOT . '/src/config_writer.php';
require_once APP_ROOT . '/src/email.php';

$configPath  = CONFIG_PATH . '/config.php';
$examplePath = CONFIG_PATH . '/config.example.php';
$schemaPath  = __DIR__ . '/schema.sql';
$installedFlag = APP_ROOT . '/.installed';
$installedFlag = APP_ROOT . '/.installed';

function installer_load_array(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = require $path;
    return is_array($data) ? $data : [];
}

function installer_value(array $source, array $path, $fallback = '')
{
    $ref = $source;
    foreach ($path as $key) {
        if (!is_array($ref) || !array_key_exists($key, $ref)) {
            return $fallback;
        }
        $ref = $ref[$key];
    }
    return $ref === null ? $fallback : $ref;
}

function installer_h(string $val): string
{
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

$existingConfig  = installer_load_array($configPath);
$defaultConfig   = installer_load_array($examplePath);
$prefillConfig   = $existingConfig ?: $defaultConfig;
$configExists    = is_file($configPath);

$definedValues = [
    'SNIPEIT_API_PAGE_LIMIT'   => defined('SNIPEIT_API_PAGE_LIMIT') ? SNIPEIT_API_PAGE_LIMIT : 12,
    'CATALOGUE_ITEMS_PER_PAGE' => defined('CATALOGUE_ITEMS_PER_PAGE') ? CATALOGUE_ITEMS_PER_PAGE : 12,
];

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    || (isset($_POST['ajax']) && $_POST['ajax'] == '1');
$messages = [];
$errors   = [];
$installLocked = is_file($installedFlag);
$installCompleted = false;
$redirectTo = null;
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';

$requirements = [
    [
        'label'   => 'PHP version (>= 8.0)',
        'detail'  => 'Detected: ' . PHP_VERSION,
        'passing' => version_compare(PHP_VERSION, '8.0.0', '>='),
    ],
    [
        'label'   => 'Web server (Apache or Nginx)',
        'detail'  => $serverSoftware !== '' ? $serverSoftware : 'Unknown',
        'passing' => stripos($serverSoftware, 'apache') !== false || stripos($serverSoftware, 'nginx') !== false,
    ],
    [
        'label'   => 'PHP extension: pdo_mysql',
        'detail'  => extension_loaded('pdo_mysql') ? 'Loaded' : 'Missing',
        'passing' => extension_loaded('pdo_mysql'),
    ],
    [
        'label'   => 'PHP extension: curl',
        'detail'  => extension_loaded('curl') ? 'Loaded' : 'Missing',
        'passing' => extension_loaded('curl'),
    ],
    [
        'label'   => 'PHP extension: ldap',
        'detail'  => extension_loaded('ldap') ? 'Loaded' : 'Missing',
        'passing' => extension_loaded('ldap'),
    ],
    [
        'label'   => 'PHP extension: mbstring',
        'detail'  => extension_loaded('mbstring') ? 'Loaded' : 'Missing',
        'passing' => extension_loaded('mbstring'),
    ],
    [
        'label'   => 'PHP extension: openssl',
        'detail'  => extension_loaded('openssl') ? 'Loaded' : 'Missing',
        'passing' => extension_loaded('openssl'),
    ],
    [
        'label'   => 'PHP extension: json',
        'detail'  => extension_loaded('json') ? 'Loaded' : 'Missing',
        'passing' => extension_loaded('json'),
    ],
];

function installer_test_db(array $db): string
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

function installer_test_snipe(array $snipe): string
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

function installer_test_google(array $google, array $auth): string
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

function installer_test_microsoft(array $ms, array $auth): string
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

function installer_test_ldap(array $ldap): string
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installLocked) {
    $post = static function (string $key, $fallback = '') {
        return trim($_POST[$key] ?? $fallback);
    };

    $action = $_POST['action'] ?? 'save';

    if ($configExists && !isset($_POST['overwrite_ok']) && $action === 'save') {
        $errors[] = 'config.php already exists. Check "Overwrite existing config.php" to proceed.';
    } else {
        $dbHost    = $post('db_host', 'localhost');
        $dbPort    = (int)$post('db_port', '3306');
        $dbName    = $post('db_name', 'reserveit');
        $dbUser    = $post('db_username', '');
        $dbPassRaw = $_POST['db_password'] ?? '';
        $dbPass    = $dbPassRaw;
        $dbCharset = $post('db_charset', 'utf8mb4');

        $snipeUrl     = $post('snipe_base_url', '');
        $snipeTokenRaw = $_POST['snipe_api_token'] ?? '';
        $snipeToken    = $snipeTokenRaw;
        $snipeVerify   = isset($_POST['snipe_verify_ssl']);

        $ldapHost   = $post('ldap_host', 'ldaps://');
        $ldapBase   = $post('ldap_base_dn', '');
        $ldapBind   = $post('ldap_bind_dn', '');
        $ldapPassRaw = $_POST['ldap_bind_password'] ?? '';
        $ldapPass    = $ldapPassRaw;
        $ldapIgnore  = isset($_POST['ldap_ignore_cert']);
        $authLdapEnabled   = isset($_POST['auth_ldap_enabled']);
        $authGoogleEnabled = isset($_POST['auth_google_enabled']);
        $googleClientId    = $post('google_client_id', '');
        $googleClientSecret = $_POST['google_client_secret'] ?? '';
        $googleRedirectUri = $post('google_redirect_uri', '');
        $googleDomainsRaw  = $post('google_allowed_domains', '');
        $googleAdminRaw    = $post('google_admin_emails', '');
        $googleCheckoutRaw = $post('google_checkout_emails', '');
        $googleAllowedDomains = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $googleDomainsRaw))));
        $googleAdminEmails    = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $googleAdminRaw))));
        $googleCheckoutEmails = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $googleCheckoutRaw))));
        $msClientId    = $post('microsoft_client_id', '');
        $msClientSecret = $_POST['microsoft_client_secret'] ?? '';
        $msTenant       = $post('microsoft_tenant', '');
        $msRedirectUri  = $post('microsoft_redirect_uri', '');
        $msDomainsRaw   = $post('microsoft_allowed_domains', '');
        $msAdminRaw     = $post('microsoft_admin_emails', '');
        $msCheckoutRaw  = $post('microsoft_checkout_emails', '');
        $msAllowedDomains = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $msDomainsRaw))));
        $msAdminEmails    = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $msAdminRaw))));
        $msCheckoutEmails = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $msCheckoutRaw))));

        // Defaults for omitted settings
        $adminCns   = [];
        $checkoutCns = [];
        $timezone    = 'Europe/Jersey';
        $debug       = true;
        $logoUrl     = '';
        $primary     = '#660000';
        $missed      = 60;
        $apiCacheTtl = 60;
        $pageLimit   = $definedValues['SNIPEIT_API_PAGE_LIMIT'];
        $cataloguePP = $definedValues['CATALOGUE_ITEMS_PER_PAGE'];

        $newConfig = $defaultConfig;
        $newConfig['db_booking'] = [
            'host'     => $dbHost,
            'port'     => $dbPort,
            'dbname'   => $dbName,
            'username' => $dbUser,
            'password' => $dbPass,
            'charset'  => $dbCharset,
        ];
        $newConfig['snipeit'] = [
            'base_url'   => $snipeUrl,
            'api_token'  => $snipeToken,
            'verify_ssl' => $snipeVerify,
        ];
        $newConfig['ldap'] = [
            'host'          => $ldapHost,
            'base_dn'       => $ldapBase,
            'bind_dn'       => $ldapBind,
            'bind_password' => $ldapPass,
            'ignore_cert'   => $ldapIgnore,
        ];
        $newConfig['auth']['ldap_enabled']           = $authLdapEnabled;
        $newConfig['auth']['google_oauth_enabled']   = $authGoogleEnabled;
        $newConfig['auth']['microsoft_oauth_enabled'] = isset($_POST['auth_microsoft_enabled']);
        $newConfig['auth']['admin_group_cn']         = $adminCns;
        $newConfig['auth']['checkout_group_cn']      = $checkoutCns;
        $newConfig['auth']['google_admin_emails']    = $googleAdminEmails;
        $newConfig['auth']['google_checkout_emails'] = $googleCheckoutEmails;
        $newConfig['auth']['microsoft_admin_emails'] = $msAdminEmails;
        $newConfig['auth']['microsoft_checkout_emails'] = $msCheckoutEmails;
        $newConfig['google_oauth'] = [
            'client_id'       => $googleClientId,
            'client_secret'   => $googleClientSecret,
            'redirect_uri'    => $googleRedirectUri,
            'allowed_domains' => $googleAllowedDomains,
        ];
        $newConfig['microsoft_oauth'] = [
            'client_id'       => $msClientId,
            'client_secret'   => $msClientSecret,
            'tenant'          => $msTenant,
            'redirect_uri'    => $msRedirectUri,
            'allowed_domains' => $msAllowedDomains,
        ];
        $newConfig['app'] = [
            'timezone'              => $timezone,
            'debug'                 => $debug,
            'logo_url'              => $logoUrl,
            'primary_color'         => $primary,
            'missed_cutoff_minutes' => $missed,
            'api_cache_ttl_seconds' => $apiCacheTtl,
            'reservation_notice_minutes' => 0,
            'reservation_notice_bypass_checkout_staff' => false,
            'reservation_notice_bypass_admins' => false,
            'reservation_min_duration_minutes' => 0,
            'reservation_max_duration_minutes' => 0,
            'reservation_duration_bypass_checkout_staff' => false,
            'reservation_duration_bypass_admins' => false,
            'reservation_max_concurrent_reservations' => 0,
            'reservation_concurrent_bypass_checkout_staff' => false,
            'reservation_concurrent_bypass_admins' => false,
            'reservation_blackout_slots' => [],
            'reservation_blackout_bypass_checkout_staff' => false,
            'reservation_blackout_bypass_admins' => false,
        ];
        $newConfig['catalogue'] = [
            'allowed_categories' => [],
            'show_available_default_locations' => true,
        ];
        $newConfig['smtp'] = [
            'host'       => $post('smtp_host', ''),
            'port'       => (int)$post('smtp_port', 587),
            'username'   => $post('smtp_username', ''),
            'password'   => $_POST['smtp_password'] ?? '',
            'encryption' => $post('smtp_encryption', 'tls'),
            'auth_method'=> $post('smtp_auth_method', 'login'),
            'from_email' => $post('smtp_from_email', ''),
            'from_name'  => $post('smtp_from_name', 'SnipeScheduler'),
        ];

        if ($isAjax && $action !== 'save') {
            try {
                if ($action === 'test_db') {
                    $messages[] = installer_test_db($newConfig['db_booking']);
                } elseif ($action === 'test_api') {
                    $messages[] = installer_test_snipe($newConfig['snipeit']);
                } elseif ($action === 'test_microsoft') {
                    $messages[] = installer_test_microsoft($newConfig['microsoft_oauth'], $newConfig['auth']);
                } elseif ($action === 'test_google') {
                    $messages[] = installer_test_google($newConfig['google_oauth'], $newConfig['auth']);
                } elseif ($action === 'test_ldap') {
                    $messages[] = installer_test_ldap($newConfig['ldap']);
                } elseif ($action === 'test_smtp') {
                    $smtp = $newConfig['smtp'];
                    if (empty($smtp['host']) || empty($smtp['from_email'])) {
                        throw new Exception('SMTP host and from email are required.');
                    }
                    $targetEmail = $smtp['from_email'];
                    $targetName  = $smtp['from_name'] ?? $targetEmail;
                    $sent = layout_send_notification(
                        $targetEmail,
                        $targetName,
                        'SnipeScheduler SMTP test',
                        ['This is a test email from the installer SMTP settings.'],
                        ['smtp' => $smtp]
                    );
                    if ($sent) {
                        $messages[] = 'SMTP test email sent to ' . $targetEmail . '.';
                    } else {
                        throw new Exception('SMTP send failed (see logs).');
                    }
                } else {
                    $errors[] = 'Unknown test action.';
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }

            header('Content-Type: application/json');
            echo json_encode([
                'ok'       => empty($errors),
                'messages' => $messages,
                'errors'   => $errors,
            ]);
            exit;
        }

        if (!is_dir(CONFIG_PATH)) {
            @mkdir(CONFIG_PATH, 0755, true);
        }

        $content = layout_build_config_file($newConfig, [
            'SNIPEIT_API_PAGE_LIMIT'   => $pageLimit,
            'CATALOGUE_ITEMS_PER_PAGE' => $cataloguePP,
        ]);

        if (@file_put_contents($configPath, $content, LOCK_EX) === false) {
            $errors[] = 'Failed to write config.php. Check permissions on the config/ directory.';
        } else {
            $messages[] = 'Config file written to config/config.php.';
            $prefillConfig = $newConfig;
            $configExists  = true;
        }

        $setupDb = isset($_POST['setup_db']);
        if (!$errors && $setupDb) {
            $dsnBase = sprintf('mysql:host=%s;port=%d;charset=%s', $dbHost, $dbPort, $dbCharset);
            try {
                $pdo = new PDO($dsnBase, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                $dbNameEsc = str_replace('`', '``', $dbName);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameEsc}` CHARACTER SET {$dbCharset} COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbNameEsc}`");

                if (!is_file($schemaPath)) {
                    throw new RuntimeException("schema.sql not found at {$schemaPath}");
                }

                $schemaSql = file_get_contents($schemaPath);
                $pdo->exec($schemaSql);

                $messages[] = "Database '{$dbName}' is ready.";
            } catch (Throwable $e) {
                $errors[] = 'Database setup failed: ' . installer_h($e->getMessage());
            }
        }

        // Mark installation complete if everything succeeded.
        if (!$errors) {
            @file_put_contents($installedFlag, "Installed on " . date(DATE_ATOM) . "\n");
            $installLocked = true;
            $installCompleted = true;
            $redirectTo = '../index.php';
            $messages[] = 'Installation complete. Please delete public/install/install.php (or restrict access) now.';
            if (!headers_sent()) {
                header('Refresh: 3; url=' . $redirectTo);
            }
        }
    }
}

// Prefill values for the form
$pref = static function (array $path, $fallback = '') use ($prefillConfig) {
    return installer_value($prefillConfig, $path, $fallback);
};
$adminPref = $pref(['auth', 'admin_group_cn'], []);
if (!is_array($adminPref)) {
    $adminPref = [];
}
$adminText = implode("\n", $adminPref);
$checkoutPref = $pref(['auth', 'checkout_group_cn'], []);
if (!is_array($checkoutPref)) {
    $checkoutPref = [];
}
$checkoutText = implode("\n", $checkoutPref);
$googleAdminPref = $pref(['auth', 'google_admin_emails'], []);
if (!is_array($googleAdminPref)) {
    $googleAdminPref = [];
}
$googleAdminText = implode("\n", $googleAdminPref);
$googleCheckoutPref = $pref(['auth', 'google_checkout_emails'], []);
if (!is_array($googleCheckoutPref)) {
    $googleCheckoutPref = [];
}
$googleCheckoutText = implode("\n", $googleCheckoutPref);
$msAdminPref = $pref(['auth', 'microsoft_admin_emails'], []);
if (!is_array($msAdminPref)) {
    $msAdminPref = [];
}
$msAdminText = implode("\n", $msAdminPref);
$msCheckoutPref = $pref(['auth', 'microsoft_checkout_emails'], []);
if (!is_array($msCheckoutPref)) {
    $msCheckoutPref = [];
}
$msCheckoutText = implode("\n", $msCheckoutPref);
$googleDomainsPref = $pref(['google_oauth', 'allowed_domains'], []);
if (!is_array($googleDomainsPref)) {
    $googleDomainsPref = [];
}
$googleDomainsText = implode("\n", $googleDomainsPref);
$msDomainsPref = $pref(['microsoft_oauth', 'allowed_domains'], []);
if (!is_array($msDomainsPref)) {
    $msDomainsPref = [];
}
$msDomainsText = implode("\n", $msDomainsPref);

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

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SnipeScheduler – Web Installer</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        body { background: #f7f9fc; }
        .installer-page {
            max-width: 960px;
            margin: 0 auto;
        }
    </style>
</head>
<body class="p-4">
<div class="container installer-page">
    <div class="page-shell">
        <div class="page-header">
            <h1>SnipeScheduler Installer</h1>
            <div class="page-subtitle">
                Create config.php and initialise the database. For production security, remove or protect this file after setup.
            </div>
        </div>

        <?php if ($installLocked): ?>
            <div class="alert alert-info">
                Installation already completed. Remove the <code>.installed</code> file in the project root to rerun the installer.
            </div>
        <?php endif; ?>

        <?php if ($configExists && !$installLocked): ?>
            <div class="alert alert-warning">
                A config file already exists at <code><?= installer_h($configPath) ?></code>. To overwrite, tick the checkbox below.
            </div>
        <?php endif; ?>

        <?php if ($messages): ?>
            <div class="alert alert-success">
                <?= implode('<br>', array_map('installer_h', $messages)) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?= implode('<br>', $errors) ?>
            </div>
        <?php endif; ?>

        <?php if (!$installLocked): ?>
        <form method="post" action="install.php" class="row g-3" id="installer-form">
            <?php if ($configExists): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="overwrite_ok" id="overwrite_ok">
                        <label class="form-check-label" for="overwrite_ok">Overwrite existing config.php</label>
                    </div>
                </div>
            <?php endif; ?>

            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-body">
                        <h5 class="card-title mb-2">System requirements</h5>
                        <p class="text-muted small mb-3">The installer checks common requirements below. Please resolve any missing items before continuing.</p>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 40%;">Requirement</th>
                                        <th>Status</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($requirements as $req): ?>
                                        <tr>
                                            <td><?= installer_h($req['label']) ?></td>
                                            <td>
                                                <?php if ($req['passing']): ?>
                                                    <span class="badge bg-success">OK</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Missing</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= installer_h($req['detail']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Database</h5>
                        <p class="text-muted small mb-3">Booking app database connection (not the Snipe-IT DB). Installer will create the database and tables.</p>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Host</label>
                                <input type="text" name="db_host" class="form-control" value="<?= installer_h($pref(['db_booking', 'host'], 'localhost')) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Port</label>
                                <input type="number" name="db_port" class="form-control" value="<?= (int)$pref(['db_booking', 'port'], 3306) ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Database name</label>
                                <input type="text" name="db_name" class="form-control" value="<?= installer_h($pref(['db_booking', 'dbname'], 'reserveit')) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Username</label>
                                <input type="text" name="db_username" class="form-control" value="<?= installer_h($pref(['db_booking', 'username'], '')) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Password</label>
                                <input type="password" name="db_password" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Charset</label>
                                <input type="text" name="db_charset" class="form-control" value="<?= installer_h($pref(['db_booking', 'charset'], 'utf8mb4')) ?>">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="setup_db" name="setup_db" checked>
                                    <label class="form-check-label" for="setup_db">Create database and run schema.sql</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="db-test-result"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-test-action="test_db" data-target="db-test-result">Test database connection</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Snipe-IT API</h5>
                        <p class="text-muted small mb-3">Connection details for your Snipe-IT instance.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Base URL</label>
                                <input type="text" name="snipe_base_url" class="form-control" value="<?= installer_h($pref(['snipeit', 'base_url'], '')) ?>">
                                <div class="form-text">Example: https://snipeit.example.com</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">API token</label>
                                <input type="password" name="snipe_api_token" class="form-control">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="snipe_verify_ssl" id="snipe_verify_ssl" <?= $pref(['snipeit', 'verify_ssl'], false) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="snipe_verify_ssl">Verify SSL certificate</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="api-test-result"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-test-action="test_api" data-target="api-test-result">Test Snipe-IT API</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Authentication</h5>
                        <p class="text-muted small mb-3">Configure sign-in methods below. Toggle each method on/off and add its settings.</p>

                        <h6 class="mt-2 pb-1 border-bottom text-uppercase fw-bold border-3 border-primary border-start ps-2">LDAP / Active Directory</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="auth_ldap_enabled" id="auth_ldap_enabled" <?= $pref(['auth', 'ldap_enabled'], true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="auth_ldap_enabled">Enable LDAP sign-in</label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">LDAP host (e.g. ldaps://host)</label>
                                <input type="text" name="ldap_host" class="form-control" value="<?= installer_h($pref(['ldap', 'host'], 'ldaps://')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Base DN</label>
                                <input type="text" name="ldap_base_dn" class="form-control" value="<?= installer_h($pref(['ldap', 'base_dn'], '')) ?>" placeholder="dc=company,dc=local">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bind DN (service account)</label>
                                <input type="text" name="ldap_bind_dn" class="form-control" value="<?= installer_h($pref(['ldap', 'bind_dn'], '')) ?>" placeholder="CN=binduser,CN=Users,DC=company,DC=local">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bind password</label>
                                <input type="password" name="ldap_bind_password" class="form-control">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="ldap_ignore_cert" id="ldap_ignore_cert" <?= $pref(['ldap', 'ignore_cert'], true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="ldap_ignore_cert">Ignore SSL certificate errors</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">LDAP/AD Administrators Group(s)</label>
                                <textarea name="admin_group_cn" rows="3" class="form-control" placeholder="ICT Admins&#10;Another Admin Group"><?= installer_h($adminText) ?></textarea>
                                <div class="form-text">Comma or newline separated group names with full admin access.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">LDAP/AD Checkout Staff Group(s)</label>
                                <textarea name="checkout_group_cn" rows="3" class="form-control" placeholder="Checkout Staff&#10;Equipment Desk"><?= installer_h($checkoutText) ?></textarea>
                                <div class="form-text">Comma or newline separated group names for staff who can use all features except Admin.</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="ldap-test-result"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-test-action="test_ldap" data-target="ldap-test-result">Test LDAP connection</button>
                        </div>

                        <hr class="my-4">

                        <h6 class="mt-4 pb-1 border-bottom text-uppercase fw-bold border-3 border-success border-start ps-2">Google OAuth</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="auth_google_enabled" id="auth_google_enabled" <?= $pref(['auth', 'google_oauth_enabled'], false) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="auth_google_enabled">Enable Google OAuth sign-in</label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label">Google Client ID</label>
                                <input type="text" name="google_client_id" class="form-control" value="<?= installer_h($pref(['google_oauth', 'client_id'], '')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Google Client Secret</label>
                                <input type="password" name="google_client_secret" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Redirect URI (optional)</label>
                                <input type="text" name="google_redirect_uri" class="form-control" value="<?= installer_h($pref(['google_oauth', 'redirect_uri'], '')) ?>" placeholder="<?= installer_h($googleRedirectDefault) ?>">
                                <div class="form-text">
                                    Leave blank to auto-detect. Typical authorised redirect URI: <code><?= installer_h($googleRedirectDefault) ?></code>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Allowed Google domains (optional)</label>
                                <textarea name="google_allowed_domains" rows="3" class="form-control" placeholder="example.com&#10;sub.example.com"><?= installer_h($googleDomainsText) ?></textarea>
                                <div class="form-text">Comma or newline separated. Leave empty to allow any Google account.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Google administrator emails (optional)</label>
                                <textarea name="google_admin_emails" rows="3" class="form-control" placeholder="admin1@example.com&#10;admin2@example.com"><?= installer_h($googleAdminText) ?></textarea>
                                <div class="form-text">Comma or newline separated addresses with full admin access.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Google checkout staff emails (optional)</label>
                                <textarea name="google_checkout_emails" rows="3" class="form-control" placeholder="staff1@example.com&#10;staff2@example.com"><?= installer_h($googleCheckoutText) ?></textarea>
                                <div class="form-text">Comma or newline separated addresses that can access staff features (excluding Admin).</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="google-test-result"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-test-action="test_google" data-target="google-test-result">Test Google OAuth</button>
                        </div>

                        <hr class="my-4">

                        <h6 class="mt-2 pb-1 border-bottom text-uppercase fw-bold border-3 border-info border-start ps-2">Microsoft Entra / 365</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="auth_microsoft_enabled" id="auth_microsoft_enabled" <?= $pref(['auth', 'microsoft_oauth_enabled'], false) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="auth_microsoft_enabled">Enable Microsoft sign-in</label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label">Client ID (Application ID)</label>
                                <input type="text" name="microsoft_client_id" class="form-control" value="<?= installer_h($pref(['microsoft_oauth', 'client_id'], '')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Client Secret</label>
                                <input type="password" name="microsoft_client_secret" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tenant ID (GUID)</label>
                                <input type="text" name="microsoft_tenant" class="form-control" value="<?= installer_h($pref(['microsoft_oauth', 'tenant'], '')) ?>" placeholder="00000000-0000-0000-0000-000000000000">
                                <div class="form-text">Required. Use the Directory (tenant) ID from Entra.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Redirect URI (optional)</label>
                                <input type="text" name="microsoft_redirect_uri" class="form-control" value="<?= installer_h($pref(['microsoft_oauth', 'redirect_uri'], '')) ?>" placeholder="<?= installer_h($msRedirectDefault) ?>">
                                <div class="form-text">
                                    Leave blank to auto-detect. Typical authorised redirect URI: <code><?= installer_h($msRedirectDefault) ?></code>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Allowed domains (optional)</label>
                                <textarea name="microsoft_allowed_domains" rows="3" class="form-control" placeholder="example.com&#10;sub.example.com"><?= installer_h($msDomainsText) ?></textarea>
                                <div class="form-text">Comma or newline separated. Leave empty to allow any Microsoft account.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Microsoft administrator emails (optional)</label>
                                <textarea name="microsoft_admin_emails" rows="3" class="form-control" placeholder="admin1@example.com&#10;admin2@example.com"><?= installer_h($msAdminText) ?></textarea>
                                <div class="form-text">Comma or newline separated addresses with full admin access.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Microsoft checkout staff emails (optional)</label>
                                <textarea name="microsoft_checkout_emails" rows="3" class="form-control" placeholder="staff1@example.com&#10;staff2@example.com"><?= installer_h($msCheckoutText) ?></textarea>
                                <div class="form-text">Comma or newline separated addresses that can access staff features (excluding Admin).</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="ms-test-result"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-test-action="test_microsoft" data-target="ms-test-result">Test Microsoft OAuth</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">SMTP (email)</h5>
                        <p class="text-muted small mb-3">Used for notification emails during and after setup.</p>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">SMTP host</label>
                                <input type="text" name="smtp_host" class="form-control" value="<?= installer_h($pref(['smtp', 'host'], '')) ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Port</label>
                                <input type="number" name="smtp_port" class="form-control" value="<?= (int)$pref(['smtp', 'port'], 587) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Encryption</label>
                                <select name="smtp_encryption" class="form-select">
                                    <?php
                                    $enc = strtolower($pref(['smtp', 'encryption'], 'tls'));
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
                                    $auth = strtolower($pref(['smtp', 'auth_method'], 'login'));
                                    foreach (['login', 'plain', 'none'] as $opt) {
                                        $sel = $auth === $opt ? 'selected' : '';
                                        echo "<option value=\"{$opt}\" {$sel}>" . strtoupper($opt) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Username</label>
                                <input type="text" name="smtp_username" class="form-control" value="<?= installer_h($pref(['smtp', 'username'], '')) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Password</label>
                                <input type="password" name="smtp_password" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">From email</label>
                                <input type="email" name="smtp_from_email" class="form-control" value="<?= installer_h($pref(['smtp', 'from_email'], '')) ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">From name</label>
                                <input type="text" name="smtp_from_name" class="form-control" value="<?= installer_h($pref(['smtp', 'from_name'], 'SnipeScheduler')) ?>">
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="smtp-test-result"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-test-action="test_smtp" data-target="smtp-test-result">Test SMTP</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 d-flex justify-content-end">
                <button type="submit" name="action" value="save" class="btn btn-primary">Generate config &amp; install</button>
            </div>
        </form>
        <?php else: ?>
        <div class="alert alert-secondary mt-3 mb-0">
            Installer is locked. Remove the <code>.installed</code> file to run again.
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
<?php if (!$installLocked): ?>
<script>
(function () {
    const form = document.getElementById('installer-form');
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
<?php endif; ?>
</html>
