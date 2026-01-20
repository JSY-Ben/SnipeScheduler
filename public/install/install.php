<?php
/**
 * Web installer for KitGrab.
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

        $adminEmail = strtolower(trim($_POST['admin_email'] ?? ''));
        $adminFirstName = trim($_POST['admin_first_name'] ?? '');
        $adminLastName = trim($_POST['admin_last_name'] ?? '');
        $adminUsername = trim($_POST['admin_username'] ?? '');
        $adminPassword = $_POST['admin_password'] ?? '';

        if ($action === 'save') {
            if ($adminEmail === '') {
                $errors[] = 'Admin email is required.';
            } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Admin email is not a valid email address.';
            }
            if ($adminPassword === '') {
                $errors[] = 'Admin password is required.';
            }
        }

        // Defaults for omitted settings
        $timezone    = 'Europe/Jersey';
        $debug       = true;
        $logoUrl     = '';
        $primary     = '#660000';
        $missed      = 60;
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
        $newConfig['ldap'] = [
            'host'          => 'ldaps://',
            'base_dn'       => '',
            'bind_dn'       => '',
            'bind_password' => '',
            'ignore_cert'   => true,
        ];
        $newConfig['auth']['ldap_enabled']             = false;
        $newConfig['auth']['google_oauth_enabled']     = false;
        $newConfig['auth']['microsoft_oauth_enabled']  = false;
        $newConfig['auth']['admin_group_cn']           = [];
        $newConfig['auth']['checkout_group_cn']        = [];
        $newConfig['auth']['google_admin_emails']      = [];
        $newConfig['auth']['google_checkout_emails']   = [];
        $newConfig['auth']['microsoft_admin_emails']   = [];
        $newConfig['auth']['microsoft_checkout_emails'] = [];
        $newConfig['google_oauth'] = [
            'client_id'       => '',
            'client_secret'   => '',
            'redirect_uri'    => '',
            'allowed_domains' => [],
        ];
        $newConfig['microsoft_oauth'] = [
            'client_id'       => '',
            'client_secret'   => '',
            'tenant'          => '',
            'redirect_uri'    => '',
            'allowed_domains' => [],
        ];
        $newConfig['app'] = [
            'name'                  => 'KitGrab',
            'timezone'              => $timezone,
            'debug'                 => $debug,
            'logo_url'              => $logoUrl,
            'primary_color'         => $primary,
            'missed_cutoff_minutes' => $missed,
        ];
        $newConfig['catalogue'] = [
            'allowed_categories' => [],
        ];
        $newConfig['smtp'] = [
            'host'       => $post('smtp_host', ''),
            'port'       => (int)$post('smtp_port', 587),
            'username'   => $post('smtp_username', ''),
            'password'   => $_POST['smtp_password'] ?? '',
            'encryption' => $post('smtp_encryption', 'tls'),
            'auth_method'=> $post('smtp_auth_method', 'login'),
            'from_email' => $post('smtp_from_email', ''),
            'from_name'  => $post('smtp_from_name', 'KitGrab'),
        ];

        if ($isAjax && $action !== 'save') {
            try {
                if ($action === 'test_db') {
                    $messages[] = installer_test_db($newConfig['db_booking']);
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
                        'KitGrab SMTP test',
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

        if (!$errors) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $dbHost, $dbPort, $dbName, $dbCharset);
            try {
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $adminFirstNameValue = $adminFirstName !== '' ? $adminFirstName : $adminEmail;
                $adminLastNameValue = $adminLastName !== '' ? $adminLastName : '';
                $adminUsernameValue = $adminUsername !== '' ? $adminUsername : null;
                $adminUserId = sprintf('%u', crc32($adminEmail));
                $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    INSERT INTO users (user_id, first_name, last_name, email, username, is_admin, is_staff, password_hash, auth_source, created_at)
                    VALUES (:user_id, :first_name, :last_name, :email, :username, 1, 1, :password_hash, 'local', NOW())
                    ON DUPLICATE KEY UPDATE
                        first_name = VALUES(first_name),
                        last_name = VALUES(last_name),
                        username = VALUES(username),
                        is_admin = 1,
                        is_staff = 1,
                        password_hash = VALUES(password_hash)
                ");
                $stmt->execute([
                    ':user_id' => $adminUserId,
                    ':first_name' => $adminFirstNameValue,
                    ':last_name' => $adminLastNameValue,
                    ':email' => $adminEmail,
                    ':username' => $adminUsernameValue,
                    ':password_hash' => $passwordHash,
                ]);

                $messages[] = 'Local admin account created in the users table.';
            } catch (Throwable $e) {
                $errors[] = 'Local admin creation failed: ' . installer_h($e->getMessage());
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
$adminEmailPref = $_POST['admin_email'] ?? '';
$adminFirstNamePref = $_POST['admin_first_name'] ?? '';
$adminLastNamePref = $_POST['admin_last_name'] ?? '';
$adminUsernamePref = $_POST['admin_username'] ?? '';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KitGrab – Web Installer</title>
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
            <h1>KitGrab Installer</h1>
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
                        <p class="text-muted small mb-3">Booking app database connection. Installer will create the database and tables.</p>
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
                        <h5 class="card-title mb-1">Inventory</h5>
                        <p class="text-muted small mb-3">Asset models and assets live in this app database. Add them after install in your admin workflows.</p>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Admin account</h5>
                        <p class="text-muted small mb-3">Create the first local administrator account. You can add LDAP/Google/Microsoft sign-in later in Settings.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Admin email</label>
                                <input type="email" name="admin_email" class="form-control" value="<?= installer_h($adminEmailPref) ?>" placeholder="admin@example.com" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">First name (optional)</label>
                                <input type="text" name="admin_first_name" class="form-control" value="<?= installer_h($adminFirstNamePref) ?>" placeholder="Admin">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last name (optional)</label>
                                <input type="text" name="admin_last_name" class="form-control" value="<?= installer_h($adminLastNamePref) ?>" placeholder="User">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Username (optional)</label>
                                <input type="text" name="admin_username" class="form-control" value="<?= installer_h($adminUsernamePref) ?>" placeholder="admin">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password</label>
                                <input type="password" name="admin_password" class="form-control" required>
                                <div class="form-text">Use a strong password. Complexity is recommended but not enforced.</div>
                            </div>
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
                                <input type="text" name="smtp_from_name" class="form-control" value="<?= installer_h($pref(['smtp', 'from_name'], 'KitGrab')) ?>">
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
